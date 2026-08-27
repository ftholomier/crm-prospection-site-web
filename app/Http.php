<?php
declare(strict_types=1);

namespace App;

/**
 * Client HTTP minimal basé sur cURL, avec repli sur les flux PHP si cURL
 * n'est pas compilé. Utilisé pour le scraping et les API tierces.
 */
final class Http
{
    private const UA = 'Mozilla/5.0 (compatible; ProspectStudio/1.0; +https://example.com/bot)';
    private const MAX_BYTES = 3145728; // 3 Mo : suffisant pour une page, borne la mémoire

    /**
     * Récupère une URL.
     * @return array{ok:bool,status:int,body:string,url:string,error:string,headers:array,elapsed:float,size:int}
     */
    public static function get(string $url, int $timeout = 20): array
    {
        $result = [
            'ok' => false, 'status' => 0, 'body' => '', 'url' => $url,
            'error' => '', 'headers' => [], 'elapsed' => 0.0, 'size' => 0,
        ];
        $started = microtime(true);

        if (!function_exists('curl_init')) {
            return self::getWithStreams($url, $timeout, $result, $started);
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
            CURLOPT_USERAGENT => self::UA,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,*/*;q=0.8', 'Accept-Language: fr-FR,fr;q=0.9'],
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
            CURLOPT_USERAGENT => self::UA,
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
    private static function getWithStreams(string $url, int $timeout, array $result, float $started): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'follow_location' => 1,
                'max_redirects' => 5,
                'header' => "User-Agent: " . self::UA . "\r\nAccept-Language: fr-FR,fr;q=0.9\r\n",
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
