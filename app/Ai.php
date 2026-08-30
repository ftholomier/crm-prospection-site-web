<?php
declare(strict_types=1);

namespace App;

/**
 * Choix du fournisseur d'IA.
 *
 * L'application ne s'adresse plus directement à Claude : elle passe par ici, et
 * c'est le réglage « ai.provider » qui décide. Tous les clients rendent la même
 * forme de réponse, si bien que le générateur ignore lequel a répondu.
 *
 * Ce qu'ils ne font pas également est dit franchement plutôt que masqué : la
 * lecture d'un site bloqué repose sur un outil serveur qui n'existe que chez
 * Anthropic, et tous les modèles ne savent pas lire une capture d'écran.
 */
final class Ai
{
    public const CLAUDE = 'claude';
    public const DEEPSEEK = 'deepseek';
    public const GEMINI = 'gemini';

    /**
     * Les fournisseurs, dans l'ordre où ils sont proposés.
     *
     * Une seule liste : ajouter un fournisseur ne doit pas obliger à retrouver
     * les huit endroits qui les énuméraient, ce qui est exactement la faute qui
     * a laissé les listes de modèles désynchronisées de leur fournisseur.
     */
    public const FOURNISSEURS = [
        self::CLAUDE => 'Claude (Anthropic)',
        self::DEEPSEEK => 'DeepSeek',
        self::GEMINI => 'Gemini (Google)',
    ];

    /** Classe cliente d'un fournisseur. */
    private const CLIENTS = [
        self::CLAUDE => Claude::class,
        self::DEEPSEEK => DeepSeek::class,
        self::GEMINI => Gemini::class,
    ];

    /** Ramène n'importe quelle saisie à un fournisseur connu. */
    public static function normalize(string $provider, string $defaut = self::CLAUDE): string
    {
        return isset(self::FOURNISSEURS[$provider]) ? $provider : $defaut;
    }

    public static function provider(): string
    {
        return self::normalize((string) Config::get('ai.provider', self::CLAUDE));
    }

    public static function label(?string $provider = null): string
    {
        $provider = self::normalize((string) ($provider ?? self::provider()));
        // Le nom court suffit à l'écran : « Claude », « DeepSeek », « Gemini ».
        return trim(explode('(', self::FOURNISSEURS[$provider])[0]);
    }

    public static function isConfiguredFor(string $provider): bool
    {
        $client = self::CLIENTS[self::normalize($provider)];
        return $client::isConfigured();
    }

    /**
     * Toutes les clés nécessaires sont-elles présentes ?
     *
     * Un réglage par étape peut solliciter plusieurs fournisseurs : il ne suffit
     * plus que le principal soit configuré, sinon la génération partirait pour
     * échouer à mi-parcours, après avoir déjà consommé le brief.
     */
    public static function isConfigured(): bool
    {
        foreach (self::providersUsed() as $fournisseur) {
            if (!self::isConfiguredFor($fournisseur)) {
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

    /** Modèle par défaut d'un fournisseur, celui de ses Réglages. */
    public static function defaultModel(string $provider): string
    {
        $provider = self::normalize($provider);
        if ($provider === self::CLAUDE) {
            return (string) Config::get('claude.model', 'claude-opus-5');
        }
        $client = self::CLIENTS[$provider];
        return $client::model();
    }

    /** Modèle en vigueur, pour l'afficher là où le coût et le suivi le sont. */
    public static function model(): string
    {
        return self::defaultModel(self::provider());
    }

    /**
     * Plafond de jetons produits, propre au fournisseur.
     *
     * Il ne se déduit pas du principal : une page générée par Gemini doit
     * respecter le plafond réglé pour Gemini, sinon on demande à un modèle
     * 24 000 jetons quand ses réglages en annoncent 8 000.
     */
    public static function maxTokens(string $provider): int
    {
        $provider = self::normalize($provider);
        if ($provider === self::CLAUDE) {
            return (int) Config::get('claude.max_tokens', 24000);
        }
        $client = self::CLIENTS[$provider];
        return $client::defaultMaxTokens();
    }

    /**
     * Modèles proposés par un fournisseur, tels qu'ils s'affichent dans un menu.
     *
     * @return array<int,array{id:string,label:string}>
     */
    public static function catalog(string $provider): array
    {
        $provider = self::normalize($provider);
        if ($provider === self::CLAUDE) {
            $modeles = [];
            foreach (Models::catalog() as $modele) {
                $modeles[] = ['id' => (string) $modele['id'], 'label' => (string) ($modele['name'] ?? $modele['id'])];
            }
            return $modeles;
        }
        $client = self::CLIENTS[$provider];
        return $client::catalog();
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
     * Elle n'accompagne que le brief : c'est le modèle de cette étape-là qui
     * décide. Tous les modèles Claude et Gemini la lisent ; côté DeepSeek, seul
     * le modèle vision — au même tarif que Flash.
     */
    public static function readsImages(): bool
    {
        $provider = self::for('brief')['provider'];
        if ($provider === self::CLAUDE) {
            return true;
        }
        $client = self::CLIENTS[self::normalize($provider)];
        return $client::readsImages(self::modelFor('brief'));
    }

    /**
     * Étapes de la génération d'une maquette, dont le modèle se règle
     * séparément. Ce sont elles, et elles seules, qui composent le coût d'une
     * maquette et qui décident des clés API indispensables.
     */
    public const ETAPES = [
        'brief' => 'Direction artistique',
        'pages' => 'Pages de la maquette',
    ];

    /**
     * Étapes réglables mais étrangères à la maquette.
     *
     * La lecture d'un site bloqué n'a pas lieu à chaque génération : c'est un
     * recours. Son coût ne doit donc pas entrer dans le total « par maquette »,
     * et l'absence de sa clé ne doit pas empêcher de générer.
     */
    public const ETAPES_HORS_MAQUETTE = [
        'lecture' => 'Lecture du site par l\'IA',
    ];

    /**
     * Étapes dont le fournisseur est imposé.
     *
     * La lecture d'un site passe par l'outil serveur web_fetch, qui n'existe
     * que chez Anthropic : la requête part de chez eux, et c'est précisément ce
     * qui la fait passer là où notre serveur est filtré. Le choix se limite
     * donc au modèle — et il vaut la peine, c'est l'étape la plus lourde en
     * jetons d'entrée de toute l'application.
     */
    public const ETAPES_IMPOSEES = [
        'lecture' => self::CLAUDE,
    ];

    /** Toutes les étapes réglables, dans l'ordre où elles s'enchaînent. */
    public static function etapes(): array
    {
        return self::ETAPES_HORS_MAQUETTE + self::ETAPES;
    }

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
        if ($etape === null || !isset(self::etapes()[$etape])) {
            return $defaut;
        }

        $reglage = (array) Config::get('ai.steps.' . $etape, []);
        $modele = trim((string) ($reglage['model'] ?? ''));

        // Une étape au fournisseur imposé ignore ce qui serait enregistré :
        // seul son modèle se règle.
        if (isset(self::ETAPES_IMPOSEES[$etape])) {
            return ['provider' => self::ETAPES_IMPOSEES[$etape], 'model' => $modele];
        }

        $fournisseur = trim((string) ($reglage['provider'] ?? ''));
        if ($fournisseur === '' && $modele === '') {
            return $defaut;
        }

        return [
            'provider' => self::normalize($fournisseur, self::provider()),
            'model' => $modele,
        ];
    }

    /** Modèle réellement employé pour une étape, nom résolu. */
    public static function modelFor(?string $etape = null): string
    {
        $choix = self::for($etape);
        return $choix['model'] !== '' ? $choix['model'] : self::defaultModel($choix['provider']);
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
            $choix['provider'] = self::normalize((string) $impose['provider']);
            $choix['model'] = trim((string) ($impose['model'] ?? ''));
            // Un appel imposé n'entre pas dans la ventilation par étape : il ne
            // reflète pas la consommation ordinaire de l'application.
            $etape = null;
        }

        if ($choix['model'] !== '') {
            $options['model'] = $choix['model'];
        }

        $client = self::CLIENTS[$choix['provider']];
        $reponse = $streaming ? $client::stream($options, $onDelta) : $client::message($options);

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

    /**
     * Appel avec outils serveur — la lecture d'un site bloqué.
     *
     * Il ne passe pas par dispatch() : les outils serveur imposent une boucle
     * de reprise propre à Anthropic. Mais il est chiffré et ventilé comme les
     * autres, sinon l'étape la plus lourde en jetons d'entrée resterait absente
     * du relevé — et le relevé aurait cessé d'être juste.
     */
    public static function withServerTools(array $options, int $maxContinuations = 4): array
    {
        $etape = isset($options['etape']) ? (string) $options['etape'] : null;
        unset($options['etape']);

        $modele = $etape !== null ? self::modelFor($etape) : self::defaultModel(self::CLAUDE);
        $options['model'] = $modele;

        $reponse = Claude::withServerTools($options, $maxContinuations);
        if ($reponse['ok']) {
            if ($etape !== null) {
                Models::recordStep($etape, $reponse['usage'] ?? []);
            }
            Consumption::record(self::CLAUDE, $modele, $reponse['usage'] ?? []);
        }
        return $reponse;
    }

    public static function test(?string $provider = null): array
    {
        $client = self::CLIENTS[self::normalize((string) ($provider ?? self::provider()))];
        return $client::test();
    }

    /** Message d'aide quand rien n'est configuré, adapté au fournisseur choisi. */
    public static function missingKeyMessage(): string
    {
        $manquants = [];
        foreach (self::providersUsed() as $fournisseur) {
            if (!self::isConfiguredFor($fournisseur)) {
                $manquants[] = self::label($fournisseur);
            }
        }
        if ($manquants === []) {
            return '';
        }
        return count($manquants) > 1
            ? 'Vos réglages par étape sollicitent ' . implode(' et ', $manquants)
                . ' : toutes ces clés API doivent être renseignées.'
            : 'Aucune clé API ' . $manquants[0] . ' n\'est renseignée dans les Réglages.';
    }
}
