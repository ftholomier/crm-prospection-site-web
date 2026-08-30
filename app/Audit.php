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
                    // Ce que la refonte corrige. Un constat sans réponse est un
                    // reproche ; avec sa réponse, c'est un argument.
                    'fix' => (string) ($check['fix'] ?? ''),
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
                'fix' => "Le nouveau site est conçu pour le téléphone d'abord : lisible sans zoomer, boutons atteignables au pouce, menu qui tient dans l'écran.",
                'detail' => "Aucune balise viewport : le site ne s'adapte pas aux mobiles, qui représentent aujourd'hui la majorité des visites.",
                'weight' => 22,
                'test' => static fn (array $a, string $html): bool => !preg_match('/<meta[^>]+name=["\']?viewport/i', $html),
            ],
            'no_media_queries' => [
                'label' => 'Aucune adaptation mobile dans le CSS',
                'fix' => "La mise en page se réorganise à chaque largeur d'écran — téléphone, tablette, ordinateur — au lieu d'être figée sur une seule.",
                'detail' => "Le CSS ne contient aucune règle @media : la mise en page est figée quelle que soit la taille d'écran.",
                'weight' => 12,
                'test' => static function (array $a, string $html, string $css): bool {
                    $source = $html . $css;
                    return $source !== '' && !str_contains($source, '@media');
                },
            ],
            'no_https' => [
                'label' => 'Site non sécurisé (HTTP)',
                'fix' => "Le nouveau site est servi en HTTPS, certificat compris et renouvelé automatiquement : plus d'avertissement « non sécurisé » dans le navigateur.",
                'detail' => "Le site n'est pas en HTTPS : les navigateurs affichent « Non sécurisé » aux visiteurs et Google le pénalise.",
                'weight' => 15,
                'test' => static fn (array $a): bool => empty($a['http']['https']),
            ],
            'legacy_doctype' => [
                'label' => 'Code HTML d\'une génération précédente',
                'fix' => 'Le code est réécrit aux standards actuels : plus rapide à afficher, compris par tous les navigateurs, et lisible par Google.',
                'detail' => 'Le site utilise un doctype HTML 4 ou XHTML, abandonné depuis plus de dix ans.',
                'weight' => 12,
                'test' => static fn (array $a, string $html): bool => (bool) preg_match('/<!DOCTYPE[^>]*(HTML 4|XHTML)/i', $html),
            ],
            'table_layout' => [
                'label' => 'Mise en page à base de tableaux',
                'fix' => "La mise en page est reconstruite avec les techniques d'aujourd'hui : elle s'adapte, se lit à haute voix par les lecteurs d'écran, et se corrige sans tout casser.",
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
                'fix' => "Grilles et espacements calculés : les blocs s'alignent, respirent, et gardent leurs proportions quelle que soit la longueur de vos textes.",
                'detail' => "Ni Flexbox ni CSS Grid : la mise en page utilise des méthodes qui datent d'avant 2015.",
                'weight' => 8,
                'test' => static function (array $a, string $html, string $css): bool {
                    $source = $html . $css;
                    return $source !== '' && !preg_match('/display\s*:\s*(flex|grid)/i', $source);
                },
            ],
            'flash' => [
                'label' => 'Technologies mortes détectées',
                'fix' => "Les technologies abandonnées disparaissent. Ce qu'elles affichaient est refait avec des moyens que tous les navigateurs savent encore lire.",
                'detail' => 'Le site contient du Flash ou une applet Java, technologies qu\'aucun navigateur ne lit plus.',
                'weight' => 20,
                'test' => static fn (array $a, string $html): bool => (bool) preg_match('/\.swf\b|<applet|application\/x-shockwave/i', $html),
            ],
            'old_jquery' => [
                'label' => 'Bibliothèque JavaScript obsolète',
                'fix' => 'Les bibliothèques obsolètes sont retirées : moins de code à télécharger, plus de failles connues, et un site qui démarre plus vite.',
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
                'fix' => "Les mentions se mettent à jour toutes seules. Un visiteur ne peut plus déduire de votre pied de page que le site est à l'abandon.",
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
                'fix' => "Chaque page reçoit son titre et sa description : c'est la phrase que Google affiche sous votre nom dans ses résultats.",
                'detail' => "Aucune méta-description : Google affiche un extrait choisi au hasard dans les résultats de recherche.",
                'weight' => 7,
                'test' => static fn (array $a): bool => trim((string) ($a['description'] ?? '')) === '',
            ],
            'no_og' => [
                'label' => 'Partages sur les réseaux sans visuel',
                'fix' => "Un visuel est associé à chaque page : partagée sur Facebook, LinkedIn ou WhatsApp, elle s'affiche avec une image et un titre au lieu d'un lien nu.",
                'detail' => "Les balises Open Graph sont absentes : quand quelqu'un partage le site, aucun aperçu ne s'affiche.",
                'weight' => 4,
                'test' => static fn (array $a, string $html): bool => !preg_match('/property=["\']og:(image|title)/i', $html),
            ],
            'heavy_images' => [
                'label' => 'Images non optimisées',
                'fix' => 'Vos photos sont recompressées et servies au bon format : même qualité perçue, poids divisé, page qui apparaît immédiatement.',
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
                'fix' => "L'hébergement et le code sont pensés pour la vitesse. Un visiteur qui attend trois secondes est un visiteur qui part.",
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
                'fix' => "La page d'accueil est allégée : elle s'affiche vite même en 4G médiocre, ce qui est la situation réelle de la plupart de vos visiteurs.",
                'detail' => 'Le poids du HTML seul dépasse 500 Ko, signe de code accumulé au fil des années.',
                'weight' => 4,
                'test' => static fn (array $a): bool => (int) ($a['http']['size'] ?? 0) > 512000,
            ],
            'no_favicon' => [
                'label' => 'Aucune icône de navigateur',
                'fix' => "Une icône représente votre entreprise dans l'onglet, les favoris et l'écran d'accueil du téléphone.",
                'detail' => "Le site n'a pas de favicon : il apparaît sans identité dans les onglets et les favoris.",
                'weight' => 3,
                'test' => static fn (array $a, string $html): bool => !preg_match('/rel=["\'][^"\']*icon/i', $html),
            ],
            'no_social' => [
                'label' => 'Aucun réseau social relié',
                'fix' => "Vos pages sociales sont reliées au site, et le site aux pages : les visiteurs circulent de l'un à l'autre au lieu de vous chercher.",
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
