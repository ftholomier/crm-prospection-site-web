<?php
declare(strict_types=1);

namespace App\Mail;

/**
 * Construction du message MIME : multipart/alternative (texte + HTML), en-têtes
 * encodés selon la RFC 2047 et en-têtes de désinscription conformes, sans quoi
 * un email de prospection à froid finit systématiquement en spam.
 */
final class Message
{
    private array $headers = [];

    public function __construct(
        private string $fromEmail,
        private string $fromName,
        private string $toEmail,
        private string $toName,
        private string $subject,
        private string $html,
        private string $text
    ) {
    }

    public function header(string $name, string $value): self
    {
        if (trim($value) !== '') {
            $this->headers[$name] = $value;
        }
        return $this;
    }

    /** Identifiant de message, réutilisé pour rattacher les réponses. */
    public function messageId(string $domain): string
    {
        $id = '<' . bin2hex(random_bytes(12)) . '@' . $domain . '>';
        $this->headers['Message-ID'] = $id;
        return $id;
    }

    public function build(): string
    {
        $boundary = 'bnd_' . bin2hex(random_bytes(12));

        $headers = array_merge([
            'Date' => date('r'),
            'From' => self::address($this->fromEmail, $this->fromName),
            'To' => self::address($this->toEmail, $this->toName),
            'Subject' => self::encodeHeader($this->subject),
            'MIME-Version' => '1.0',
            'Content-Type' => 'multipart/alternative; boundary="' . $boundary . '"',
        ], $this->headers);

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . str_replace(["\r", "\n"], '', $value);
        }

        $body = [
            '',
            'Cet email est au format MIME multipart.',
            '',
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            trim(chunk_split(base64_encode($this->text), 76, "\r\n")),
            '',
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            trim(chunk_split(base64_encode($this->html), 76, "\r\n")),
            '',
            '--' . $boundary . '--',
            '',
        ];

        return implode("\r\n", array_merge($lines, $body));
    }

    /** « Nom » <email>, avec encodage du nom si nécessaire. */
    public static function address(string $email, string $name = ''): string
    {
        $name = trim($name);
        if ($name === '') {
            return $email;
        }
        return self::encodeHeader($name) . ' <' . $email . '>';
    }

    /** Encodage RFC 2047 pour les en-têtes contenant des caractères non ASCII. */
    public static function encodeHeader(string $value): string
    {
        $value = trim(str_replace(["\r", "\n"], ' ', $value));
        if (preg_match('/^[\x20-\x7E]*$/', $value)) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /** Conversion HTML → texte brut lisible, pour la partie alternative. */
    public static function htmlToText(string $html): string
    {
        $text = preg_replace('~<(script|style)[^>]*>.*?</\1>~is', '', $html) ?? $html;
        $text = preg_replace('~<br\s*/?>~i', "\n", $text) ?? $text;
        $text = preg_replace('~</(p|div|tr|h[1-6]|li)>~i', "\n\n", $text) ?? $text;
        $text = preg_replace('~<li[^>]*>~i', '- ', $text) ?? $text;
        // Les liens sont explicités : un texte brut sans URL perd tout intérêt.
        $text = preg_replace_callback(
            '~<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~is',
            static function (array $m): string {
                $label = trim(strip_tags($m[2]));
                return $label === '' ? $m[1] : $label . ' : ' . $m[1];
            },
            $text
        ) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        return trim($text);
    }
}
