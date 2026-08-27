<?php
declare(strict_types=1);

namespace App;

/**
 * Score de vétusté d'un site, de 0 (site moderne) à 100 (site totalement dépassé).
 * Chaque contrôle produit un constat lisible, réutilisable tel quel dans l'email
 * de prospection : c'est l'argumentaire commercial, pas seulement une note.
 */
final class Audit
{
    /**
     * Contrôles appliqués. « weight » est le nombre de points de vétusté ajoutés
     * quand le défaut est constaté.
     */
    public static function run(array $analysis): array
    {
        $html = (string) ($analysis['raw']['home_html'] ?? '');
        $css = implode("\n", $analysis['raw']['css'] ?? []);
        $findings = [];
        $score = 0;

        foreach (self::checks() as $key => $check) {
            $failed = $check['test']($analysis, $html, $css);
            if ($failed) {
                $score += $check['weight'];
                $findings[] = [
                    'key' => $key,
                    'label' => $check['label'],
                    'detail' => is_string($failed) ? $failed : $check['detail'],
                    'weight' => $check['weight'],
                    'severity' => $check['weight'] >= 12 ? 'critique' : ($check['weight'] >= 7 ? 'important' : 'mineur'),
                ];
            }
        }

        $score = Util::clamp((int) round($score), 0, 100);

        return [
            'score' => $score,
            'level' => self::level($score),
            'findings' => $findings,
            'checked_at' => time(),
            'summary' => self::summary($score, $findings),
        ];
    }

    /** @return array<string,array{label:string,detail:string,weight:int,test:callable}> */
    private static function checks(): array
    {
        return [
            'no_viewport' => [
                'label' => 'Site non responsive',
                'detail' => "Aucune balise viewport : le site ne s'adapte pas aux mobiles, qui représentent aujourd'hui la majorité des visites.",
                'weight' => 22,
                'test' => static fn (array $a, string $html): bool => !preg_match('/<meta[^>]+name=["\']?viewport/i', $html),
            ],
            'no_media_queries' => [
                'label' => 'Aucune adaptation mobile dans le CSS',
                'detail' => "Le CSS ne contient aucune règle @media : la mise en page est figée quelle que soit la taille d'écran.",
                'weight' => 12,
                'test' => static function (array $a, string $html, string $css): bool {
                    $source = $html . $css;
                    return $source !== '' && !str_contains($source, '@media');
                },
            ],
            'no_https' => [
                'label' => 'Site non sécurisé (HTTP)',
                'detail' => "Le site n'est pas en HTTPS : les navigateurs affichent « Non sécurisé » aux visiteurs et Google le pénalise.",
                'weight' => 15,
                'test' => static fn (array $a): bool => empty($a['http']['https']),
            ],
            'legacy_doctype' => [
                'label' => 'Code HTML d\'une génération précédente',
                'detail' => 'Le site utilise un doctype HTML 4 ou XHTML, abandonné depuis plus de dix ans.',
                'weight' => 12,
                'test' => static fn (array $a, string $html): bool => (bool) preg_match('/<!DOCTYPE[^>]*(HTML 4|XHTML)/i', $html),
            ],
            'table_layout' => [
                'label' => 'Mise en page à base de tableaux',
                'detail' => "La structure repose sur des balises <table> ou des attributs abandonnés (bgcolor, <font>, <center>).",
                'weight' => 14,
                'test' => static function (array $a, string $html): bool {
                    if (preg_match('/<(font|center)\b|bgcolor=/i', $html)) {
                        return true;
                    }
                    return preg_match_all('/<table\b/i', $html) >= 3;
                },
            ],
            'no_modern_css' => [
                'label' => 'Aucune technique de mise en page moderne',
                'detail' => "Ni Flexbox ni CSS Grid : la mise en page utilise des méthodes qui datent d'avant 2015.",
                'weight' => 8,
                'test' => static function (array $a, string $html, string $css): bool {
                    $source = $html . $css;
                    return $source !== '' && !preg_match('/display\s*:\s*(flex|grid)/i', $source);
                },
            ],
            'flash' => [
                'label' => 'Technologies mortes détectées',
                'detail' => 'Le site contient du Flash ou une applet Java, technologies qu\'aucun navigateur ne lit plus.',
                'weight' => 20,
                'test' => static fn (array $a, string $html): bool => (bool) preg_match('/\.swf\b|<applet|application\/x-shockwave/i', $html),
            ],
            'old_jquery' => [
                'label' => 'Bibliothèque JavaScript obsolète',
                'detail' => 'Une version ancienne de jQuery est chargée, avec les failles de sécurité connues associées.',
                'weight' => 6,
                'test' => static function (array $a, string $html) {
                    if (preg_match('/jquery[.\-]?(\d+)\.(\d+)/i', $html, $m)) {
                        $major = (int) $m[1];
                        if ($major < 3) {
                            return 'jQuery ' . $m[1] . '.' . $m[2] . ' est chargé : cette version ne reçoit plus de correctifs de sécurité.';
                        }
                    }
                    return false;
                },
            ],
            'stale_copyright' => [
                'label' => 'Pied de page daté',
                'detail' => 'La mention de copyright n\'a pas été mise à jour, signe visible aux visiteurs que le site est laissé à l\'abandon.',
                'weight' => 9,
                'test' => static function (array $a, string $html) {
                    if (preg_match_all('/(?:©|&copy;|copyright)[^0-9]{0,20}(\d{4})/i', $html, $m)) {
                        $latest = max(array_map('intval', $m[1]));
                        $currentYear = (int) date('Y');
                        if ($latest > 1990 && $latest < $currentYear - 1) {
                            return 'Le pied de page affiche encore « © ' . $latest .' », soit ' . ($currentYear - $latest) . ' ans de retard.';
                        }
                    }
                    return false;
                },
            ],
            'no_description' => [
                'label' => 'Référencement incomplet',
                'detail' => "Aucune méta-description : Google affiche un extrait choisi au hasard dans les résultats de recherche.",
                'weight' => 7,
                'test' => static fn (array $a): bool => trim((string) ($a['description'] ?? '')) === '',
            ],
            'no_og' => [
                'label' => 'Partages sur les réseaux sans visuel',
                'detail' => "Les balises Open Graph sont absentes : quand quelqu'un partage le site, aucun aperçu ne s'affiche.",
                'weight' => 4,
                'test' => static fn (array $a, string $html): bool => !preg_match('/property=["\']og:(image|title)/i', $html),
            ],
            'heavy_images' => [
                'label' => 'Images non optimisées',
                'detail' => "Aucune image au format moderne (WebP/AVIF) et pas de chargement différé : les pages sont lourdes et lentes.",
                'weight' => 6,
                'test' => static function (array $a): bool {
                    $images = $a['images'] ?? [];
                    if (count($images) < 3) {
                        return false;
                    }
                    foreach ($images as $image) {
                        if (!empty($image['modern']) || !empty($image['lazy'])) {
                            return false;
                        }
                    }
                    return true;
                },
            ],
            'slow' => [
                'label' => 'Temps de réponse dégradé',
                'detail' => 'Le serveur met plus de deux secondes à répondre, ce qui fait fuir une partie des visiteurs avant affichage.',
                'weight' => 8,
                'test' => static function (array $a) {
                    $elapsed = (float) ($a['http']['elapsed'] ?? 0);
                    return $elapsed > 2.0
                        ? 'La page d\'accueil met ' . number_format($elapsed, 1, ',', ' ') . ' seconde(s) à répondre.'
                        : false;
                },
            ],
            'heavy_page' => [
                'label' => 'Page d\'accueil trop lourde',
                'detail' => 'Le poids du HTML seul dépasse 500 Ko, signe de code accumulé au fil des années.',
                'weight' => 4,
                'test' => static fn (array $a): bool => (int) ($a['http']['size'] ?? 0) > 512000,
            ],
            'no_favicon' => [
                'label' => 'Aucune icône de navigateur',
                'detail' => "Le site n'a pas de favicon : il apparaît sans identité dans les onglets et les favoris.",
                'weight' => 3,
                'test' => static fn (array $a, string $html): bool => !preg_match('/rel=["\'][^"\']*icon/i', $html),
            ],
            'no_social' => [
                'label' => 'Aucun réseau social relié',
                'detail' => "Le site ne renvoie vers aucun réseau social, signe d'une présence en ligne figée.",
                'weight' => 3,
                'test' => static fn (array $a): bool => empty($a['social']),
            ],
        ];
    }

    private static function level(int $score): string
    {
        return match (true) {
            $score >= 70 => 'Site très daté',
            $score >= 45 => 'Site à refondre',
            $score >= 25 => 'Site vieillissant',
            default => 'Site correct',
        };
    }

    /** Phrase de synthèse réutilisable dans l'email. */
    private static function summary(int $score, array $findings): string
    {
        if ($findings === []) {
            return "Aucun défaut majeur détecté : ce site n'est probablement pas une bonne cible.";
        }
        $top = array_slice(array_column($findings, 'label'), 0, 3);
        return self::level($score) . ' (' . $score . '/100). Principaux constats : ' . mb_strtolower(implode(', ', $top)) . '.';
    }

    /** Les trois arguments les plus forts, formatés pour l'insertion dans un email. */
    public static function topArguments(array $audit, int $limit = 3): array
    {
        $findings = $audit['findings'] ?? [];
        usort($findings, static fn (array $a, array $b): int => ($b['weight'] ?? 0) <=> ($a['weight'] ?? 0));
        return array_slice(array_column($findings, 'detail'), 0, $limit);
    }
}
