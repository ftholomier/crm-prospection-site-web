<?php
declare(strict_types=1);

namespace App;

/**
 * Client Gemini (Google).
 *
 * Google publie, à côté de sa propre API, un point d'entrée qui accepte la même
 * forme de requête que DeepSeek : le socle commun sert donc les deux, et il n'y
 * a ici que ce qui appartient en propre à Gemini.
 *
 * Deux choses le distinguent utilement de DeepSeek :
 *  - Tous ses modèles lisent les images. La capture du site peut donc
 *    accompagner le brief, ce que seul le modèle vision permet chez DeepSeek.
 *  - Le catalogue préfixe ses identifiants par « models/ » ; on rend le nom
 *    tel qu'il doit être appelé, sans ce préfixe.
 *
 * Comme DeepSeek, il n'a pas d'outil serveur de lecture web : un site que notre
 * serveur ne peut pas atteindre reste l'affaire de Claude.
 */
final class Gemini extends ChatCompletions
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/openai';

    /**
     * Modèle par défaut.
     *
     * Flash-Lite 2.5 est le moins cher de toute la famille — 0,10 $ / 0,40 $
     * par million de jetons, soit le cinquième de la 3.5 — et il suffit
     * largement à remplir un gabarit dont la structure et le style sont déjà
     * imposés, avec un contrôle de conformité derrière.
     *
     * Aucune date de retrait n'est inscrite ici : je n'ai pas pu lire la page
     * des dépréciations de Google depuis cet hébergement, et une date inventée
     * ferait plus de dégâts que pas de date du tout. C'est la liste relevée sur
     * votre compte qui fait foi — si ce nom n'y figure plus, il ne s'affichera
     * pas, et un appel refusé le dit explicitement.
     */
    public const DEFAUT = 'gemini-2.5-flash-lite';

    public static function nom(): string
    {
        return 'Gemini';
    }

    public static function baseUrl(): string
    {
        $base = rtrim(trim((string) Config::get('gemini.base_url', '')), '/');
        return $base !== '' ? $base : self::BASE_URL;
    }

    public static function apiKey(): string
    {
        return trim((string) Config::get('gemini.api_key', ''));
    }

    public static function model(): string
    {
        $model = trim((string) Config::get('gemini.model', ''));
        if ($model === '') {
            return self::DEFAUT;
        }
        return $model;
    }

    public static function defaultMaxTokens(): int
    {
        return (int) Config::get('gemini.max_tokens', 8000);
    }

    public static function timeout(): int
    {
        return (int) Config::get('gemini.timeout', 600);
    }

    /** Toute la famille lit les images ; c'est son avantage sur DeepSeek. */
    public static function readsImages(string $model): bool
    {
        return true;
    }

    public static function cachePath(): string
    {
        return DATA_DIR . '/gemini-models.json';
    }

    /**
     * Liste servie tant que l'API n'a jamais répondu.
     *
     * Elle ne sert qu'à ne pas présenter un champ vide : les noms exacts des
     * versions préliminaires changent, et c'est précisément pourquoi la liste
     * se relit depuis le compte dès que la clé est saisie.
     */
    protected static function fallbackModels(): array
    {
        $noms = [
            'gemini-2.5-flash-lite',
            'gemini-2.5-flash',
            'gemini-3.1-flash-lite',
            'gemini-3.5-flash-lite',
            'gemini-3-flash',
        ];
        return array_map(static fn (string $id): array => ['id' => $id, 'label' => $id], $noms);
    }

    /**
     * Gemini refuse une clé invalide par un 400, pas par un 401.
     *
     * Sans ce cas particulier, une clé mal collée s'afficherait « liste
     * indisponible » — on chercherait la panne du côté du réseau au lieu du
     * côté du champ qu'on vient de remplir.
     */
    protected static function isAuthError(int $status, string $message): bool
    {
        if ($status === 400 && stripos($message, 'api key') !== false) {
            return true;
        }
        return parent::isAuthError($status, $message);
    }

    /**
     * Familles écartées du menu : elles ne rédigent pas de HTML.
     *
     * Le compte donne accès aux embeddings, à la synthèse vocale, à la
     * génération d'images et de vidéos par le même catalogue. Les laisser
     * s'afficher revient à proposer un choix qui ne peut qu'échouer.
     */
    private const HORS_SUJET = ['embedding', 'aqa', 'imagen', 'veo', 'tts', '-image', 'native-audio', 'live-'];

    protected static function keepModel(string $id): bool
    {
        $id = strtolower($id);
        foreach (self::HORS_SUJET as $motif) {
            if (str_contains($id, $motif)) {
                return false;
            }
        }
        return true;
    }

    /** Le catalogue rend « models/gemini-… » ; on appelle sans le préfixe. */
    protected static function normalizeModelId(string $id): string
    {
        $id = trim($id);
        return str_starts_with($id, 'models/') ? substr($id, 7) : $id;
    }

    protected static function describeError(int $status, array $data): string
    {
        $message = (string) ($data['error']['message'] ?? $data['message'] ?? '');
        return match ($status) {
            400 => 'Requête refusée par Gemini : ' . ($message ?: 'paramètres invalides') . '.',
            401, 403 => 'Clé API Gemini refusée. Vérifiez-la dans les Réglages, et que l\'API '
                . 'Generative Language est activée sur le projet Google.',
            404 => 'Modèle Gemini inconnu' . ($message !== '' ? ' : ' . $message : '')
                . '. Rafraîchissez la liste des modèles dans les Réglages.',
            429 => 'Quota Gemini atteint. Réessayez dans un instant.',
            500, 503 => 'Gemini est momentanément indisponible. Réessayez.',
            default => 'Erreur Gemini (HTTP ' . $status . ')' . ($message !== '' ? ' : ' . $message : '') . '.',
        };
    }
}
