<?php
declare(strict_types=1);

namespace App;

/**
 * Choix du fournisseur d'IA.
 *
 * L'application ne s'adresse plus directement à Claude : elle passe par ici, et
 * c'est le réglage « ai.provider » qui décide. Les deux clients rendent la même
 * forme de réponse, si bien que le générateur ignore lequel a répondu.
 *
 * Ce que les deux ne font pas également est dit franchement plutôt que masqué :
 * la lecture d'un site bloqué repose sur un outil serveur qui n'existe que chez
 * Anthropic, et la capture d'écran n'est lisible que par Claude.
 */
final class Ai
{
    public const CLAUDE = 'claude';
    public const DEEPSEEK = 'deepseek';

    public static function provider(): string
    {
        return Config::get('ai.provider') === self::DEEPSEEK ? self::DEEPSEEK : self::CLAUDE;
    }

    public static function label(?string $provider = null): string
    {
        return ($provider ?? self::provider()) === self::DEEPSEEK ? 'DeepSeek' : 'Claude';
    }

    public static function isConfigured(): bool
    {
        return self::provider() === self::DEEPSEEK
            ? DeepSeek::isConfigured()
            : Claude::isConfigured();
    }

    /** Modèle en vigueur, pour l'afficher là où le coût et le suivi le sont. */
    public static function model(): string
    {
        return self::provider() === self::DEEPSEEK
            ? DeepSeek::model()
            : (string) Config::get('claude.model', 'claude-opus-5');
    }

    /**
     * Le fournisseur peut-il lire un site depuis sa propre infrastructure ?
     *
     * C'est la seule fonction du produit qu'un changement de fournisseur retire :
     * elle repose sur l'outil serveur web_fetch, propre à Anthropic. Mieux vaut
     * le dire à l'écran que laisser un bouton échouer.
     */
    public static function canReadSites(): bool
    {
        return self::provider() === self::CLAUDE;
    }

    /** Le fournisseur sait-il regarder la capture du site actuel ? */
    public static function readsImages(): bool
    {
        return self::provider() === self::CLAUDE;
    }

    public static function message(array $options): array
    {
        return self::provider() === self::DEEPSEEK
            ? DeepSeek::message($options)
            : Claude::message($options);
    }

    /** @param callable(string $chunk, array $meta):void|null $onDelta */
    public static function stream(array $options, ?callable $onDelta = null): array
    {
        return self::provider() === self::DEEPSEEK
            ? DeepSeek::stream($options, $onDelta)
            : Claude::stream($options, $onDelta);
    }

    public static function test(): array
    {
        return self::provider() === self::DEEPSEEK ? DeepSeek::test() : Claude::test();
    }

    /** Message d'aide quand rien n'est configuré, adapté au fournisseur choisi. */
    public static function missingKeyMessage(): string
    {
        return self::provider() === self::DEEPSEEK
            ? 'Aucune clé API DeepSeek n\'est renseignée dans les Réglages.'
            : 'Aucune clé API Claude n\'est renseignée dans les Réglages.';
    }
}
