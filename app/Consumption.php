<?php
declare(strict_types=1);

namespace App;

/**
 * Relevé de consommation, appel par appel.
 *
 * Le coût ne peut plus se recalculer après coup : depuis qu'un modèle se règle
 * par étape et qu'un second fournisseur existe, additionner tous les jetons
 * pour les multiplier par le tarif du modèle actuellement configuré donne un
 * chiffre faux. Chaque appel est donc chiffré au moment où il a lieu, au tarif
 * du modèle qui a réellement répondu, et la ligne est conservée.
 *
 * Un modèle sans tarif relevé — les grilles DeepSeek ne le sont pas — écrit une
 * ligne au coût nul et marquée comme telle. Les totaux disent alors combien
 * d'appels ils ne savent pas chiffrer, plutôt que de faire passer un total
 * partiel pour un total.
 */
final class Consumption
{
    public static function path(): string
    {
        return DATA_DIR . '/consommation.jsonl';
    }

    /**
     * Contexte du prochain appel : prospect, version, étape.
     *
     * Le porter dans une variable de classe évite de le faire traverser quatre
     * couches de signatures pour un besoin de journalisation.
     */
    private static array $contexte = [];

    public static function setContext(array $contexte): void
    {
        self::$contexte = $contexte;
    }

    public static function clearContext(): void
    {
        self::$contexte = [];
    }

    /** Enregistre un appel abouti. */
    public static function record(string $provider, string $model, array $usage): void
    {
        $entree = (int) ($usage['input_tokens'] ?? 0)
            + (int) ($usage['cache_creation_input_tokens'] ?? 0)
            + (int) ($usage['cache_read_input_tokens'] ?? 0);
        $sortie = (int) ($usage['output_tokens'] ?? 0);
        if ($entree === 0 && $sortie === 0) {
            return;
        }

        // Le tarif est celui de l'instant : certains modèles coûtent le double
        // en heure pleine, et le prix d'un appel ne se relit pas plus tard.
        $maintenant = time();
        $prix = Models::priceOf($model, $maintenant);
        Store::append(self::path(), [
            'ts' => $maintenant,
            'prospect' => (string) (self::$contexte['prospect'] ?? ''),
            'version' => (string) (self::$contexte['version'] ?? ''),
            'etape' => (string) (self::$contexte['etape'] ?? ''),
            'provider' => $provider,
            'model' => $model,
            'in' => $entree,
            'out' => $sortie,
            'usd' => $prix === null ? null : round($entree / 1e6 * $prix[0] + $sortie / 1e6 * $prix[1], 6),
        ]);
    }

    /**
     * Lignes du relevé, de la plus ancienne à la plus récente.
     *
     * Store::tail remonte des plus récentes : on rétablit l'ordre, parce que
     * l'ordre chronologique est celui dans lequel on lit une consommation.
     *
     * @return array<int,array>
     */
    public static function lines(?string $prospectId = null, int $limit = 5000): array
    {
        $lignes = Store::tail(self::path(), $limit, $prospectId === null
            ? null
            : static fn (array $row): bool => (string) ($row['prospect'] ?? '') === $prospectId);
        return array_reverse($lignes);
    }

    /**
     * Cumul d'un ensemble de lignes.
     *
     * Quand aucune ligne n'est chiffrée, le total vaut null et non zéro : un
     * « 0,00 $ » se lirait « gratuit », ce qui serait le contraire de la
     * vérité — on ne connaît simplement pas le tarif.
     *
     * @return array{in:int,out:int,usd:?float,appels:int,sans_tarif:int,modeles:array}
     */
    public static function sum(array $lignes): array
    {
        $total = ['in' => 0, 'out' => 0, 'usd' => 0.0, 'appels' => 0, 'sans_tarif' => 0, 'modeles' => []];
        $chiffres = 0;
        foreach ($lignes as $ligne) {
            $total['in'] += (int) ($ligne['in'] ?? 0);
            $total['out'] += (int) ($ligne['out'] ?? 0);
            $total['appels']++;
            if (($ligne['usd'] ?? null) === null) {
                $total['sans_tarif']++;
            } else {
                $total['usd'] += (float) $ligne['usd'];
                $chiffres++;
            }
            $modele = (string) ($ligne['model'] ?? '');
            if ($modele !== '' && !in_array($modele, $total['modeles'], true)) {
                $total['modeles'][] = $modele;
            }
        }
        if ($chiffres === 0) {
            $total['usd'] = null;
        }
        return $total;
    }

    /**
     * Consommation d'un prospect, groupée par version de maquette.
     *
     * C'est ce qui permet de comparer : la même entreprise, le même site,
     * générée deux fois avec des modèles différents, deux totaux côte à côte.
     *
     * @return array<string,array> version => cumul + détail par étape
     */
    public static function byVersion(string $prospectId): array
    {
        $versions = [];
        foreach (self::lines($prospectId) as $ligne) {
            $version = (string) ($ligne['version'] ?? '');
            if ($version === '') {
                $version = 'hors version';
            }
            $versions[$version]['lignes'][] = $ligne;
        }

        foreach ($versions as $version => $bloc) {
            $versions[$version] = self::sum($bloc['lignes']) + [
                'lignes' => $bloc['lignes'],
                'debut' => (int) ($bloc['lignes'][0]['ts'] ?? 0),
            ];
        }

        // De la version la plus récente à la plus ancienne ; ce qui n'est pas
        // une version numérotée — les comparaisons — passe à la fin.
        uksort($versions, static function (string $a, string $b): int {
            $rangA = preg_match('/^v(\d+)$/', $a, $m) ? (int) $m[1] : -1;
            $rangB = preg_match('/^v(\d+)$/', $b, $m) ? (int) $m[1] : -1;
            return $rangB <=> $rangA;
        });
        return $versions;
    }

    // ------------------------------------------------------------ Affichage

    /** Taux de conversion saisi dans les Réglages, ou null s'il ne l'est pas. */
    public static function eurRate(): ?float
    {
        $taux = (float) Config::get('billing.eur_rate', 0);
        return $taux > 0 ? $taux : null;
    }

    /**
     * Montant affiché.
     *
     * Les API facturent en dollars : c'est le montant sûr, et il est donné en
     * premier. L'euro n'apparaît que si un taux a été saisi, parce qu'un taux
     * inventé rendrait faux précisément ce qu'on cherche à rendre juste.
     */
    public static function money(?float $usd, bool $court = false): string
    {
        if ($usd === null) {
            return 'tarif non relevé';
        }
        // Trois décimales sous le dollar : un appel coûte souvent quelques
        // millièmes, et « < 0,01 $ » masquerait justement ce qu'on compare.
        $dollars = $usd < 0.001 && $usd > 0
            ? '< 0,001 $'
            : number_format($usd, $usd < 1 ? 3 : 2, ',', ' ') . ' $';

        $taux = self::eurRate();
        if ($taux === null) {
            return $dollars;
        }
        $euros = $usd * $taux;
        $euros = $euros < 0.001 && $euros > 0
            ? '< 0,001 €'
            : number_format($euros, $euros < 1 ? 3 : 2, ',', ' ') . ' €';

        return $court ? $euros : $dollars . ' · ' . $euros;
    }

    public static function tokens(int $n): string
    {
        return number_format($n, 0, ',', ' ');
    }
}
