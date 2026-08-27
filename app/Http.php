<?php
declare(strict_types=1);

namespace App;

/**
 * Client HTTP minimal basé sur cURL, avec repli sur les flux PHP si cURL
 * n'est pas compilé. Utilisé pour le scraping et les API tierces.
 */
final class Http
{
    private const MAX_BYTES = 3145728; // 3 Mo : suffisant pour une page, borne la mémoire

    /**
     * Identités de navigateur utilisées pour la lecture des sites.
     *
     * Un agent qui s'annonce comme robot est refusé d'emblée par une bonne
     * partie des pare-feux applicatifs, y compris pour une simple page
     * d'accueil publique. On se présente donc comme un navigateur courant, et
     * la seconde identité sert de nouvelle tentative quand la première est
     * rejetée.
     */
    public const AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.3 Safari/605.1.15',
    ];

    /** Agent configuré, ou identité de navigateur par défaut. */
    public static function agent(int $variant = 0): string
    {
        $configured = trim((string) Config::get('scraper.user_agent', ''));
        if ($configured !== '' && $variant === 0) {
            return $configured;
        }
        return self::AGENTS[$variant] ?? self::AGENTS[0];
    }

    /** En-têtes d'un navigateur réel : leur absence suffit à déclencher un refus. */
    private static function browserHeaders(string $url): array
    {
        return [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
            'Upgrade-Insecure-Requests: 1',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: none',
            'Sec-Fetch-User: ?1',
            'Cache-Control: max-age=0',
        ];
    }

    /**
     * Récupère une URL.
     * @return array{ok:bool,status:int,body:string,url:string,error:string,headers:array,elapsed:float,size:int}
     */
    public static function get(string $url, int $timeout = 20, int $agentVariant = 0): array
    {
        $result = [
            'ok' => false, 'status' => 0, 'body' => '', 'url' => $url,
            'error' => '', 'headers' => [], 'elapsed' => 0.0, 'size' => 0,
        ];
        $started = microtime(true);

        if (!function_exists('curl_init')) {
            return self::getWithStreams($url, $timeout, $result, $started, $agentVariant);
        }

        $handle = curl_init();
        $headers = [];
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => self::agent($agentVariant),
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Certains pare-feux posent un cookie puis redirigent : sans moteur
            // de cookies, la redirection boucle et finit en refus.
            CURLOPT_COOKIEFILE => '',
            CURLOPT_HTTPHEADER => self::browserHeaders($url),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static function ($ch, $dlTotal, $dlNow): int {
                return $dlNow > self::MAX_BYTES ? 1 : 0;
            },
        ]);

        $body = curl_exec($handle);
        $result['status'] = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $result['url'] = (string) (curl_getinfo($handle, CURLINFO_EFFECTIVE_URL) ?: $url);
        if ($body === false) {
            $result['error'] = curl_error($handle) ?: 'Requête impossible';
        } else {
            $result['body'] = self::toUtf8((string) $body, $headers['content-type'] ?? '');
            $result['ok'] = $result['status'] >= 200 && $result['status'] < 400;
        }
        curl_close($handle);

        $result['headers'] = $headers;
        $result['elapsed'] = round(microtime(true) - $started, 3);
        $result['size'] = strlen($result['body']);
        return $result;
    }

    /**
     * Récupère seulement le début d'un fichier distant.
     *
     * Sert à connaître le type et les dimensions réelles d'une image sans la
     * télécharger entièrement : l'en-tête d'un JPEG, d'un PNG ou d'un WebP
     * tient dans les premiers kilo-octets. Les serveurs qui ignorent l'en-tête
     * Range renvoient tout : la coupure côté client limite quand même le coût.
     *
     * @return array{ok:bool,status:int,body:string,headers:array,partiel:bool,error:string}
     */
    public static function peek(string $url, int $bytes = 65536, int $timeout = 12): array
    {
        $result = ['ok' => false, 'status' => 0, 'body' => '', 'headers' => [], 'partiel' => false, 'error' => ''];
        if (!function_exists('curl_init')) {
            $full = self::get($url, $timeout);
            $result['ok'] = $full['ok'];
            $result['status'] = $full['status'];
            $result['body'] = substr($full['body'], 0, $bytes);
            $result['headers'] = $full['headers'];
            $result['error'] = $full['error'];
            return $result;
        }

        $headers = [];
        $buffer = '';
        $handle = curl_init();
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT => self::agent(0),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_COOKIEFILE => '',
            CURLOPT_RANGE => '0-' . max(1024, $bytes - 1),
            CURLOPT_HTTPHEADER => self::browserHeaders($url),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffer, $bytes): int {
                $buffer .= $chunk;
                // Rendre moins que reçu interrompt volontairement le transfert.
                return strlen($buffer) >= $bytes ? -1 : strlen($chunk);
            },
        ]);
        curl_exec($handle);
        $erreur = curl_errno($handle);
        $result['status'] = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        // L'arrêt volontaire remonte comme une erreur d'écriture : ce n'en est pas une.
        $coupe = in_array($erreur, [CURLE_WRITE_ERROR, 23], true);
        if ($erreur !== 0 && !$coupe) {
            $result['error'] = 'Téléchargement interrompu (' . $erreur . ')';
        }
        $result['headers'] = $headers;
        $result['body'] = substr($buffer, 0, $bytes);
        $result['partiel'] = $coupe || $result['status'] === 206;
        $result['ok'] = $result['body'] !== '' && ($result['status'] >= 200 && $result['status'] < 400);
        return $result;
    }

    /** Requête JSON (POST ou GET) vers une API tierce. */
    public static function json(string $url, ?array $payload = null, array $headers = [], int $timeout = 30): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'Extension cURL indisponible'];
        }
        $handle = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => self::agent(),
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
        ];
        if ($payload !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }
        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        $data = is_string($body) ? json_decode($body, true) : null;
        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'data' => is_array($data) ? $data : [],
            'raw' => is_string($body) ? $body : '',
            'error' => $error,
        ];
    }

    /** Convertit le corps en UTF-8 en s'appuyant sur l'en-tête puis sur le HTML. */
    private static function toUtf8(string $body, string $contentType): string
    {
        $charset = '';
        if (preg_match('/charset=["\']?([\w\-]+)/i', $contentType, $m)) {
            $charset = strtoupper($m[1]);
        } elseif (preg_match('/<meta[^>]+charset=["\']?([\w\-]+)/i', substr($body, 0, 4096), $m)) {
            $charset = strtoupper($m[1]);
        }
        if ($charset !== '' && $charset !== 'UTF-8' && $charset !== 'UTF8') {
            $converted = @mb_convert_encoding($body, 'UTF-8', $charset);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }
        return mb_check_encoding($body, 'UTF-8') ? $body : (string) mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1');
    }

    /** Repli sans cURL, pour les hébergements qui ne l'exposent pas. */
    private static function getWithStreams(string $url, int $timeout, array $result, float $started, int $agentVariant = 0): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'follow_location' => 1,
                'max_redirects' => 5,
                'header' => 'User-Agent: ' . self::agent($agentVariant) . "\r\n"
                    . implode("\r\n", self::browserHeaders($url)) . "\r\n",
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $context, 0, self::MAX_BYTES);
        $headers = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) {
                $result['status'] = (int) $m[1];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }
        if ($body === false) {
            $result['error'] = 'Requête impossible (flux PHP)';
        } else {
            $result['body'] = self::toUtf8($body, $headers['content-type'] ?? '');
            $result['ok'] = $result['status'] >= 200 && $result['status'] < 400;
        }
        $result['headers'] = $headers;
        $result['elapsed'] = round(microtime(true) - $started, 3);
        $result['size'] = strlen($result['body']);
        return $result;
    }
}
