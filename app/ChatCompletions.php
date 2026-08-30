<?php
declare(strict_types=1);

namespace App;

/**
 * Socle des fournisseurs qui exposent un point d'entrée /chat/completions.
 *
 * Aucun appel ne part vers OpenAI et aucune clé OpenAI n'est demandée : cette
 * forme de requête — messages à plat, prompt système en tête, jeton porteur,
 * flux SSE de fragments — est simplement celle que DeepSeek et Google ont
 * reprise pour leurs propres API. La partager évite d'écrire deux fois le même
 * dialogue. Ce qui distingue les fournisseurs tient dans quelques méthodes
 * redéfinies : l'adresse, la clé, le modèle par défaut, la traduction des
 * erreurs et celle des compteurs.
 *
 * Les réponses sont ramenées à la forme du client Claude, pour que le reste de
 * l'application ignore lequel des fournisseurs a répondu.
 */
abstract class ChatCompletions
{
    // ------------------------------------------------- À définir par chacun

    abstract public static function baseUrl(): string;

    abstract public static function apiKey(): string;

    abstract public static function model(): string;

    abstract public static function defaultMaxTokens(): int;

    abstract public static function timeout(): int;

    /** Nom du fournisseur, pour les messages destinés à l'utilisateur. */
    abstract public static function nom(): string;

    /** Ce modèle accepte-t-il une image dans la requête ? */
    abstract public static function readsImages(string $model): bool;

    /** Message d'erreur en français, à partir du code et du corps de réponse. */
    abstract protected static function describeError(int $status, array $data): string;

    // ------------------------------------------------------------- Publique

    public static function isConfigured(): bool
    {
        return trim(static::apiKey()) !== '';
    }

    /** @return array{ok:bool,error:string,text:string,json:?array,usage:array,stop_reason:string} */
    public static function message(array $options): array
    {
        $reponse = static::call(static::payload($options), false, null, $options);
        return $reponse['ok']
            ? static::finish($reponse['text'], $reponse['usage'], $reponse['stop_reason'])
            : $reponse;
    }

    /** @param callable(string $chunk, array $meta):void|null $onDelta */
    public static function stream(array $options, ?callable $onDelta = null): array
    {
        $payload = static::payload($options);
        $payload['stream'] = true;
        // Sans cela l'usage n'est pas renvoyé en fin de flux, et le suivi des
        // coûts resterait vide sur toutes les pages générées en streaming.
        $payload['stream_options'] = ['include_usage' => true];

        $reponse = static::call($payload, true, $onDelta, $options);
        return $reponse['ok']
            ? static::finish($reponse['text'], $reponse['usage'], $reponse['stop_reason'])
            : $reponse;
    }

    /** Contrôle rapide de la clé, pour le bouton d'essai des Réglages. */
    public static function test(): array
    {
        $result = static::message([
            'system' => 'Tu réponds en un mot.',
            'messages' => [['role' => 'user', 'content' => 'Réponds exactement : OK']],
            'max_tokens' => 16,
        ]);
        return [
            'ok' => $result['ok'],
            'error' => $result['error'],
            'model' => static::model(),
            'answer' => trim($result['text']),
        ];
    }

    // ------------------------------------------------------------- Requête

    /**
     * Corps de requête.
     *
     * Le prompt système devient un message de rôle « system » en tête, comme
     * l'attendent les deux API.
     */
    protected static function payload(array $options): array
    {
        $modele = trim((string) ($options['model'] ?? '')) !== ''
            ? (string) $options['model']
            : static::model();

        $messages = [];
        $system = trim((string) ($options['system'] ?? ''));
        $schema = $options['schema'] ?? null;

        if ($schema !== null) {
            // Le mode JSON de ces API n'accepte pas de schéma : on le décrit
            // dans les consignes, et la réponse est validée au retour.
            $system .= "\n\nRéponds uniquement par un objet JSON valide, sans texte autour ni bloc "
                . "Markdown, conforme à ce schéma :\n"
                . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        if ($system !== '') {
            $messages[] = ['role' => 'system', 'content' => $system];
        }

        $accepteImages = static::readsImages($modele);
        foreach ((array) ($options['messages'] ?? []) as $message) {
            $messages[] = [
                'role' => (string) ($message['role'] ?? 'user'),
                'content' => static::convertContent($message['content'] ?? '', $accepteImages),
            ];
        }

        $payload = [
            'model' => $modele,
            'messages' => $messages,
            'max_tokens' => (int) ($options['max_tokens'] ?? static::defaultMaxTokens()),
        ];
        if ($schema !== null) {
            $payload['response_format'] = ['type' => 'json_object'];
        }
        return $payload;
    }

    /**
     * Traduit le contenu d'un message vers la forme attendue ici.
     *
     * Les blocs d'image d'Anthropic deviennent des adresses de données ; un
     * modèle qui ne lit pas les images les perd plutôt que de faire échouer
     * toute la requête sur un bloc qu'il ne connaît pas.
     */
    protected static function convertContent(mixed $content, bool $accepteImages): string|array
    {
        if (is_string($content)) {
            return $content;
        }

        $parties = [];
        $texte = [];
        foreach ((array) $content as $bloc) {
            if (is_string($bloc)) {
                $texte[] = $bloc;
                continue;
            }
            $type = (string) ($bloc['type'] ?? '');
            if ($type === 'text') {
                $texte[] = (string) ($bloc['text'] ?? '');
                continue;
            }
            if ($type === 'image' && $accepteImages) {
                $source = (array) ($bloc['source'] ?? []);
                $media = (string) ($source['media_type'] ?? 'image/jpeg');
                $data = (string) ($source['data'] ?? '');
                if ($data !== '') {
                    $parties[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $media . ';base64,' . $data]];
                }
            }
        }

        if ($parties === []) {
            return implode("\n\n", $texte);
        }
        $parties[] = ['type' => 'text', 'text' => implode("\n\n", $texte)];
        return $parties;
    }

    protected static function headers(): array
    {
        return [
            'Content-Type: application/json',
            'Authorization: Bearer ' . trim(static::apiKey()),
        ];
    }

    /**
     * Exécute la requête, en streaming ou non.
     * @return array{ok:bool,error:string,text:string,usage:array,stop_reason:string,json:?array}
     */
    protected static function call(array $payload, bool $streaming, ?callable $onDelta, array $options): array
    {
        if (!static::isConfigured()) {
            return static::failure('Aucune clé API ' . static::nom() . ' n\'est renseignée dans les Réglages.');
        }
        if (!function_exists('curl_init')) {
            return static::failure('L\'extension cURL de PHP est requise pour appeler l\'API.');
        }

        $text = '';
        $usage = [];
        $stopReason = '';
        $buffer = '';

        $handle = curl_init();
        $reglages = [
            CURLOPT_URL => rtrim(static::baseUrl(), '/') . '/chat/completions',
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => static::headers(),
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => (int) ($options['timeout'] ?? static::timeout()),
            CURLOPT_CONNECTTIMEOUT => 15,
        ];

        if ($streaming) {
            $reglages[CURLOPT_HTTPHEADER] = array_merge(static::headers(), ['Accept: text/event-stream']);
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
            return static::failure($streaming
                ? 'Streaming interrompu : ' . ($curlError ?: 'connexion perdue')
                : 'Connexion à l\'API impossible : ' . $curlError);
        }

        if (!$streaming) {
            $data = json_decode((string) $body, true);
            if (!is_array($data)) {
                return static::failure('Réponse illisible de l\'API (HTTP ' . $status . ').');
            }
            if ($status < 200 || $status >= 300) {
                return static::failure(static::describeError($status, $data));
            }
            $text = (string) ($data['choices'][0]['message']['content'] ?? '');
            $usage = (array) ($data['usage'] ?? []);
            $stopReason = (string) ($data['choices'][0]['finish_reason'] ?? '');
        } elseif ($status < 200 || $status >= 300) {
            // En erreur, le corps n'est pas du SSE mais un JSON d'erreur, que
            // la fonction d'écriture a accumulé tel quel.
            $data = json_decode($buffer !== '' ? $buffer : '{}', true);
            return static::failure(static::describeError($status, is_array($data) ? $data : []));
        }

        if ($text === '') {
            return static::failure('Le modèle n\'a renvoyé aucun contenu.');
        }

        return ['ok' => true, 'error' => '', 'text' => $text, 'usage' => $usage,
            'stop_reason' => $stopReason, 'json' => null];
    }

    /**
     * Normalise la sortie vers la forme attendue par le reste de l'application.
     *
     * Les compteurs sont nommés autrement que chez Anthropic : on les traduit
     * pour que le suivi des coûts reste homogène. La part d'entrée relue depuis
     * le cache est isolée — elle coûte une fraction du reste, les confondre
     * gonflerait la facture.
     */
    protected static function finish(string $text, array $usage, string $stopReason): array
    {
        $cache = static::cachedTokens($usage);
        $total = (int) ($usage['prompt_tokens'] ?? 0);

        $normalise = [
            'input_tokens' => max(0, $total - $cache),
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

    /** Jetons d'entrée servis depuis le cache du fournisseur. */
    protected static function cachedTokens(array $usage): int
    {
        return (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0);
    }

    protected static function failure(string $message): array
    {
        return ['ok' => false, 'error' => $message, 'text' => '', 'json' => null,
            'usage' => [], 'stop_reason' => ''];
    }

    // ------------------------------------------------------- Liste des modèles

    abstract public static function cachePath(): string;

    /** Modèles connus, servis tant que l'API n'a jamais répondu. */
    abstract protected static function fallbackModels(): array;

    protected const CACHE_TTL = 86400;

    /**
     * Modèles proposés par le compte.
     *
     * La liste est mise en cache : l'interroger à chaque affichage des Réglages
     * ajouterait un aller-retour réseau à une page qui n'en a pas besoin, et la
     * rendrait dépendante de la disponibilité de l'API.
     */
    public static function catalog(): array
    {
        $cache = Store::read(static::cachePath());
        $modeles = (array) ($cache['models'] ?? []);
        if ($modeles !== [] && time() - (int) ($cache['at'] ?? 0) < static::CACHE_TTL) {
            return $modeles;
        }

        $frais = static::refresh();
        if ($frais['ok']) {
            return $frais['models'];
        }
        // L'API n'a pas répondu : la liste connue vaut mieux qu'un champ vide.
        return $modeles !== [] ? $modeles : static::fallbackModels();
    }

    /**
     * Interroge l'API et met la liste en cache.
     * @return array{ok:bool,error:string,models:array,count:int}
     */
    public static function refresh(): array
    {
        if (!static::isConfigured()) {
            return ['ok' => false, 'error' => 'Renseignez d\'abord la clé API ' . static::nom() . '.',
                'models' => [], 'count' => 0];
        }

        $reponse = Http::json(rtrim(static::baseUrl(), '/') . '/models', null, [
            'Authorization: Bearer ' . trim(static::apiKey()),
        ], 20);

        if (!$reponse['ok']) {
            $message = (string) ($reponse['data']['error']['message'] ?? $reponse['error'] ?? '');
            $erreur = static::isAuthError((int) $reponse['status'], $message)
                ? 'Clé API ' . static::nom() . ' refusée.'
                : 'Liste indisponible' . ($message !== '' ? ' : ' . $message : ' (HTTP ' . $reponse['status'] . ')') . '.';
            // L'échec est daté, pour ne pas réessayer à chaque affichage.
            Store::write(static::cachePath(), Store::read(static::cachePath()) + ['attempted_at' => time()]);
            return ['ok' => false, 'error' => $erreur, 'models' => [], 'count' => 0];
        }

        $modeles = [];
        foreach ((array) ($reponse['data']['data'] ?? []) as $modele) {
            $id = static::normalizeModelId((string) ($modele['id'] ?? ''));
            if ($id === '' || !static::keepModel($id) || in_array($id, array_column($modeles, 'id'), true)) {
                continue;
            }
            $modeles[] = ['id' => $id, 'label' => $id];
        }
        if ($modeles === []) {
            return ['ok' => false, 'error' => 'L\'API n\'a renvoyé aucun modèle.', 'models' => [], 'count' => 0];
        }

        usort($modeles, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
        Store::write(static::cachePath(), ['models' => $modeles, 'at' => time()]);
        return ['ok' => true, 'error' => '', 'models' => $modeles, 'count' => count($modeles)];
    }

    /** La clé a-t-elle été refusée ? Tous les fournisseurs ne répondent pas 401. */
    protected static function isAuthError(int $status, string $message): bool
    {
        return in_array($status, [401, 403], true);
    }

    /**
     * Ce modèle sait-il produire du texte à la demande ?
     *
     * Le point d'entrée /models rend tout ce que le compte peut appeler, sans
     * dire à quoi chacun sert. Proposer un modèle d'embedding dans un menu de
     * génération ne se solderait que par un échec au premier appel.
     */
    protected static function keepModel(string $id): bool
    {
        return true;
    }

    /** Certains catalogues préfixent leurs identifiants ; on rend celui qu'on appelle. */
    protected static function normalizeModelId(string $id): string
    {
        return trim($id);
    }

    public static function fetchedAt(): int
    {
        return (int) (Store::read(static::cachePath())['at'] ?? 0);
    }
}
