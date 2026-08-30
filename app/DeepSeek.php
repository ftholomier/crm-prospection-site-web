<?php
declare(strict_types=1);

namespace App;

/**
 * Client DeepSeek.
 *
 * Tout le dialogue — convention OpenAI, flux SSE, traduction des compteurs —
 * vient du socle commun. Ne reste ici que ce qui appartient en propre à
 * DeepSeek : son adresse, ses noms de modèles, sa manière de compter le cache
 * et ses messages d'erreur.
 *
 * Une différence compte et ne se rattrape pas : il n'existe pas d'outil serveur
 * de lecture web. La lecture d'un site que notre serveur ne peut pas atteindre
 * reste donc l'affaire de Claude.
 */
final class DeepSeek extends ChatCompletions
{
    private const BASE_URL = 'https://api.deepseek.com';

    /** Modèle par défaut, et cible de reprise des anciens noms. */
    public const DEFAUT = 'deepseek-v4-flash';

    /** Modèle qui sait lire une image, au tarif de Flash. */
    public const VISION = 'deepseek-v4-flash-vision-exp';

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

    public static function nom(): string
    {
        return 'DeepSeek';
    }

    /**
     * Adresse de base, modifiable dans la configuration : plusieurs
     * hébergements exposent une API compatible derrière leur propre domaine, et
     * un mandataire d'entreprise se règle ici plutôt que dans le code.
     */
    public static function baseUrl(): string
    {
        $base = rtrim(trim((string) Config::get('deepseek.base_url', '')), '/');
        return $base !== '' ? $base : self::BASE_URL;
    }

    public static function apiKey(): string
    {
        return trim((string) Config::get('deepseek.api_key', ''));
    }

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

    public static function defaultMaxTokens(): int
    {
        return (int) Config::get('deepseek.max_tokens', 8000);
    }

    public static function timeout(): int
    {
        return (int) Config::get('deepseek.timeout', 600);
    }

    /** Seul le modèle vision accepte une image ; les autres la refuseraient. */
    public static function readsImages(string $model): bool
    {
        return str_contains($model, 'vision');
    }

    public static function cachePath(): string
    {
        return DATA_DIR . '/deepseek-models.json';
    }

    protected static function fallbackModels(): array
    {
        return [
            ['id' => self::DEFAUT, 'label' => self::DEFAUT],
            ['id' => 'deepseek-v4-pro', 'label' => 'deepseek-v4-pro'],
            ['id' => self::VISION, 'label' => self::VISION],
        ];
    }

    /**
     * DeepSeek ne suit pas la convention OpenAI sur ce point : il rapporte la
     * part relue sous son propre nom, et non dans prompt_tokens_details.
     */
    protected static function cachedTokens(array $usage): int
    {
        if (isset($usage['prompt_cache_hit_tokens'])) {
            return (int) $usage['prompt_cache_hit_tokens'];
        }
        return parent::cachedTokens($usage);
    }

    protected static function describeError(int $status, array $data): string
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
}
