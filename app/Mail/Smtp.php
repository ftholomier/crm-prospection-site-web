<?php
declare(strict_types=1);

namespace App\Mail;

use App\Config;

/**
 * Client SMTP écrit sur les sockets natifs de PHP : ni Composer, ni PHPMailer.
 * Gère le SMTP simple, le SSL implicite et STARTTLS, avec authentification
 * LOGIN ou PLAIN. Chaque échange est journalisé pour le diagnostic.
 */
final class Smtp
{
    private $socket = null;
    private array $log = [];
    private array $capabilities = [];

    public function __construct(
        private string $host,
        private int $port = 587,
        private string $security = 'tls',
        private string $username = '',
        private string $password = '',
        private int $timeout = 20,
        private bool $verifyPeer = true
    ) {
    }

    /** Instance construite depuis les réglages enregistrés. */
    public static function fromConfig(): self
    {
        return new self(
            (string) Config::get('smtp.host', ''),
            (int) Config::get('smtp.port', 587),
            (string) Config::get('smtp.security', 'tls'),
            (string) Config::get('smtp.user', ''),
            (string) Config::get('smtp.pass', ''),
            20,
            (bool) Config::get('smtp.verify_peer', true)
        );
    }

    public function log(): array
    {
        return $this->log;
    }

    /**
     * Envoie un message déjà construit.
     * @param string[] $recipients
     * @return array{ok:bool,error:string,log:string[]}
     */
    public function send(string $from, array $recipients, string $rawMessage): array
    {
        try {
            $this->connect();
            $this->command('MAIL FROM:<' . $from . '>', [250]);
            foreach ($recipients as $recipient) {
                $this->command('RCPT TO:<' . $recipient . '>', [250, 251]);
            }
            $this->command('DATA', [354]);
            $this->write(self::prepareBody($rawMessage) . "\r\n.");
            $this->expect([250]);
            $this->command('QUIT', [221, 250], true);
            $this->close();
            return ['ok' => true, 'error' => '', 'log' => $this->log];
        } catch (\RuntimeException $exception) {
            $this->close();
            return ['ok' => false, 'error' => $exception->getMessage(), 'log' => $this->log];
        }
    }

    /**
     * Normalise les fins de ligne en CRLF et échappe le point en début de
     * ligne, qui signalerait autrement la fin du message.
     *
     * La normalisation se fait en deux temps : tout ramener à \n, puis passer
     * en CRLF. Une substitution directe vers CRLF réécrirait les séquences
     * déjà correctes et démultiplierait les sauts de ligne.
     */
    private static function prepareBody(string $raw): string
    {
        $normalized = str_replace("\r\n", "\n", $raw);
        $normalized = str_replace("\r", "\n", $normalized);
        $normalized = str_replace("\n", "\r\n", $normalized);
        return preg_replace('/^\./m', '..', $normalized) ?? $normalized;
    }

    /** Ouvre la session et s'authentifie, sans rien envoyer : bouton « Tester ». */
    public function test(): array
    {
        try {
            $this->connect();
            $this->command('QUIT', [221, 250], true);
            $this->close();
            return ['ok' => true, 'error' => '', 'log' => $this->log];
        } catch (\RuntimeException $exception) {
            $this->close();
            return ['ok' => false, 'error' => $exception->getMessage(), 'log' => $this->log];
        }
    }

    private function connect(): void
    {
        if ($this->host === '') {
            throw new \RuntimeException('Aucun serveur SMTP configuré.');
        }

        $security = strtolower($this->security);
        $transport = $security === 'ssl' ? 'ssl://' : '';
        $context = stream_context_create(['ssl' => [
            'verify_peer' => $this->verifyPeer,
            'verify_peer_name' => $this->verifyPeer,
            'allow_self_signed' => !$this->verifyPeer,
            'SNI_enabled' => true,
            'peer_name' => $this->host,
        ]]);

        $errorNumber = 0;
        $errorMessage = '';
        $this->socket = @stream_socket_client(
            $transport . $this->host . ':' . $this->port,
            $errorNumber,
            $errorMessage,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($this->socket === false) {
            $this->socket = null;
            throw new \RuntimeException('Connexion à ' . $this->host . ':' . $this->port . ' impossible — ' . ($errorMessage ?: 'hôte injoignable'));
        }
        stream_set_timeout($this->socket, $this->timeout);
        $this->expect([220]);

        $hostname = $this->clientHostname();
        $this->ehlo($hostname);

        if ($security === 'tls') {
            if (!$this->supports('STARTTLS')) {
                throw new \RuntimeException('Le serveur n\'annonce pas STARTTLS. Choisissez « SSL » ou « aucun » selon votre hébergeur.');
            }
            $this->command('STARTTLS', [220]);
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            if (!@stream_socket_enable_crypto($this->socket, true, $crypto)) {
                throw new \RuntimeException('Passage en TLS refusé par le serveur.');
            }
            $this->log[] = '*** connexion chiffrée (STARTTLS) ***';
            $this->ehlo($hostname);
        }

        if ($this->username !== '') {
            $this->authenticate();
        }
    }

    private function ehlo(string $hostname): void
    {
        $response = $this->command('EHLO ' . $hostname, [250], true);
        if ($response === null) {
            $this->command('HELO ' . $hostname, [250]);
            $this->capabilities = [];
            return;
        }
        $this->capabilities = [];
        foreach (explode("\n", $response) as $line) {
            $line = trim(substr($line, 4));
            if ($line !== '') {
                $this->capabilities[] = strtoupper($line);
            }
        }
    }

    private function supports(string $keyword): bool
    {
        foreach ($this->capabilities as $capability) {
            if (str_starts_with($capability, strtoupper($keyword))) {
                return true;
            }
        }
        return false;
    }

    private function authenticate(): void
    {
        $mechanisms = '';
        foreach ($this->capabilities as $capability) {
            if (str_starts_with($capability, 'AUTH')) {
                $mechanisms = $capability;
                break;
            }
        }

        if ($mechanisms === '' || str_contains($mechanisms, 'LOGIN')) {
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($this->username), [334], false, true);
            $this->command(base64_encode($this->password), [235], false, true);
            return;
        }
        if (str_contains($mechanisms, 'PLAIN')) {
            $token = base64_encode("\0" . $this->username . "\0" . $this->password);
            $this->command('AUTH PLAIN ' . $token, [235], false, true);
            return;
        }
        throw new \RuntimeException('Aucune méthode d\'authentification supportée (' . $mechanisms . ').');
    }

    /**
     * Envoie une commande et vérifie le code de réponse.
     * @param int[] $expected
     */
    private function command(string $command, array $expected, bool $optional = false, bool $secret = false): ?string
    {
        $this->write($command, $secret);
        try {
            return $this->expect($expected);
        } catch (\RuntimeException $exception) {
            if ($optional) {
                return null;
            }
            throw $exception;
        }
    }

    private function write(string $data, bool $secret = false): void
    {
        if ($this->socket === null) {
            throw new \RuntimeException('Connexion SMTP fermée.');
        }
        $this->log[] = '> ' . ($secret ? '[masqué]' : $this->firstLine($data));
        if (@fwrite($this->socket, $data . "\r\n") === false) {
            throw new \RuntimeException('Écriture impossible sur la connexion SMTP.');
        }
    }

    /** @param int[] $expected */
    private function expect(array $expected): string
    {
        if ($this->socket === null) {
            throw new \RuntimeException('Connexion SMTP fermée.');
        }
        $response = '';
        while (true) {
            $line = @fgets($this->socket, 1024);
            if ($line === false) {
                $meta = stream_get_meta_data($this->socket);
                throw new \RuntimeException(!empty($meta['timed_out'])
                    ? 'Le serveur SMTP ne répond plus (délai dépassé).'
                    : 'Réponse SMTP interrompue.');
            }
            $response .= $line;
            // Une réponse multiligne place un tiret en 4e position ; la dernière ligne, une espace.
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $this->log[] = '< ' . trim($response);
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new \RuntimeException('Le serveur a répondu : ' . trim($response));
        }
        return $response;
    }

    private function close(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    /** Nom annoncé dans EHLO : un FQDN est attendu par la plupart des serveurs. */
    private function clientHostname(): string
    {
        $host = (string) Config::get('smtp.from_email', '');
        if (str_contains($host, '@')) {
            return substr($host, strpos($host, '@') + 1);
        }
        $server = $_SERVER['SERVER_NAME'] ?? gethostname();
        return is_string($server) && str_contains($server, '.') ? $server : 'localhost';
    }

    private function firstLine(string $data): string
    {
        $line = strtok($data, "\r\n");
        return $line === false ? '' : substr($line, 0, 120);
    }
}
