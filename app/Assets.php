<?php
declare(strict_types=1);

namespace App;

/**
 * Récupération des actifs visuels du prospect : logo, favicon et photos.
 *
 * Une maquette qui montre les vraies photos du prospect ne se compare pas à
 * un gabarit, elle se projette.
 *
 * Deux modes, au choix dans les réglages :
 *  - « copie »  : les fichiers sont recopiés chez nous. Coût : quelques Mo par
 *                 prospect. Bénéfice : la maquette tient même si le site
 *                 d'origine interdit l'affichage depuis un autre domaine, part
 *                 en refonte ou change ses adresses — c'est-à-dire précisément
 *                 ce qui arrive quand le prospect commence à bouger.
 *  - « liens »  : rien n'est stocké, on garde les adresses distantes. On lit
 *                 tout de même le début de chaque fichier pour connaître ses
 *                 dimensions réelles et vérifier qu'il s'affiche bien depuis
 *                 notre domaine ; sans cela le cadrage des photos ne serait
 *                 qu'une supposition.
 */
final class Assets
{
    private const MAX_PHOTOS = 12;
    private const MAX_FICHIER = 3145728;   // 3 Mo par image
    private const MAX_TOTAL = 26214400;    // 25 Mo par prospect
    private const MAX_EDGE = 1800;         // au-delà, inutile pour une maquette
    private const ENTETE = 65536;          // suffit pour lire les dimensions

    public const MODE_COPIE = 'copie';
    public const MODE_LIENS = 'liens';

    public static function mode(): string
    {
        return Config::get('design.assets_mode') === self::MODE_LIENS
            ? self::MODE_LIENS
            : self::MODE_COPIE;
    }

    public static function dir(string $prospectId): string
    {
        $dir = Mockup::dir($prospectId) . '/assets';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public static function cataloguePath(string $prospectId): string
    {
        return self::dir($prospectId) . '/catalogue.json';
    }

    public static function catalogue(string $prospectId): array
    {
        return Store::read(self::cataloguePath($prospectId), ['logo' => null, 'favicon' => null, 'photos' => []]);
    }

    public static function has(string $prospectId): bool
    {
        $c = self::catalogue($prospectId);
        return ($c['logo'] ?? null) !== null || ($c['favicon'] ?? null) !== null || ($c['photos'] ?? []) !== [];
    }

    /** Chemin d'un fichier du catalogue, si le nom est connu. */
    public static function pathOf(string $prospectId, string $file): ?string
    {
        $file = basename($file);
        if (!preg_match('/^[a-z0-9._-]+$/i', $file) || $file === 'catalogue.json') {
            return null;
        }
        $path = self::dir($prospectId) . '/' . $file;
        return is_file($path) ? $path : null;
    }

    public static function mediaType(string $path): string
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'svg') {
            return 'image/svg+xml';
        }
        return Image::probeFile($path)['media_type'] ?? 'application/octet-stream';
    }

    /**
     * Copie les actifs du site analysé.
     * @return array{ok:bool,error:string,catalogue:array}
     */
    public static function collect(string $prospectId, array $analysis, ?callable $notify = null): array
    {
        $say = static function (string $message, string $state = 'running') use ($notify): void {
            if ($notify !== null) {
                $notify($message, $state);
            }
        };

        self::clear($prospectId);
        $mode = self::mode();
        $catalogue = ['logo' => null, 'favicon' => null, 'photos' => [], 'mode' => $mode, 'at' => time()];
        $total = 0;

        $logo = trim((string) ($analysis['logo'] ?? ''));
        if ($logo !== '') {
            $say('Récupération du logo');
            $stored = self::store($prospectId, $logo, 'logo', $total);
            if ($stored !== null) {
                $catalogue['logo'] = $stored;
                $total += $stored['poids'];
                $say('Logo récupéré (' . $stored['largeur'] . '×' . $stored['hauteur'] . ')', 'done');
            } else {
                $say('Logo inexploitable, ignoré', 'warn');
            }
        }

        $favicon = trim((string) ($analysis['favicon'] ?? ''));
        if ($favicon !== '') {
            $stored = self::store($prospectId, $favicon, 'favicon', $total);
            if ($stored !== null) {
                $catalogue['favicon'] = $stored;
                $total += $stored['poids'];
                $say('Favicon récupérée', 'done');
            }
        }

        $sources = self::selectPhotos($analysis);
        if ($sources !== []) {
            $say('Récupération de ' . count($sources) . ' photo(s)');
        }
        $rang = 0;
        foreach ($sources as $image) {
            if (count($catalogue['photos']) >= self::MAX_PHOTOS || $total >= self::MAX_TOTAL) {
                break;
            }
            $stored = self::store($prospectId, (string) $image['url'], 'photo-' . sprintf('%02d', ++$rang), $total);
            if ($stored === null) {
                continue;
            }
            $stored['alt'] = (string) ($image['alt'] ?? '');
            $catalogue['photos'][] = $stored;
            $total += $stored['poids'];
        }

        Store::write(self::cataloguePath($prospectId), $catalogue);

        $nb = count($catalogue['photos']);
        $poids = $mode === self::MODE_COPIE
            ? ' (' . Scraper::humanSize($total) . ' copiés)'
            : ' (liens conservés, rien de stocké)';
        $say(
            $nb > 0
                ? $nb . ' photo(s) retenue(s)' . $poids
                : 'Aucune photo exploitable retenue',
            $nb > 0 ? 'done' : 'warn'
        );

        return ['ok' => true, 'error' => '', 'catalogue' => $catalogue];
    }

    /**
     * Retient les photos utiles : les grandes d'abord, sans les pictogrammes
     * ni les vignettes, et sans les doublons de miniature.
     */
    private static function selectPhotos(array $analysis): array
    {
        $retenues = [];
        $vues = [];
        foreach ($analysis['images'] ?? [] as $image) {
            $url = (string) ($image['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $nom = strtolower(basename((string) parse_url($url, PHP_URL_PATH)));
            // Les logos, icônes, pictogrammes et pixels de suivi ne servent pas
            // de photographie ; les miniatures font doublon avec leur original.
            if (preg_match('/(logo|icon|favicon|sprite|pixel|spacer|placeholder|avatar|-mini|_mini|thumb|vignette)/i', $nom)) {
                continue;
            }
            if (!preg_match('/\.(jpe?g|png|webp|avif)(\?|$)/i', $url)) {
                continue;
            }
            $cle = preg_replace('/[^a-z0-9]/i', '', $nom) ?? $nom;
            if (isset($vues[$cle])) {
                continue;
            }
            $vues[$cle] = true;
            $retenues[] = $image;
        }
        return array_slice($retenues, 0, self::MAX_PHOTOS * 2);
    }

    /**
     * Retient un fichier, selon le mode : recopié chez nous, ou simplement
     * référencé après vérification.
     * @return array{fichier:?string,distant:string,largeur:int,hauteur:int,orientation:string,poids:int,source:string}|null
     */
    private static function store(string $prospectId, string $url, string $base, int $dejaPris): ?array
    {
        if (self::mode() === self::MODE_LIENS) {
            return self::referencer($url);
        }
        if ($dejaPris >= self::MAX_TOTAL) {
            return null;
        }
        $response = Http::get($url, 15);
        if (!$response['ok'] || $response['body'] === '' || $response['size'] > self::MAX_FICHIER) {
            return null;
        }

        $binary = $response['body'];
        $type = strtolower($response['headers']['content-type'] ?? '');

        // Un logo est souvent vectoriel : on le garde tel quel, débarrassé de
        // tout script, puisqu'il est servi depuis notre domaine.
        if (str_contains($type, 'svg') || preg_match('/\.svg(\?|$)/i', $url)) {
            $svg = self::sanitizeSvg($binary);
            if ($svg === null) {
                return null;
            }
            $fichier = $base . '.svg';
            if (@file_put_contents(self::dir($prospectId) . '/' . $fichier, $svg) === false) {
                return null;
            }
            return [
                'fichier' => $fichier,
                'distant' => '',
                'largeur' => 0,
                'hauteur' => 0,
                'orientation' => 'vectoriel',
                'poids' => strlen($svg),
                'source' => $url,
            ];
        }

        $probe = Image::probe($binary);
        if ($probe === null) {
            return null;
        }
        // Une photo de 4 000 px alourdit la maquette sans rien y ajouter.
        if (max($probe['width'], $probe['height']) > self::MAX_EDGE) {
            $reduced = Image::downscale($binary, $probe, self::MAX_EDGE);
            if ($reduced !== null) {
                $binary = $reduced;
                $probe = Image::probe($binary) ?? $probe;
            }
        }

        $fichier = $base . '.' . $probe['extension'];
        if (@file_put_contents(self::dir($prospectId) . '/' . $fichier, $binary) === false) {
            return null;
        }

        return [
            'fichier' => $fichier,
            'distant' => '',
            'largeur' => $probe['width'],
            'hauteur' => $probe['height'],
            'orientation' => self::orientation($probe['width'], $probe['height']),
            'poids' => strlen($binary),
            'source' => $url,
        ];
    }

    /**
     * Mode « liens » : on ne stocke rien, mais on ne référence pas à l'aveugle.
     *
     * Un seul appel, borné à l'en-tête du fichier, répond aux deux questions
     * qui comptent : l'image s'affiche-t-elle bien depuis notre domaine — un
     * site protégé contre le vol d'images refuse justement les requêtes
     * venues d'ailleurs — et quelles sont ses dimensions réelles.
     *
     * @return array{fichier:?string,distant:string,largeur:int,hauteur:int,orientation:string,poids:int,source:string}|null
     */
    private static function referencer(string $url): ?array
    {
        // Une image en clair dans une maquette servie en HTTPS est bloquée par
        // le navigateur : autant l'écarter tout de suite.
        if (Config::get('app.base_url') && str_starts_with((string) Config::get('app.base_url'), 'https://')
            && str_starts_with($url, 'http://')) {
            return null;
        }

        $vectoriel = preg_match('/\.svg(\?|$)/i', $url) === 1;
        $reponse = Http::peek($url, self::ENTETE);
        if (!$reponse['ok']) {
            return null;
        }
        if ($vectoriel || str_contains(strtolower($reponse['headers']['content-type'] ?? ''), 'svg')) {
            return [
                'fichier' => null,
                'distant' => $url,
                'largeur' => 0,
                'hauteur' => 0,
                'orientation' => 'vectoriel',
                'poids' => 0,
                'source' => $url,
            ];
        }

        // En-tête seule : les octets reçus sont volontairement incomplets, un
        // décodage intégral échouerait sur une image parfaitement valide.
        $probe = Image::probe($reponse['body'], false);
        if ($probe === null) {
            return null;
        }
        return [
            'fichier' => null,
            'distant' => $url,
            'largeur' => $probe['width'],
            'hauteur' => $probe['height'],
            'orientation' => self::orientation($probe['width'], $probe['height']),
            'poids' => 0,
            'source' => $url,
        ];
    }

    /**
     * Adresse à utiliser dans la maquette pour une entrée du catalogue :
     * relative quand le fichier est chez nous, absolue quand il reste distant.
     */
    public static function src(?array $entree): ?string
    {
        if ($entree === null) {
            return null;
        }
        if (!empty($entree['fichier'])) {
            return 'assets/' . $entree['fichier'];
        }
        return !empty($entree['distant']) ? (string) $entree['distant'] : null;
    }

    private static function orientation(int $w, int $h): string
    {
        if ($h === 0) {
            return 'inconnue';
        }
        $ratio = $w / $h;
        return match (true) {
            $ratio > 1.25 => 'paysage',
            $ratio < 0.8 => 'portrait',
            default => 'carrée',
        };
    }

    /** Retire scripts et gestionnaires d'événements d'un SVG. */
    private static function sanitizeSvg(string $svg): ?string
    {
        if (!str_contains(strtolower($svg), '<svg')) {
            return null;
        }
        $svg = preg_replace('~<script\b[^>]*>.*?</script>~is', '', $svg) ?? $svg;
        $svg = preg_replace('~\son[a-z]+\s*=\s*"[^"]*"~i', '', $svg) ?? $svg;
        $svg = preg_replace("~\son[a-z]+\s*=\s*'[^']*'~i", '', $svg) ?? $svg;
        $svg = preg_replace('~<(foreignObject|iframe|embed)\b~i', '<!-- $1', $svg) ?? $svg;
        return $svg;
    }

    public static function clear(string $prospectId): void
    {
        $dir = self::dir($prospectId);
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * Catalogue transmis au modèle : uniquement ce qui l'aide à composer.
     * Le nom de fichier sert de src, l'orientation commande le cadrage.
     */
    public static function forPrompt(string $prospectId): array
    {
        $c = self::catalogue($prospectId);
        $sortie = [];
        $logo = self::src($c['logo'] ?? null);
        if ($logo !== null) {
            $sortie['logo'] = ['src' => $logo, 'orientation' => $c['logo']['orientation']];
        }
        $favicon = self::src($c['favicon'] ?? null);
        if ($favicon !== null) {
            $sortie['favicon'] = ['src' => $favicon];
        }
        foreach ($c['photos'] ?? [] as $photo) {
            $src = self::src($photo);
            if ($src === null) {
                continue;
            }
            $sortie['photos'][] = [
                'src' => $src,
                'dimensions' => $photo['largeur'] . '×' . $photo['hauteur'],
                'orientation' => $photo['orientation'],
                'description' => ($photo['alt'] ?? '') !== '' ? $photo['alt'] : '(sans description sur le site d\'origine)',
            ];
        }
        return $sortie;
    }
}
