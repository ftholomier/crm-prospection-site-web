<?php
declare(strict_types=1);

namespace App;

/**
 * Client Messages API en HTTP brut (contrainte du projet : PHP natif, sans
 * Composer, donc sans SDK Anthropic).
 *
 * Deux modes : une requête classique pour les appels courts et structurés,
 * et une requête en streaming pour la génération des pages — indispensable sur
 * un hébergement mutualisé, où une réponse longue non streamée déclencherait
 * un timeout côté serveur ou proxy.
 */
final class Claude
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const VERSION = '2023-06-01';

    public static function isConfigured(): bool
    {
        return trim((string) Config::get('claude.api_key', '')) !== '';
    }

    private static function headers(): array
    {
        return [
            'Content-Type: application/json',
            'x-api-key: ' . trim((string) Config::get('claude.api_key', '')),
            'anthropic-version: ' . self::VERSION,
        ];
    }

    /** Corps de requête commun aux deux modes. */
    private static function payload(array $options): array
    {
        $payload = [
            'model' => (string) Config::get('claude.model', 'claude-opus-5'),
            'max_tokens' => (int) ($options['max_tokens'] ?? Config::get('claude.max_tokens', 24000)),
            'messages' => $options['messages'],
            'thinking' => ['type' => 'adaptive'],
            'output_config' => ['effort' => (string) Config::get('claude.effort', 'high')],
        ];
        if (!empty($options['system'])) {
            $payload['system'] = $options['system'];
        }
        if (!empty($options['schema'])) {
            $payload['output_config']['format'] = [
                'type' => 'json_schema',
                'schema' => $options['schema'],
            ];
        }
        return $payload;
    }

    /**
     * Appel non streamé, pour les réponses courtes (analyse, brief, textes).
     * @return array{ok:bool,error:string,text:string,json:?array,usage:array}
     */
    public static function message(array $options): array
    {
        if (!self::isConfigured()) {
            return self::failure('Aucune clé API Claude n\'est renseignée dans les Réglages.');
        }
        if (!function_exists('curl_init')) {
            return self::failure('L\'extension cURL de PHP est requise pour appeler l\'API.');
        }

        $handle = curl_init();
        curl_setopt_array($handle, [
            CURLOPT_URL => self::ENDPOINT,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => self::headers(),
            CURLOPT_POSTFIELDS => json_encode(self::payload($options), JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => (int) ($options['timeout'] ?? Config::get('claude.timeout', 600)),
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            return self::failure('Connexion à l\'API impossible : ' . $curlError);
        }
        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            return self::failure('Réponse illisible de l\'API (HTTP ' . $status . ').');
        }
        if ($status < 200 || $status >= 300) {
            return self::failure(self::describeError($status, $data));
        }
        if (($data['stop_reason'] ?? '') === 'refusal') {
            return self::failure('La requête a été déclinée par le modèle. Reformulez le prompt de design.');
        }

        $text = self::extractText($data);
        return [
            'ok' => true,
            'error' => '',
            'text' => $text,
            'json' => self::decodeJson($text),
            'usage' => $data['usage'] ?? [],
            'stop_reason' => $data['stop_reason'] ?? '',
        ];
    }

    /**
     * Appel streamé. Le callback reçoit chaque fragment de texte au fil de l'eau,
     * ce qui permet d'afficher la progression et de tenir la connexion ouverte.
     *
     * @param callable(string $chunk, array $meta):void|null $onDelta
     * @return array{ok:bool,error:string,text:string,json:?array,usage:array}
     */
    public static function stream(array $options, ?callable $onDelta = null): array
    {
        if (!self::isConfigured()) {
            return self::failure('Aucune clé API Claude n\'est renseignée dans les Réglages.');
        }
        if (!function_exists('curl_init')) {
            return self::failure('L\'extension cURL de PHP est requise pour appeler l\'API.');
        }

        $payload = self::payload($options) + [];
        $payload['stream'] = true;

        $text = '';
        $usage = [];
        $stopReason = '';
        $apiError = '';
        $buffer = '';

        $handle = curl_init();
        curl_setopt_array($handle, [
            CURLOPT_URL => self::ENDPOINT,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => array_merge(self::headers(), ['Accept: text/event-stream']),
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => (int) ($options['timeout'] ?? Config::get('claude.timeout', 600)),
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (
                &$buffer, &$text, &$usage, &$stopReason, &$apiError, $onDelta
            ): int {
                $buffer .= $chunk;
                // Les événements SSE sont séparés par une ligne vide ; on ne traite
                // que les blocs complets et on conserve le reliquat pour la suite.
                while (($break = strpos($buffer, "\n\n")) !== false) {
                    $block = substr($buffer, 0, $break);
                    $buffer = substr($buffer, $break + 2);
                    foreach (explode("\n", $block) as $line) {
                        if (!str_starts_with($line, 'data:')) {
                            continue;
                        }
                        $json = trim(substr($line, 5));
                        if ($json === '' || $json === '[DONE]') {
                            continue;
                        }
                        $event = json_decode($json, true);
                        if (!is_array($event)) {
                            continue;
                        }
                        switch ($event['type'] ?? '') {
                            case 'content_block_delta':
                                $delta = $event['delta'] ?? [];
                                if (($delta['type'] ?? '') === 'text_delta') {
                                    $piece = (string) ($delta['text'] ?? '');
                                    $text .= $piece;
                                    if ($onDelta !== null) {
                                        $onDelta($piece, ['length' => strlen($text)]);
                                    }
                                }
                                break;
                            case 'message_delta':
                                $usage = array_merge($usage, $event['usage'] ?? []);
                                $stopReason = (string) ($event['delta']['stop_reason'] ?? $stopReason);
                                break;
                            case 'message_start':
                                $usage = array_merge($usage, $event['message']['usage'] ?? []);
                                break;
                            case 'error':
                                $apiError = (string) ($event['error']['message'] ?? 'Erreur de streaming');
                                break;
                        }
                    }
                }
                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);

        if ($apiError !== '') {
            return self::failure($apiError);
        }
        if ($ok === false) {
            return self::failure('Streaming interrompu : ' . ($curlError ?: 'connexion perdue'));
        }
        if ($status < 200 || $status >= 300) {
            $data = json_decode($text !== '' ? $text : '{}', true);
            return self::failure(self::describeError($status, is_array($data) ? $data : []));
        }
        if ($stopReason === 'refusal') {
            return self::failure('La requête a été déclinée par le modèle. Reformulez le prompt de design.');
        }
        if ($text === '') {
            return self::failure('Le modèle n\'a renvoyé aucun contenu.');
        }

        return [
            'ok' => true,
            'error' => '',
            'text' => $text,
            'json' => self::decodeJson($text),
            'usage' => $usage,
            'stop_reason' => $stopReason,
        ];
    }

    /** Concatène les blocs texte de la réponse. */
    private static function extractText(array $data): string
    {
        $parts = [];
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $parts[] = (string) ($block['text'] ?? '');
            }
        }
        return implode('', $parts);
    }

    /** Décode un JSON éventuellement entouré de texte ou de balises Markdown. */
    public static function decodeJson(string $text): ?array
    {
        $trimmed = trim($text);
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        if (preg_match('/```(?:json)?\s*(.+?)```/s', $trimmed, $m)) {
            $decoded = json_decode(trim($m[1]), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        $start = strpos($trimmed, '{');
        $end = strrpos($trimmed, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($trimmed, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }

    /** Message d'erreur lisible, traduit pour les cas les plus fréquents. */
    private static function describeError(int $status, array $data): string
    {
        $message = (string) ($data['error']['message'] ?? '');
        return match ($status) {
            401 => 'Clé API refusée (401). Vérifiez la clé dans les Réglages.',
            400 => 'Requête refusée par l\'API (400) : ' . ($message ?: 'paramètre invalide'),
            403 => 'Accès interdit (403) : ' . ($message ?: 'la clé n\'a pas les droits nécessaires'),
            404 => 'Modèle introuvable (404) : vérifiez l\'identifiant du modèle dans les Réglages.',
            429 => 'Limite de débit atteinte (429). Patientez puis relancez la génération.',
            500, 502, 503, 529 => 'L\'API est momentanément indisponible (' . $status . '). Relancez dans quelques instants.',
            default => 'Erreur API ' . $status . ($message !== '' ? ' : ' . $message : ''),
        };
    }

    private static function failure(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'text' => '', 'json' => null, 'usage' => [], 'stop_reason' => ''];
    }

    /** Vérifie la clé par un appel minimal, pour le bouton « Tester » des Réglages. */
    public static function test(): array
    {
        $result = self::message([
            'messages' => [['role' => 'user', 'content' => 'Réponds exactement : OK']],
            'max_tokens' => 1000,
        ]);
        return ['ok' => $result['ok'], 'error' => $result['error'], 'text' => trim($result['text'])];
    }
}
