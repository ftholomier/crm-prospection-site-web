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

    /**
     * Toutes les clés nécessaires sont-elles présentes ?
     *
     * Un réglage par étape peut solliciter les deux fournisseurs : il ne suffit
     * plus que le principal soit configuré, sinon la génération partirait pour
     * échouer à mi-parcours, après avoir déjà consommé le brief.
     */
    public static function isConfigured(): bool
    {
        foreach (self::providersUsed() as $fournisseur) {
            $ok = $fournisseur === self::DEEPSEEK ? DeepSeek::isConfigured() : Claude::isConfigured();
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    /** Fournisseurs réellement sollicités par la génération, sans doublon. */
    public static function providersUsed(): array
    {
        $utilises = [self::provider()];
        foreach (array_keys(self::ETAPES) as $etape) {
            $utilises[] = self::for($etape)['provider'];
        }
        return array_values(array_unique($utilises));
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

    /**
     * La capture du site peut-elle être jointe ?
     *
     * Elle n'accompagne que le brief : c'est donc le fournisseur de cette
     * étape-là qui décide, pas le principal.
     */
    public static function readsImages(): bool
    {
        return self::for('brief')['provider'] === self::CLAUDE;
    }

    /** Étapes dont le modèle se règle séparément. */
    public const ETAPES = [
        'brief' => 'Direction artistique',
        'pages' => 'Pages de la maquette',
    ];

    /**
     * Fournisseur et modèle d'une étape.
     *
     * Toute la raison d'être de ce découpage tient en deux phrases. Le brief
     * décide de ce qu'on garde et de ce qu'on refuse d'inventer : un modèle
     * faible y écrit un chiffre qu'aucune page du site n'atteste, et le
     * contrôle de conformité ne peut pas le voir — il ne juge que la forme.
     * Les pages, elles, ne font que remplir un gabarit dont la structure et le
     * style sont déjà imposés, et elles pèsent l'essentiel des jetons produits.
     *
     * @return array{provider:string,model:string}
     */
    public static function for(?string $etape = null): array
    {
        $defaut = ['provider' => self::provider(), 'model' => ''];
        if ($etape === null || !isset(self::ETAPES[$etape])) {
            return $defaut;
        }

        $reglage = (array) Config::get('ai.steps.' . $etape, []);
        $fournisseur = trim((string) ($reglage['provider'] ?? ''));
        $modele = trim((string) ($reglage['model'] ?? ''));
        if ($fournisseur === '' && $modele === '') {
            return $defaut;
        }

        $fournisseur = $fournisseur === self::DEEPSEEK ? self::DEEPSEEK
            : ($fournisseur === self::CLAUDE ? self::CLAUDE : self::provider());

        return ['provider' => $fournisseur, 'model' => $modele];
    }

    /** Modèle réellement employé pour une étape, nom résolu. */
    public static function modelFor(?string $etape = null): string
    {
        $choix = self::for($etape);
        if ($choix['model'] !== '') {
            return $choix['model'];
        }
        return $choix['provider'] === self::DEEPSEEK
            ? DeepSeek::model()
            : (string) Config::get('claude.model', 'claude-opus-5');
    }

    public static function message(array $options): array
    {
        return self::dispatch($options, null);
    }

    /** @param callable(string $chunk, array $meta):void|null $onDelta */
    public static function stream(array $options, ?callable $onDelta = null): array
    {
        return self::dispatch($options, $onDelta ?? static function (): void {
        }, true);
    }

    /**
     * Aiguille l'appel vers le client de l'étape.
     *
     * L'étape se déclare dans les options sous « etape » ; sans elle, le
     * fournisseur principal répond, ce qui garde tous les appels existants
     * inchangés.
     */
    private static function dispatch(array $options, ?callable $onDelta, bool $streaming = false): array
    {
        $etape = isset($options['etape']) ? (string) $options['etape'] : null;
        $choix = self::for($etape);
        unset($options['etape']);

        // Un appelant peut imposer le couple fournisseur/modèle : c'est ce qui
        // permet de comparer plusieurs modèles sans toucher aux réglages.
        $impose = (array) ($options['impose'] ?? []);
        unset($options['impose']);
        if (($impose['provider'] ?? '') !== '') {
            $choix['provider'] = $impose['provider'] === self::DEEPSEEK ? self::DEEPSEEK : self::CLAUDE;
            $choix['model'] = trim((string) ($impose['model'] ?? ''));
            // Un appel imposé n'entre pas dans la ventilation par étape : il ne
            // reflète pas la consommation ordinaire de l'application.
            $etape = null;
        }

        if ($choix['model'] !== '') {
            $options['model'] = $choix['model'];
        }

        $reponse = $choix['provider'] === self::DEEPSEEK
            ? ($streaming ? DeepSeek::stream($options, $onDelta) : DeepSeek::message($options))
            : ($streaming ? Claude::stream($options, $onDelta) : Claude::message($options));

        // La ventilation se fait ici : les clients ignorent à quelle étape ils
        // répondent, et n'ont pas à le savoir.
        if ($reponse['ok']) {
            if ($etape !== null) {
                Models::recordStep($etape, $reponse['usage'] ?? []);
            }
            // Le coût est arrêté maintenant, au tarif du modèle qui vient de
            // répondre : le recalculer plus tard, quand les réglages auront
            // changé, donnerait un chiffre faux.
            Consumption::record(
                $choix['provider'],
                $choix['model'] !== '' ? $choix['model'] : self::modelFor($etape),
                $reponse['usage'] ?? []
            );
        }
        return $reponse;
    }

    public static function test(): array
    {
        return self::provider() === self::DEEPSEEK ? DeepSeek::test() : Claude::test();
    }

    /** Message d'aide quand rien n'est configuré, adapté au fournisseur choisi. */
    public static function missingKeyMessage(): string
    {
        $manquants = [];
        foreach (self::providersUsed() as $fournisseur) {
            $ok = $fournisseur === self::DEEPSEEK ? DeepSeek::isConfigured() : Claude::isConfigured();
            if (!$ok) {
                $manquants[] = self::label($fournisseur);
            }
        }
        if ($manquants === []) {
            return '';
        }
        return count($manquants) > 1
            ? 'Vos réglages par étape sollicitent ' . implode(' et ', $manquants)
                . ' : les deux clés API doivent être renseignées.'
            : 'Aucune clé API ' . $manquants[0] . ' n\'est renseignée dans les Réglages.';
    }
}
