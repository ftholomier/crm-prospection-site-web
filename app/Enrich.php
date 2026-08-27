<?php
declare(strict_types=1);

namespace App;

/**
 * Enrichissement des coordonnées du prospect, en cascade.
 *
 * L'ordre compte : une base entreprise ne connaît ni l'adresse email ni l'URL
 * du site. C'est donc le site lui-même qui fournit email, téléphone et SIREN,
 * et la base entreprise qui complète ensuite l'identité (raison sociale exacte,
 * dirigeant, effectif, activité) à partir de ce SIREN.
 *
 * Modes : « manual » (rien d'automatique), « site » (scraping seul),
 * « site_company » (scraping puis base entreprise).
 */
final class Enrich
{
    public static function modes(): array
    {
        return [
            'manual' => 'Saisie manuelle uniquement',
            'site' => 'Depuis le site (email, téléphone, société)',
            'site_company' => 'Site + base entreprise (dirigeant, SIREN, effectif)',
        ];
    }

    /**
     * Applique l'enrichissement à un prospect à partir de l'analyse du site.
     * Ne remplace jamais une valeur saisie à la main.
     */
    public static function apply(array $prospect, array $analysis): array
    {
        $mode = (string) Config::get('enrichment.mode', 'site');
        $report = ['mode' => $mode, 'sources' => [], 'at' => time()];

        if ($mode === 'manual') {
            $prospect['enrichment'] = $report + ['note' => 'Mode manuel : aucune donnée collectée automatiquement.'];
            return $prospect;
        }

        $contact = $analysis['contact'] ?? [];

        $prospect = self::fillIfEmpty($prospect, 'company', (string) ($analysis['company'] ?? ''));
        $prospect = self::fillIfEmpty($prospect, 'email', (string) ($contact['email'] ?? ''));
        $prospect = self::fillIfEmpty($prospect, 'phone', (string) ($contact['phone'] ?? ''));
        $prospect = self::fillIfEmpty($prospect, 'siren', (string) ($contact['siren'] ?? ''));
        $prospect = self::fillIfEmpty($prospect, 'city', (string) ($contact['city'] ?? ''));
        $prospect['candidate_emails'] = $contact['emails'] ?? [];

        if (!empty($contact['emails'])) {
            $report['sources'][] = 'Site : ' . count($contact['emails']) . ' adresse(s) trouvée(s)';
        } else {
            $report['sources'][] = 'Site : aucune adresse email trouvée';
        }

        if ($mode === 'site_company') {
            $company = self::lookupCompany(
                (string) ($prospect['siren'] ?? ''),
                (string) ($prospect['company'] ?? ''),
                (string) ($prospect['city'] ?? '')
            );
            if ($company['ok']) {
                $prospect = self::fillIfEmpty($prospect, 'company', (string) ($company['data']['company'] ?? ''));
                $prospect = self::fillIfEmpty($prospect, 'siren', (string) ($company['data']['siren'] ?? ''));
                $prospect = self::fillIfEmpty($prospect, 'city', (string) ($company['data']['city'] ?? ''));
                $prospect = self::fillIfEmpty($prospect, 'sector', (string) ($company['data']['sector'] ?? ''));
                if (trim((string) ($prospect['first_name'] ?? '')) === '' && !empty($company['data']['director'])) {
                    [$first, $last] = Util::splitName((string) $company['data']['director']);
                    $prospect['first_name'] = $first;
                    $prospect['last_name'] = $last;
                }
                $report['company'] = $company['data'];
                $report['sources'][] = 'Base entreprise : ' . ($company['data']['company'] ?? 'fiche trouvée');
            } else {
                $report['sources'][] = 'Base entreprise : ' . $company['error'];
            }
        }

        $prospect['enrichment'] = $report;
        return $prospect;
    }

    /** N'écrit que si le champ est encore vide, pour protéger la saisie manuelle. */
    private static function fillIfEmpty(array $prospect, string $field, string $value): array
    {
        $value = trim($value);
        if ($value !== '' && trim((string) ($prospect[$field] ?? '')) === '') {
            $prospect[$field] = $value;
        }
        return $prospect;
    }

    /**
     * Interroge l'API Pappers, par SIREN si connu, sinon par raison sociale.
     * @return array{ok:bool,error:string,data:array}
     */
    public static function lookupCompany(string $siren, string $name = '', string $city = ''): array
    {
        $key = trim((string) Config::get('enrichment.pappers_api_key', ''));
        if ($key === '') {
            return ['ok' => false, 'error' => 'aucune clé API renseignée', 'data' => []];
        }

        if (preg_match('/^\d{9}$/', $siren)) {
            $url = 'https://api.pappers.fr/v2/entreprise?api_token=' . rawurlencode($key) . '&siren=' . $siren;
            $response = Http::json($url, null, [], 20);
            if ($response['ok'] && !empty($response['data'])) {
                return ['ok' => true, 'error' => '', 'data' => self::mapCompany($response['data'])];
            }
        }

        if (trim($name) !== '') {
            $query = trim($name . ' ' . $city);
            $url = 'https://api.pappers.fr/v2/recherche?api_token=' . rawurlencode($key)
                . '&q=' . rawurlencode($query) . '&par_page=1';
            $response = Http::json($url, null, [], 20);
            $first = $response['data']['resultats'][0] ?? null;
            if ($response['ok'] && is_array($first)) {
                return ['ok' => true, 'error' => '', 'data' => self::mapCompany($first)];
            }
        }

        return ['ok' => false, 'error' => 'aucune correspondance trouvée', 'data' => []];
    }

    /** Normalise la réponse de l'API vers les champs utilisés par l'application. */
    private static function mapCompany(array $raw): array
    {
        $siege = $raw['siege'] ?? [];
        $director = '';
        foreach ($raw['representants'] ?? ($raw['dirigeants'] ?? []) as $person) {
            $first = trim((string) ($person['prenom'] ?? ($person['prenom_usuel'] ?? '')));
            $last = trim((string) ($person['nom'] ?? ''));
            if ($first !== '' || $last !== '') {
                $director = trim($first . ' ' . $last);
                break;
            }
        }

        return array_filter([
            'company' => (string) ($raw['nom_entreprise'] ?? ($raw['denomination'] ?? '')),
            'siren' => (string) ($raw['siren'] ?? ''),
            'city' => (string) ($siege['ville'] ?? ($raw['ville'] ?? '')),
            'postcode' => (string) ($siege['code_postal'] ?? ''),
            'sector' => (string) ($raw['libelle_code_naf'] ?? ($raw['activite_principale'] ?? '')),
            'director' => $director,
            'headcount' => (string) ($raw['effectif'] ?? ''),
            'created' => (string) ($raw['date_creation'] ?? ''),
            'legal_form' => (string) ($raw['forme_juridique'] ?? ''),
        ], static fn ($value): bool => $value !== '');
    }
}
