<?php
declare(strict_types=1);

namespace App;

/**
 * Catalogue des modèles Claude.
 *
 * La liste et les capacités sont récupérées en direct sur /v1/models et mises
 * en cache 24 heures. Les tarifs, eux, ne sont pas exposés par l'API : ils sont
 * maintenus dans la table ci-dessous, datée, et l'interface signale clairement
 * les modèles dont le tarif n'est pas connu plutôt que d'inventer un chiffre.
 */
final class Models
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/models';
    private const VERSION = '2023-06-01';
    private const CACHE_TTL = 86400;

    /** Date de relevé de la grille tarifaire ci-dessous. */
    public const PRICING_DATE = '2026-08-27';
    public const PRICING_SOURCE = 'https://platform.claude.com/docs/en/about-claude/pricing';

    /**
     * Tarifs publics en dollars par million de tokens.
     * [entrée, sortie, lecture de cache]
     */
    private const PRICING = [
        'claude-fable-5' => [10.0, 50.0, 1.0],
        'claude-mythos-5' => [10.0, 50.0, 1.0],
        'claude-opus-5' => [5.0, 25.0, 0.50],
        'claude-opus-4-8' => [5.0, 25.0, 0.50],
        'claude-opus-4-7' => [5.0, 25.0, 0.50],
        'claude-opus-4-6' => [5.0, 25.0, 0.50],
        'claude-opus-4-5' => [5.0, 25.0, 0.50],
        'claude-opus-4-1' => [15.0, 75.0, 1.50],
        'claude-opus-4-0' => [15.0, 75.0, 1.50],
        'claude-sonnet-5' => [2.0, 10.0, 0.20],
        'claude-sonnet-4-6' => [3.0, 15.0, 0.30],
        'claude-sonnet-4-5' => [3.0, 15.0, 0.30],
        'claude-sonnet-4-0' => [3.0, 15.0, 0.30],
        'claude-haiku-4-5' => [1.0, 5.0, 0.10],
        'claude-3-5-haiku' => [0.80, 4.0, 0.08],
    ];

    /**
     * Capacités de repli, utilisées tant que l'API n'a pas été interrogée.
     * Au-delà de cette liste, un modèle inconnu est présumé de génération
     * courante : réflexion adaptative et niveaux d'effort disponibles.
     */
    private const FALLBACK = [
        'claude-fable-5' => ['Claude Fable 5', true, ['low', 'medium', 'high', 'xhigh', 'max'], true],
        'claude-opus-5' => ['Claude Opus 5', true, ['low', 'medium', 'high', 'xhigh', 'max'], true],
        'claude-opus-4-8' => ['Claude Opus 4.8', true, ['low', 'medium', 'high', 'xhigh', 'max'], true],
        'claude-opus-4-7' => ['Claude Opus 4.7', true, ['low', 'medium', 'high', 'xhigh', 'max'], true],
        'claude-opus-4-6' => ['Claude Opus 4.6', true, ['low', 'medium', 'high', 'max'], true],
        'claude-opus-4-5' => ['Claude Opus 4.5', false, ['low', 'medium', 'high'], true],
        'claude-sonnet-5' => ['Claude Sonnet 5', true, ['low', 'medium', 'high', 'xhigh', 'max'], true],
        'claude-sonnet-4-6' => ['Claude Sonnet 4.6', true, ['low', 'medium', 'high', 'max'], true],
        'claude-sonnet-4-5' => ['Claude Sonnet 4.5', false, [], true],
        'claude-haiku-4-5' => ['Claude Haiku 4.5', false, [], true],
    ];

    /**
     * Consommation de référence d'une maquette complète (brief + 3 pages),
     * utilisée pour estimer un coût tant qu'aucune génération réelle n'a été
     * mesurée. Remplacée par la moyenne observée dès la première maquette.
     */
    private const DEFAULT_PROFILE = ['input' => 18000, 'output' => 24000];

    public static function cachePath(): string
    {
        return DATA_DIR . '/models.json';
    }

    public static function usagePath(): string
    {
        return DATA_DIR . '/usage.json';
    }

    // --------------------------------------------------------- Catalogue

    /**
     * Catalogue complet, trié du moins cher au plus cher.
     * @return array<int,array{id:string,name:string,input:?float,output:?float,cost:?float,context:?int,max_output:?int,adaptive:bool,efforts:array,structured:bool,live:bool}>
     */
    public static function catalog(): array
    {
        $cached = Store::read(self::cachePath());
        $live = is_array($cached['models'] ?? null) ? $cached['models'] : [];

        $entries = [];
        foreach ($live as $model) {
            $id = (string) ($model['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $entries[$id] = self::describe($id, $model, true);
        }

        // Les modèles connus absents de la réponse (clé sans accès, API muette)
        // restent proposés : mieux vaut une liste complète qu'une liste vide.
        foreach (self::FALLBACK as $id => $_) {
            if (!isset($entries[$id])) {
                $entries[$id] = self::describe($id, null, false);
            }
        }

        $current = trim((string) Config::get('claude.model', ''));
        if ($current !== '' && !isset($entries[$current])) {
            $entries[$current] = self::describe($current, null, false);
        }

        $list = array_values($entries);
        usort($list, static function (array $a, array $b): int {
            // Tarif inconnu : rejeté en fin de liste plutôt que traité comme gratuit.
            $costA = $a['cost'] ?? PHP_FLOAT_MAX;
            $costB = $b['cost'] ?? PHP_FLOAT_MAX;
            return $costA === $costB ? strcmp($a['name'], $b['name']) : ($costA <=> $costB);
        });
        return $list;
    }

    /** Fiche normalisée d'un modèle, tarif et capacités réunis. */
    private static function describe(string $id, ?array $model, bool $live): array
    {
        $price = self::PRICING[$id] ?? null;
        $fallback = self::FALLBACK[$id] ?? null;
        $caps = is_array($model['capabilities'] ?? null) ? $model['capabilities'] : null;

        return [
            'id' => $id,
            'name' => (string) ($model['display_name'] ?? ($fallback[0] ?? $id)),
            'input' => $price[0] ?? null,
            'output' => $price[1] ?? null,
            'cache_read' => $price[2] ?? null,
            'cost' => $price === null ? null : self::costFor($price[0], $price[1]),
            'context' => isset($model['max_input_tokens']) ? (int) $model['max_input_tokens'] : null,
            'max_output' => isset($model['max_tokens']) ? (int) $model['max_tokens'] : null,
            'adaptive' => $caps !== null
                ? (bool) ($caps['thinking']['types']['adaptive']['supported'] ?? false)
                : ($fallback[1] ?? true),
            'efforts' => $caps !== null
                ? self::effortsFrom($caps)
                : ($fallback[2] ?? ['low', 'medium', 'high', 'xhigh', 'max']),
            'structured' => $caps !== null
                ? (bool) ($caps['structured_outputs']['supported'] ?? false)
                : ($fallback[3] ?? true),
            'vision' => $caps !== null ? (bool) ($caps['image_input']['supported'] ?? false) : true,
            'live' => $live,
        ];
    }

    /** @return string[] */
    private static function effortsFrom(array $caps): array
    {
        if (empty($caps['effort']['supported'])) {
            return [];
        }
        $levels = [];
        foreach (['low', 'medium', 'high', 'xhigh', 'max'] as $level) {
            if (!empty($caps['effort'][$level]['supported'])) {
                $levels[] = $level;
            }
        }
        return $levels;
    }

    /** Fiche d'un modèle précis, avec repli sur une description générique. */
    public static function find(string $id): array
    {
        foreach (self::catalog() as $model) {
            if ($model['id'] === $id) {
                return $model;
            }
        }
        return self::describe($id, null, false);
    }

    // ------------------------------------------------------------- Coûts

    /** Profil de consommation retenu : mesuré si disponible, théorique sinon. */
    public static function profile(): array
    {
        $usage = Store::read(self::usagePath());
        $count = (int) ($usage['mockups'] ?? 0);
        if ($count < 1) {
            return self::DEFAULT_PROFILE + ['measured' => false, 'samples' => 0];
        }
        return [
            'input' => (int) round((int) ($usage['input'] ?? 0) / $count),
            'output' => (int) round((int) ($usage['output'] ?? 0) / $count),
            'measured' => true,
            'samples' => $count,
        ];
    }

    /** Coût estimé d'une maquette complète, en dollars. */
    public static function costFor(float $input, float $output): float
    {
        $profile = self::profile();
        return $profile['input'] / 1e6 * $input + $profile['output'] / 1e6 * $output;
    }

    /** Comptabilise la consommation d'un appel, pour affiner les estimations. */
    public static function recordUsage(array $usage, bool $completesMockup = false): void
    {
        $input = (int) ($usage['input_tokens'] ?? 0)
            + (int) ($usage['cache_creation_input_tokens'] ?? 0)
            + (int) ($usage['cache_read_input_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? 0);
        if ($input === 0 && $output === 0) {
            return;
        }

        Store::mutate(self::usagePath(), static function (array $store) use ($input, $output, $completesMockup): array {
            $store['input'] = (int) ($store['input'] ?? 0) + $input;
            $store['output'] = (int) ($store['output'] ?? 0) + $output;
            $store['calls'] = (int) ($store['calls'] ?? 0) + 1;
            if ($completesMockup) {
                $store['mockups'] = (int) ($store['mockups'] ?? 0) + 1;
            }
            $store['updated_at'] = time();
            return $store;
        });
    }

    /** Signale qu'une maquette complète vient d'être produite. */
    public static function countMockup(): void
    {
        Store::mutate(self::usagePath(), static function (array $store): array {
            $store['mockups'] = (int) ($store['mockups'] ?? 0) + 1;
            $store['updated_at'] = time();
            return $store;
        });
    }

    /** Dépense cumulée estimée depuis la mise en service. */
    public static function spentSoFar(): ?float
    {
        $usage = Store::read(self::usagePath());
        $price = self::PRICING[(string) Config::get('claude.model', '')] ?? null;
        if ($price === null || ($usage['calls'] ?? 0) < 1) {
            return null;
        }
        return (int) ($usage['input'] ?? 0) / 1e6 * $price[0]
            + (int) ($usage['output'] ?? 0) / 1e6 * $price[1];
    }

    // ------------------------------------------------- Rafraîchissement

    /**
     * Interroge /v1/models et met le cache à jour.
     * @return array{ok:bool,error:string,count:int}
     */
    public static function refresh(): array
    {
        if (!Claude::isConfigured()) {
            return ['ok' => false, 'error' => 'Renseignez la clé API Claude pour récupérer la liste des modèles.', 'count' => 0];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'L\'extension cURL de PHP est requise.', 'count' => 0];
        }

        $headers = [
            'x-api-key: ' . trim((string) Config::get('claude.api_key', '')),
            'anthropic-version: ' . self::VERSION,
        ];

        $models = [];
        $after = null;
        // La réponse est paginée : on suit les pages jusqu'à has_more = false.
        for ($page = 0; $page < 10; $page++) {
            $url = self::ENDPOINT . '?limit=100' . ($after !== null ? '&after_id=' . rawurlencode($after) : '');
            $response = Http::json($url, null, $headers, 25);

            if (!$response['ok']) {
                $message = (string) ($response['data']['error']['message'] ?? '');
                return [
                    'ok' => false,
                    'count' => 0,
                    'error' => $response['status'] === 401
                        ? 'Clé API refusée (401).'
                        : 'L\'API a répondu ' . $response['status'] . ($message !== '' ? ' : ' . $message : ''),
                ];
            }

            foreach ($response['data']['data'] ?? [] as $model) {
                if (!empty($model['id'])) {
                    $models[] = [
                        'id' => (string) $model['id'],
                        'display_name' => (string) ($model['display_name'] ?? $model['id']),
                        'max_input_tokens' => $model['max_input_tokens'] ?? null,
                        'max_tokens' => $model['max_tokens'] ?? null,
                        'capabilities' => $model['capabilities'] ?? null,
                    ];
                }
            }

            if (empty($response['data']['has_more'])) {
                break;
            }
            $after = (string) ($response['data']['last_id'] ?? '');
            if ($after === '') {
                break;
            }
        }

        Store::write(self::cachePath(), [
            'fetched_at' => time(),
            'attempted_at' => time(),
            'models' => $models,
        ]);
        return ['ok' => true, 'error' => '', 'count' => count($models)];
    }

    /**
     * Rafraîchit silencieusement si le cache a plus de 24 heures.
     * L'horodatage de tentative évite de relancer un appel à chaque affichage
     * lorsque l'API est indisponible.
     */
    public static function refreshIfStale(): void
    {
        $cached = Store::read(self::cachePath());
        $now = time();
        if ($now - (int) ($cached['fetched_at'] ?? 0) <= self::CACHE_TTL) {
            return;
        }
        if ($now - (int) ($cached['attempted_at'] ?? 0) < 900 || !Claude::isConfigured()) {
            return;
        }

        $cached['attempted_at'] = $now;
        Store::write(self::cachePath(), $cached);
        self::refresh();
    }

    public static function fetchedAt(): int
    {
        return (int) (Store::read(self::cachePath())['fetched_at'] ?? 0);
    }

    /** Formate un coût en dollars pour l'affichage. */
    public static function formatCost(?float $cost): string
    {
        if ($cost === null) {
            return 'tarif inconnu';
        }
        if ($cost < 0.01) {
            return '< 0,01 $';
        }
        return number_format($cost, 2, ',', ' ') . ' $';
    }
}
