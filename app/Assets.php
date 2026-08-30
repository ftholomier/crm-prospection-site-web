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

    /** Nom de l'emplacement de photo non pourvu. */
    public const A_FOURNIR = 'a-fournir.svg';

    public static function mode(): string
    {
        return Config::get('design.assets_mode', self::MODE_LIENS) === self::MODE_COPIE
            ? self::MODE_COPIE
            : self::MODE_LIENS;
    }

    public static function isPlaceholder(string $fichier): bool
    {
        return basename($fichier) === self::A_FOURNIR;
    }

    /**
     * L'image d'un emplacement à pourvoir.
     *
     * Un bloc illustré sans photo n'est pas supprimé : il reste en place et dit
     * ce qu'il attend. La mise en page du gabarit est ainsi préservée, et il
     * suffit de déposer une image depuis l'éditeur pour que le bloc s'anime.
     */
    public static function placeholderSvg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 600" role="img" '
            . 'aria-label="Photo à fournir">'
            . '<defs><pattern id="h" width="24" height="24" patternTransform="rotate(45)" '
            . 'patternUnits="userSpaceOnUse">'
            . '<rect width="24" height="24" fill="#faf7f3"/><rect width="12" height="24" fill="#f1ebe4"/>'
            . '</pattern></defs>'
            . '<rect width="800" height="600" fill="url(#h)"/>'
            . '<text x="400" y="300" text-anchor="middle" dominant-baseline="middle" '
            . 'font-family="system-ui, sans-serif" font-size="26" letter-spacing="4" fill="#8a7f75">'
            . 'PHOTO À FOURNIR</text></svg>';
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

        // Un logo déposé à la main survit à une nouvelle analyse : il a été
        // fourni justement parce que la lecture automatique ne le trouve pas,
        // le réécraser à chaque passage serait absurde.
        $depose = self::keepManualLogo($prospectId);
        $deposees = self::keepManualPhotos($prospectId);
        // Les images écartées à la main le restent : sans cela, relancer
        // l'analyse les ferait toutes revenir et le tri serait à refaire.
        $ecartees = self::ecartees($prospectId);

        self::clear($prospectId, array_merge(
            $depose === null ? [] : [(string) $depose['fichier']],
            array_column($deposees, 'fichier')
        ));
        $mode = self::mode();
        $catalogue = ['logo' => $depose, 'favicon' => null, 'photos' => $deposees, 'mode' => $mode,
            'ecartees' => $ecartees, 'at' => time()];
        $total = 0;

        $logo = trim((string) ($analysis['logo'] ?? ''));
        if ($depose !== null) {
            $say('Logo déposé à la main, conservé', 'done');
        } elseif ($logo !== '') {
            $say('Récupération du logo');
            $stored = self::store($prospectId, $logo, 'logo', $total);
            if ($stored !== null) {
                $catalogue['logo'] = $stored;
                $total += $stored['poids'];
                $say('Logo récupéré (' . $stored['largeur'] . '×' . $stored['hauteur'] . ')', 'done');
            } else {
                $say('Logo inexploitable, ignoré', 'warn');
            }
        } else {
            $say('Aucun logo trouvé sur le site — vous pouvez le déposer depuis la fiche', 'warn');
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

        $sources = self::selectPhotos($analysis, $ecartees);
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
    private static function selectPhotos(array $analysis, array $ecartees = []): array
    {
        $retenues = [];
        $vues = [];
        foreach ($analysis['images'] ?? [] as $image) {
            $url = (string) ($image['url'] ?? '');
            if ($url === '' || in_array($url, $ecartees, true)) {
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
        // Les deux mécanismes se complètent : on pointe le fichier distant
        // quand c'est possible, on le recopie sinon. L'inverse en mode copie.
        // Dans les deux cas on ne repart pas les mains vides à la première
        // difficulté — c'est ce qui vidait les maquettes de toute photo.
        if (self::mode() === self::MODE_LIENS) {
            return self::referencer($url) ?? self::copier($prospectId, $url, $base, $dejaPris);
        }
        return self::copier($prospectId, $url, $base, $dejaPris) ?? self::referencer($url);
    }

    /** Télécharge le fichier et l'enregistre sous un nom maîtrisé. */
    private static function copier(string $prospectId, string $url, string $base, int $dejaPris): ?array
    {
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

    /**
     * Remplace le logo par un fichier fourni à la main.
     *
     * Le logo est ce qui manque le plus souvent : beaucoup de sites le posent
     * en fond CSS, ou sous un nom qui ne le désigne pas. Plutôt que de deviner
     * mieux, on laisse le déposer — c'est plus sûr et c'est immédiat.
     *
     * @param array $fichier une entrée de $_FILES
     * @return array{ok:bool,error:string}
     */
    public static function replaceLogo(string $prospectId, array $fichier): array
    {
        $erreur = (int) ($fichier['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($erreur === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'error' => 'Aucun fichier reçu.'];
        }
        if ($erreur !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => match ($erreur) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Fichier trop lourd pour le serveur.',
                UPLOAD_ERR_PARTIAL => 'Envoi interrompu, réessayez.',
                default => 'Envoi impossible (code ' . $erreur . ').',
            }];
        }

        $chemin = (string) ($fichier['tmp_name'] ?? '');
        if ($chemin === '' || !is_uploaded_file($chemin)) {
            return ['ok' => false, 'error' => 'Fichier introuvable après envoi.'];
        }
        if (filesize($chemin) > self::MAX_FICHIER) {
            return ['ok' => false, 'error' => 'Le logo dépasse ' . Scraper::humanSize(self::MAX_FICHIER) . '.'];
        }

        $binaire = (string) file_get_contents($chemin);
        $nomEnvoye = strtolower((string) ($fichier['name'] ?? ''));

        // Le type déclaré par le navigateur n'engage personne : c'est le
        // contenu qui décide, comme partout ailleurs dans l'application.
        if (str_contains(strtolower($binaire), '<svg')) {
            $svg = self::sanitizeSvg($binaire);
            if ($svg === null) {
                return ['ok' => false, 'error' => 'Ce SVG est illisible.'];
            }
            $entree = [
                'fichier' => 'logo.svg', 'distant' => '', 'largeur' => 0, 'hauteur' => 0,
                'orientation' => 'vectoriel', 'poids' => strlen($svg), 'source' => 'dépôt manuel',
            ];
            $contenu = $svg;
        } else {
            $probe = Image::probe($binaire);
            if ($probe === null) {
                return ['ok' => false, 'error' => 'Format non reconnu. Attendu : PNG, JPEG, WebP, GIF ou SVG.'];
            }
            if (max($probe['width'], $probe['height']) > self::MAX_EDGE) {
                $reduit = Image::downscale($binaire, $probe, self::MAX_EDGE);
                if ($reduit !== null) {
                    $binaire = $reduit;
                    $probe = Image::probe($binaire) ?? $probe;
                }
            }
            $entree = [
                'fichier' => 'logo.' . $probe['extension'], 'distant' => '',
                'largeur' => $probe['width'], 'hauteur' => $probe['height'],
                'orientation' => self::orientation($probe['width'], $probe['height']),
                'poids' => strlen($binaire), 'source' => 'dépôt manuel',
            ];
            $contenu = $binaire;
        }

        $catalogue = self::catalogue($prospectId);
        self::removeLogoFiles($prospectId, $catalogue);
        if (@file_put_contents(self::dir($prospectId) . '/' . $entree['fichier'], $contenu) === false) {
            return ['ok' => false, 'error' => 'Écriture impossible dans data/mockups : vérifiez les droits du dossier.'];
        }

        $catalogue['logo'] = $entree;
        Store::write(self::cataloguePath($prospectId), $catalogue);
        return ['ok' => true, 'error' => '', 'nom' => $nomEnvoye];
    }

    /**
     * Ajoute une image au catalogue depuis un dépôt manuel.
     *
     * Sert l'éditeur : quand une photo manque ou ne convient pas, on en met une
     * soi-même plutôt que d'espérer que la prochaine lecture du site fasse
     * mieux.
     *
     * @param array $fichier une entrée de $_FILES
     * @return array{ok:bool,error:string,src?:string}
     */
    public static function addImage(string $prospectId, array $fichier): array
    {
        $erreur = (int) ($fichier['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($erreur === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'error' => 'Aucun fichier reçu.'];
        }
        if ($erreur !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => match ($erreur) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Fichier trop lourd pour le serveur.',
                UPLOAD_ERR_PARTIAL => 'Envoi interrompu, réessayez.',
                default => 'Envoi impossible (code ' . $erreur . ').',
            }];
        }

        $chemin = (string) ($fichier['tmp_name'] ?? '');
        if ($chemin === '' || !is_uploaded_file($chemin)) {
            return ['ok' => false, 'error' => 'Fichier introuvable après envoi.'];
        }
        if (filesize($chemin) > self::MAX_FICHIER) {
            return ['ok' => false, 'error' => 'L\'image dépasse ' . Scraper::humanSize(self::MAX_FICHIER) . '.'];
        }

        $binaire = (string) file_get_contents($chemin);
        $probe = Image::probe($binaire);
        if ($probe === null) {
            return ['ok' => false, 'error' => 'Format non reconnu. Attendu : PNG, JPEG, WebP ou GIF.'];
        }
        if (max($probe['width'], $probe['height']) > self::MAX_EDGE) {
            $reduit = Image::downscale($binaire, $probe, self::MAX_EDGE);
            if ($reduit !== null) {
                $binaire = $reduit;
                $probe = Image::probe($binaire) ?? $probe;
            }
        }

        // Un nom tiré du contenu : redéposer deux fois la même image ne crée
        // pas deux fichiers, et le nom reste stable d'une session à l'autre.
        $nom = 'depot-' . substr(sha1($binaire), 0, 12) . '.' . $probe['extension'];
        if (@file_put_contents(self::dir($prospectId) . '/' . $nom, $binaire) === false) {
            return ['ok' => false, 'error' => 'Écriture impossible dans data/mockups : vérifiez les droits du dossier.'];
        }

        $catalogue = self::catalogue($prospectId);
        $catalogue['photos'] = array_values(array_filter(
            $catalogue['photos'] ?? [],
            static fn (array $photo): bool => ($photo['fichier'] ?? '') !== $nom
        ));
        $catalogue['photos'][] = [
            'fichier' => $nom,
            'distant' => '',
            'largeur' => $probe['width'],
            'hauteur' => $probe['height'],
            'orientation' => self::orientation($probe['width'], $probe['height']),
            'poids' => strlen($binaire),
            'source' => 'dépôt manuel',
            'alt' => '',
        ];
        Store::write(self::cataloguePath($prospectId), $catalogue);

        return ['ok' => true, 'error' => '', 'src' => 'assets/' . $nom];
    }

    /**
     * Retire une photo du catalogue.
     *
     * Ce qui ne figure plus au catalogue n'existe plus pour la génération : le
     * modèle ne reçoit que ce catalogue, et le contrôle de conformité refuse
     * toute photo qui n'y est pas. Écarter une image est donc la manière de
     * dire « pas celle-là » avant de relancer.
     *
     * Le fichier n'est effacé que s'il est chez nous et qu'aucune autre entrée
     * ne s'en sert : deux entrées peuvent pointer le même fichier après un
     * re-dépôt.
     */
    public static function removeImage(string $prospectId, string $fichier): array
    {
        $fichier = basename(trim($fichier));
        if ($fichier === '' || $fichier === '.' || $fichier === '..') {
            return ['ok' => false, 'error' => 'Image non identifiée.'];
        }

        $catalogue = self::catalogue($prospectId);
        $photos = $catalogue['photos'] ?? [];
        $reste = [];
        $trouvee = null;
        foreach ($photos as $photo) {
            // Une photo pointée à distance n'a pas de fichier local : elle
            // s'identifie alors par son adresse.
            $cle = ($photo['fichier'] ?? '') !== '' ? (string) $photo['fichier'] : (string) ($photo['distant'] ?? '');
            if ($trouvee === null && (basename($cle) === $fichier || sha1($cle) === $fichier)) {
                $trouvee = $photo;
                continue;
            }
            $reste[] = $photo;
        }
        if ($trouvee === null) {
            return ['ok' => false, 'error' => 'Cette image ne figure pas dans le catalogue.'];
        }

        $catalogue['photos'] = $reste;
        // La liste des images écartées est conservée : une nouvelle collecte
        // ne doit pas les faire revenir, sinon écarter une photo ne servirait
        // que jusqu'à la prochaine analyse.
        $ecarte = (string) (($trouvee['distant'] ?? '') !== '' ? $trouvee['distant'] : ($trouvee['fichier'] ?? ''));
        if ($ecarte !== '' && !in_array($ecarte, $catalogue['ecartees'] ?? [], true)) {
            $catalogue['ecartees'][] = $ecarte;
        }
        Store::write(self::cataloguePath($prospectId), $catalogue);

        $local = (string) ($trouvee['fichier'] ?? '');
        if ($local !== '' && !self::fichierEncoreUtilise($catalogue, $local)) {
            @unlink(self::dir($prospectId) . '/' . basename($local));
        }
        return ['ok' => true, 'error' => ''];
    }

    /** Ce fichier sert-il encore à une autre entrée du catalogue ? */
    private static function fichierEncoreUtilise(array $catalogue, string $fichier): bool
    {
        foreach (array_merge($catalogue['photos'] ?? [], array_filter([
            $catalogue['logo'] ?? null,
            $catalogue['favicon'] ?? null,
        ])) as $entree) {
            if (($entree['fichier'] ?? '') === $fichier) {
                return true;
            }
        }
        return false;
    }

    /** Images écartées à la main, à ne plus jamais récupérer. */
    public static function ecartees(string $prospectId): array
    {
        return array_values(array_filter((array) (self::catalogue($prospectId)['ecartees'] ?? [])));
    }

    /**
     * Ajoute une photo par son adresse, sans la télécharger.
     *
     * Le pendant du dépôt de fichier : l'agence colle l'adresse d'une image
     * qu'elle a repérée sur le site du prospect et que la collecte a manquée.
     * En mode « liens », rien n'est copié — c'est le réglage que vous avez
     * demandé, et l'adresse est reprise telle quelle dans la maquette.
     */
    public static function addImageByUrl(string $prospectId, string $url): array
    {
        $url = trim($url);
        if ($url === '' || !preg_match('~^https?://~i', $url)) {
            return ['ok' => false, 'error' => 'Adresse invalide : elle doit commencer par http:// ou https://.'];
        }
        if (!preg_match('~\.(jpe?g|png|webp|gif|avif)(\?|#|$)~i', $url) && !str_contains($url, 'format=')) {
            return ['ok' => false, 'error' => 'Cette adresse ne désigne pas une image reconnue (jpg, png, webp, gif, avif).'];
        }

        $catalogue = self::catalogue($prospectId);
        foreach ($catalogue['photos'] ?? [] as $photo) {
            if (($photo['distant'] ?? '') === $url) {
                return ['ok' => false, 'error' => 'Cette image est déjà au catalogue.'];
            }
        }

        // Les dimensions ne sont pas connues sans télécharger : on interroge
        // l'image, et si le serveur refuse on la garde quand même en la
        // déclarant de format inconnu plutôt que de la rejeter.
        $largeur = 0;
        $hauteur = 0;
        $reponse = Http::get($url, 10);
        if ($reponse['ok'] && $reponse['body'] !== '') {
            $probe = Image::probe($reponse['body']);
            if ($probe !== null) {
                $largeur = $probe['width'];
                $hauteur = $probe['height'];
            }
        }

        $catalogue['photos'][] = [
            'fichier' => '',
            'distant' => $url,
            'largeur' => $largeur,
            'hauteur' => $hauteur,
            'orientation' => $largeur > 0 ? self::orientation($largeur, $hauteur) : 'paysage',
            'poids' => 0,
            'source' => 'adresse saisie',
            'alt' => '',
        ];
        $catalogue['ecartees'] = array_values(array_diff((array) ($catalogue['ecartees'] ?? []), [$url]));
        Store::write(self::cataloguePath($prospectId), $catalogue);

        return ['ok' => true, 'error' => '', 'src' => $url];
    }

    /** Retire le logo du catalogue, et son fichier s'il était chez nous. */
    public static function forgetLogo(string $prospectId): void
    {
        $catalogue = self::catalogue($prospectId);
        self::removeLogoFiles($prospectId, $catalogue);
        $catalogue['logo'] = null;
        Store::write(self::cataloguePath($prospectId), $catalogue);
    }

    /** Un logo remplacé peut avoir une autre extension : on efface les deux. */
    private static function removeLogoFiles(string $prospectId, array $catalogue): void
    {
        $ancien = $catalogue['logo']['fichier'] ?? null;
        if ($ancien !== null) {
            @unlink(self::dir($prospectId) . '/' . basename((string) $ancien));
        }
        foreach (glob(self::dir($prospectId) . '/logo.*') ?: [] as $fichier) {
            @unlink($fichier);
        }
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

    /** L'entrée du logo si, et seulement si, il a été déposé à la main. */
    private static function keepManualLogo(string $prospectId): ?array
    {
        $logo = self::catalogue($prospectId)['logo'] ?? null;
        if (!is_array($logo) || ($logo['source'] ?? '') !== 'dépôt manuel') {
            return null;
        }
        return self::pathOf($prospectId, (string) ($logo['fichier'] ?? '')) !== null ? $logo : null;
    }

    /** Les photos déposées à la main, qu'une nouvelle analyse ne doit pas jeter. */
    private static function keepManualPhotos(string $prospectId): array
    {
        $gardees = [];
        foreach (self::catalogue($prospectId)['photos'] ?? [] as $photo) {
            if (($photo['source'] ?? '') === 'dépôt manuel'
                && self::pathOf($prospectId, (string) ($photo['fichier'] ?? '')) !== null) {
                $gardees[] = $photo;
            }
        }
        return $gardees;
    }

    /** @param string[] $garder noms de fichiers à conserver */
    public static function clear(string $prospectId, array $garder = []): void
    {
        $dir = self::dir($prospectId);
        $garder = array_map('basename', array_filter($garder));
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (in_array(basename($file), $garder, true)) {
                continue;
            }
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
        $sortie['emplacement_a_pourvoir'] = 'assets/' . self::A_FOURNIR;
        return $sortie;
    }
}
