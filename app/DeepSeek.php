<?php
declare(strict_types=1);

namespace App;

/**
 * Client DeepSeek, second fournisseur possible pour la génération.
 *
 * L'API suit la convention OpenAI : un seul point d'entrée /chat/completions,
 * les messages dans un tableau plat, le prompt système sous forme de message de
 * rôle « system ». Les réponses sont ramenées ici à la même forme que celles du
 * client Claude, pour que le reste de l'application ignore lequel des deux
 * fournisseurs a répondu.
 *
 * Deux différences comptent et ne se rattrapent pas :
 *  - Il n'existe pas d'outil serveur de lecture web. La lecture d'un site que
 *    notre serveur ne peut pas atteindre reste donc l'affaire de Claude.
 *  - Le format de sortie ne s'impose que par « json_object », sans schéma. Le
 *    schéma est donc décrit dans le prompt, et la réponse validée à l'arrivée.
 */
final class DeepSeek
{
    private const BASE_URL = 'https://api.deepseek.com';

    /**
     * Adresse de base, modifiable dans la configuration : plusieurs
     * hébergements exposent une API compatible derrière leur propre domaine, et
     * un mandataire d'entreprise se règle ici plutôt que dans le code.
     */
    private static function baseUrl(): string
    {
        $base = rtrim(trim((string) Config::get('deepseek.base_url', '')), '/');
        return $base !== '' ? $base : self::BASE_URL;
    }

    public static function isConfigured(): bool
    {
        return trim((string) Config::get('deepseek.api_key', '')) !== '';
    }

    /** Modèle par défaut, et cible de reprise des anciens noms. */
    public const DEFAUT = 'deepseek-v4-flash';

    /**
     * Noms retirés le 24 juillet 2026, et ce vers quoi ils pointaient.
     *
     * Les deux se rabattaient sur v4-flash — « reasoner » n'était que son mode
     * réflexion, pas le modèle Pro. Rediriger vers Pro triplerait la facture
     * pour rien.
     */
    private const RETIRES = [
        'deepseek-chat' => self::DEFAUT,
        'deepseek-reasoner' => self::DEFAUT,
    ];

    public static function model(): string
    {
        $model = trim((string) Config::get('deepseek.model', ''));
        if ($model === '') {
            return self::DEFAUT;
        }
        // Un nom retiré n'est plus routé : l'API répond par une erreur. On le
        // remplace plutôt que de laisser la génération échouer.
        return self::RETIRES[$model] ?? $model;
    }

    /** Le modèle configuré porte-t-il un nom retiré ? */
    public static function isRetired(string $model): bool
    {
        return isset(self::RETIRES[$model]);
    }

    /** @return array{ok:bool,error:string,text:string,json:?array,usage:array,stop_reason:string} */
    public static function message(array $options): array
    {
        $reponse = self::call(self::payload($options), false, null, $options);
        if (!$reponse['ok']) {
            return $reponse;
        }
        return self::finish($reponse['text'], $reponse['usage'], $reponse['stop_reason']);
    }

    /**
     * Appel streamé, même contrat que côté Claude : le callback reçoit chaque
     * fragment, ce qui tient la connexion ouverte et alimente la progression.
     *
     * @param callable(string $chunk, array $meta):void|null $onDelta
     */
    public static function stream(array $options, ?callable $onDelta = null): array
    {
        $payload = self::payload($options);
        $payload['stream'] = true;
        // Sans cela l'usage n'est pas renvoyé en fin de flux, et le suivi des
        // coûts resterait vide sur toutes les pages générées en streaming.
        $payload['stream_options'] = ['include_usage' => true];

        $reponse = self::call($payload, true, $onDelta, $options);
        if (!$reponse['ok']) {
            return $reponse;
        }
        return self::finish($reponse['text'], $reponse['usage'], $reponse['stop_reason']);
    }

    /** Contrôle rapide de la clé, pour le bouton d'essai des Réglages. */
    public static function test(): array
    {
        $result = self::message([
            'system' => 'Tu réponds en un mot.',
            'messages' => [['role' => 'user', 'content' => 'Réponds exactement : OK']],
            'max_tokens' => 16,
        ]);
        return [
            'ok' => $result['ok'],
            'error' => $result['error'],
            'model' => self::model(),
            'answer' => trim($result['text']),
        ];
    }

    private const CACHE_TTL = 86400;

    public static function cachePath(): string
    {
        return DATA_DIR . '/deepseek-models.json';
    }

    /**
     * Modèles proposés par le compte.
     *
     * La liste est mise en cache : l'interroger à chaque affichage des Réglages
     * ajouterait un aller-retour réseau à une page qui n'en a pas besoin, et la
     * rendrait dépendante de la disponibilité de l'API.
     */
    public static function catalog(): array
    {
        $cache = Store::read(self::cachePath());
        $modeles = (array) ($cache['models'] ?? []);
        if ($modeles !== [] && time() - (int) ($cache['at'] ?? 0) < self::CACHE_TTL) {
            return $modeles;
        }

        $frais = self::refresh();
        if ($frais['ok']) {
            return $frais['models'];
        }
        // L'API n'a pas répondu : la liste connue vaut mieux qu'un champ vide.
        return $modeles !== [] ? $modeles : self::FALLBACK;
    }

    /**
     * Modèles connus, servis tant que l'API n'a jamais répondu. La liste reste
     * modifiable à la main : le champ accepte n'importe quel identifiant.
     */
    /** Modèle qui sait lire une image, au tarif de Flash. */
    public const VISION = 'deepseek-v4-flash-vision-exp';

    private const FALLBACK = [
        ['id' => 'deepseek-v4-flash', 'label' => 'deepseek-v4-flash'],
        ['id' => 'deepseek-v4-pro', 'label' => 'deepseek-v4-pro'],
        ['id' => self::VISION, 'label' => self::VISION],
    ];

    /** Ce modèle accepte-t-il une image dans la requête ? */
    public static function readsImages(string $model): bool
    {
        return str_contains($model, 'vision');
    }

    /**
     * Interroge l'API et met la liste en cache.
     * @return array{ok:bool,error:string,models:array,count:int}
     */
    public static function refresh(): array
    {
        if (!self::isConfigured()) {
            return ['ok' => false, 'error' => 'Renseignez d\'abord la clé API DeepSeek.', 'models' => [], 'count' => 0];
        }

        $reponse = Http::json(self::baseUrl() . '/models', null, [
            'Authorization: Bearer ' . trim((string) Config::get('deepseek.api_key', '')),
        ], 20);

        if (!$reponse['ok']) {
            $message = (string) ($reponse['data']['error']['message'] ?? $reponse['error'] ?? '');
            $erreur = $reponse['status'] === 401
                ? 'Clé API DeepSeek refusée.'
                : 'Liste indisponible' . ($message !== '' ? ' : ' . $message : ' (HTTP ' . $reponse['status'] . ')') . '.';
            // L'échec est daté, pour ne pas réessayer à chaque affichage.
            Store::write(self::cachePath(), Store::read(self::cachePath()) + ['attempted_at' => time()]);
            return ['ok' => false, 'error' => $erreur, 'models' => [], 'count' => 0];
        }

        $modeles = [];
        foreach ((array) ($reponse['data']['data'] ?? []) as $modele) {
            $id = trim((string) ($modele['id'] ?? ''));
            if ($id !== '') {
                $modeles[] = ['id' => $id, 'label' => $id];
            }
        }
        if ($modeles === []) {
            return ['ok' => false, 'error' => 'L\'API n\'a renvoyé aucun modèle.', 'models' => [], 'count' => 0];
        }

        usort($modeles, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
        Store::write(self::cachePath(), ['models' => $modeles, 'at' => time()]);
        return ['ok' => true, 'error' => '', 'models' => $modeles, 'count' => count($modeles)];
    }

    public static function fetchedAt(): int
    {
        return (int) (Store::read(self::cachePath())['at'] ?? 0);
    }

    // ------------------------------------------------------------- Requête

    /**
     * Corps de requête.
     *
     * Le prompt système devient un message de rôle « system » en tête : c'est
     * la convention OpenAI, que DeepSeek suit.
     */
    private static function payload(array $options): array
    {
        $messages = [];
        $system = trim((string) ($options['system'] ?? ''));
        $schema = $options['schema'] ?? null;

        if ($schema !== null) {
            // Le mode JSON de DeepSeek n'accepte pas de schéma : on le décrit
            // dans les consignes, et la réponse est validée au retour.
            $system .= "\n\nRéponds uniquement par un objet JSON valide, sans texte autour ni bloc "
                . "Markdown, conforme à ce schéma :\n"
                . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        if ($system !== '') {
            $messages[] = ['role' => 'system', 'content' => $system];
        }

        foreach ((array) ($options['messages'] ?? []) as $message) {
            $messages[] = [
                'role' => (string) ($message['role'] ?? 'user'),
                'content' => self::flatten($message['content'] ?? ''),
            ];
        }

        $payload = [
            'model' => trim((string) ($options['model'] ?? '')) !== ''
                ? (string) $options['model']
                : self::model(),
            'messages' => $messages,
            'max_tokens' => (int) ($options['max_tokens'] ?? Config::get('deepseek.max_tokens', 8000)),
        ];
        if ($schema !== null) {
            $payload['response_format'] = ['type' => 'json_object'];
        }
        return $payload;
    }

    /**
     * Ramène un contenu à du texte.
     *
     * Les blocs d'image sont écartés : DeepSeek ne lit pas les images, et
     * laisser passer un bloc inconnu ferait échouer toute la requête. La
     * capture du site est donc simplement ignorée sur ce fournisseur.
     */
    private static function flatten(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        $morceaux = [];
        foreach ((array) $content as $bloc) {
            if (is_string($bloc)) {
                $morceaux[] = $bloc;
            } elseif (($bloc['type'] ?? '') === 'text') {
                $morceaux[] = (string) ($bloc['text'] ?? '');
            }
        }
        return implode("\n\n", $morceaux);
    }

    private static function headers(): array
    {
        return [
            'Content-Type: application/json',
            'Authorization: Bearer ' . trim((string) Config::get('deepseek.api_key', '')),
        ];
    }

    /**
     * Exécute la requête, en streaming ou non.
     * @return array{ok:bool,error:string,text:string,usage:array,stop_reason:string,json:?array}
     */
    private static function call(array $payload, bool $streaming, ?callable $onDelta, array $options): array
    {
        if (!self::isConfigured()) {
            return self::failure('Aucune clé API DeepSeek n\'est renseignée dans les Réglages.');
        }
        if (!function_exists('curl_init')) {
            return self::failure('L\'extension cURL de PHP est requise pour appeler l\'API.');
        }

        $text = '';
        $usage = [];
        $stopReason = '';
        $buffer = '';

        $handle = curl_init();
        $reglages = [
            CURLOPT_URL => self::baseUrl() . '/chat/completions',
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => self::headers(),
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => (int) ($options['timeout'] ?? Config::get('deepseek.timeout', 600)),
            CURLOPT_CONNECTTIMEOUT => 15,
        ];

        if ($streaming) {
            $reglages[CURLOPT_HTTPHEADER] = array_merge(self::headers(), ['Accept: text/event-stream']);
            $reglages[CURLOPT_WRITEFUNCTION] = static function ($ch, string $chunk) use (
                &$buffer, &$text, &$usage, &$stopReason, $onDelta
            ): int {
                $buffer .= $chunk;
                while (($break = strpos($buffer, "\n")) !== false) {
                    $line = rtrim(substr($buffer, 0, $break), "\r");
                    $buffer = substr($buffer, $break + 1);
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
                    // Le dernier événement ne porte pas de choix, seulement
                    // l'usage : d'où le test séparé.
                    if (isset($event['usage']) && is_array($event['usage'])) {
                        $usage = $event['usage'];
                    }
                    $choix = $event['choices'][0] ?? null;
                    if ($choix === null) {
                        continue;
                    }
                    $piece = (string) ($choix['delta']['content'] ?? '');
                    if ($piece !== '') {
                        $text .= $piece;
                        if ($onDelta !== null) {
                            $onDelta($piece, ['length' => strlen($text)]);
                        }
                    }
                    if (($choix['finish_reason'] ?? null) !== null) {
                        $stopReason = (string) $choix['finish_reason'];
                    }
                }
                return strlen($chunk);
            };
        } else {
            $reglages[CURLOPT_RETURNTRANSFER] = true;
        }

        curl_setopt_array($handle, $reglages);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            return self::failure($streaming
                ? 'Streaming interrompu : ' . ($curlError ?: 'connexion perdue')
                : 'Connexion à l\'API impossible : ' . $curlError);
        }

        if (!$streaming) {
            $data = json_decode((string) $body, true);
            if (!is_array($data)) {
                return self::failure('Réponse illisible de l\'API (HTTP ' . $status . ').');
            }
            if ($status < 200 || $status >= 300) {
                return self::failure(self::describeError($status, $data));
            }
            $text = (string) ($data['choices'][0]['message']['content'] ?? '');
            $usage = (array) ($data['usage'] ?? []);
            $stopReason = (string) ($data['choices'][0]['finish_reason'] ?? '');
        } elseif ($status < 200 || $status >= 300) {
            // En erreur, le corps n'est pas du SSE mais un JSON d'erreur, que
            // la fonction d'écriture a accumulé tel quel.
            $data = json_decode($buffer !== '' ? $buffer : '{}', true);
            return self::failure(self::describeError($status, is_array($data) ? $data : []));
        }

        if ($text === '') {
            return self::failure('Le modèle n\'a renvoyé aucun contenu.');
        }

        return ['ok' => true, 'error' => '', 'text' => $text, 'usage' => $usage,
            'stop_reason' => $stopReason, 'json' => null];
    }

    /** Normalise la sortie vers la forme attendue par le reste de l'application. */
    private static function finish(string $text, array $usage, string $stopReason): array
    {
        // Les compteurs sont nommés autrement que chez Anthropic : on les
        // traduit pour que le suivi des coûts reste homogène. DeepSeek met en
        // cache tout seul et rapporte la part relue — elle coûte le trentième
        // du reste, la confondre avec l'entrée neuve fausserait la facture.
        $cache = (int) ($usage['prompt_cache_hit_tokens'] ?? 0);
        $total = (int) ($usage['prompt_tokens'] ?? 0);
        $neuve = isset($usage['prompt_cache_miss_tokens'])
            ? (int) $usage['prompt_cache_miss_tokens']
            : max(0, $total - $cache);

        $normalise = [
            'input_tokens' => $neuve,
            'cache_read_input_tokens' => $cache,
            'output_tokens' => (int) ($usage['completion_tokens'] ?? 0),
        ];
        Models::recordUsage($normalise);

        return [
            'ok' => true,
            'error' => '',
            'text' => $text,
            'json' => Claude::decodeJson($text),
            'usage' => $normalise,
            // « length » est l'équivalent de « max_tokens » : le reste de
            // l'application teste ce mot-là pour signaler une page tronquée.
            'stop_reason' => $stopReason === 'length' ? 'max_tokens' : $stopReason,
        ];
    }

    private static function describeError(int $status, array $data): string
    {
        $message = (string) ($data['error']['message'] ?? $data['message'] ?? '');
        return match ($status) {
            401 => 'Clé API DeepSeek refusée. Vérifiez-la dans les Réglages.',
            402 => 'Solde DeepSeek insuffisant : rechargez le compte.',
            422 => 'Requête refusée par DeepSeek : ' . ($message ?: 'paramètres invalides') . '.',
            429 => 'Limite de débit DeepSeek atteinte. Réessayez dans un instant.',
            500, 503 => 'DeepSeek est momentanément indisponible. Réessayez.',
            default => 'Erreur DeepSeek (HTTP ' . $status . ')' . ($message !== '' ? ' : ' . $message : '') . '.',
        };
    }

    private static function failure(string $message): array
    {
        return ['ok' => false, 'error' => $message, 'text' => '', 'json' => null,
            'usage' => [], 'stop_reason' => ''];
    }
}
