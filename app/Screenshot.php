<?php
declare(strict_types=1);

namespace App;

/**
 * Capture de la page d'accueil du prospect, pour le comparatif avant/après et
 * pour donner au modèle une vision réelle du site.
 *
 * Trois chemins, essayés dans cet ordre :
 *
 * 1. le navigateur installé sur le serveur, s'il y en a un — c'est la seule
 *    capture entièrement locale, sans service tiers ni quota ;
 * 2. un service de capture externe, avec ou sans clé ;
 * 3. l'import manuel depuis la fiche prospect, qui ne dépend de rien.
 *
 * Aucun de ces chemins n'est indispensable : la capture est un bonus, elle ne
 * doit jamais bloquer la génération d'une maquette.
 */
final class Screenshot
{
    private const MAX_BYTES = 4194304; // 4 Mo, borne haute acceptée par l'API vision
    private const MAX_EDGE = 1568;     // au-delà, l'API réduit l'image de toute façon
    private const LARGEUR = 1280;
    private const HAUTEUR = 1000;

    /** Secondes accordées au navigateur local avant de le couper. */
    private const DELAI_NAVIGATEUR = 30;

    /** Secondes accordées à un service externe pour répondre. */
    private const DELAI_SERVICE = 25;

    /**
     * Budget de la chaîne entière, en secondes.
     *
     * Sans lui, trois fournisseurs qui expirent chacun de leur côté tiennent la
     * requête bien au-delà du temps d'exécution accordé par un hébergement
     * mutualisé : la page finit en erreur blanche au lieu d'un message.
     */
    private const BUDGET = 70.0;

    /**
     * Emplacements habituels d'un navigateur sans interface.
     *
     * La liste couvre les paquets Debian/Ubuntu, RedHat, Snap, Homebrew et les
     * binaires déposés à la main, parce qu'un hébergeur ne met jamais le même
     * au même endroit. Un chemin réglé dans la configuration passe avant.
     */
    private const NAVIGATEURS = [
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/chrome',
        '/usr/lib/chromium/chromium',
        '/usr/lib/chromium-browser/chromium-browser',
        '/snap/bin/chromium',
        '/opt/google/chrome/chrome',
        '/opt/pw-browsers/chromium',
        '/usr/local/bin/chromium',
        '/usr/local/bin/chrome',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    ];

    /** Fournisseurs proposés dans les réglages. {url} et {enc} sont substitués. */
    public const PROVIDERS = [
        'auto' => [
            'label' => 'Automatique (navigateur du serveur, puis services gratuits)',
            'template' => '',
            'needs_key' => false,
        ],
        'local' => [
            'label' => 'Navigateur installé sur le serveur (aucun service tiers)',
            'template' => '',
            'needs_key' => false,
        ],
        'thumio' => [
            'label' => 'Thum.io (sans clé, gratuit)',
            'template' => 'https://image.thum.io/get/width/1280/crop/1000/noanimate/{url}',
            'needs_key' => false,
        ],
        'mshots' => [
            'label' => 'WordPress mShots (sans clé, gratuit)',
            'template' => 'https://s0.wp.com/mshots/v1/{enc}?w=1280&h=1000',
            'needs_key' => false,
            // Le service répond immédiatement par une image d'attente et
            // fabrique la vraie capture en arrière-plan : il faut y revenir.
            'patiente' => true,
        ],
        'screenshotone' => [
            'label' => 'ScreenshotOne (clé requise)',
            'template' => 'https://api.screenshotone.com/take?access_key={key}&url={enc}&viewport_width=1280&viewport_height=1000&format=jpg&block_cookie_banners=true',
            'needs_key' => true,
        ],
        'apiflash' => [
            'label' => 'ApiFlash (clé requise)',
            'template' => 'https://api.apiflash.com/v1/urltoimage?access_key={key}&url={enc}&width=1280&height=1000&format=jpeg&response_type=image',
            'needs_key' => true,
        ],
        'custom' => [
            'label' => 'URL personnalisée',
            'template' => '',
            'needs_key' => false,
        ],
        'none' => [
            'label' => 'Désactivé (import manuel uniquement)',
            'template' => '',
            'needs_key' => false,
        ],
    ];

    /** Nom lisible d'un maillon de la chaîne. */
    public static function label(string $provider): string
    {
        return self::PROVIDERS[$provider]['label'] ?? $provider;
    }

    // ------------------------------------------------------- Chaîne d'essais

    /**
     * Ordre dans lequel les fournisseurs sont tentés.
     *
     * Un seul fournisseur, c'était un seul point de rupture : service en
     * panne, quota atteint, sortie réseau filtrée par l'hébergeur, et la
     * capture disparaissait sans recours. On tente donc le réglage choisi
     * d'abord, puis tout ce qui est disponible sans clé.
     *
     * @return string[]
     */
    public static function chaine(): array
    {
        $choisi = (string) Config::get('screenshot.provider', 'auto');
        if ($choisi === 'none') {
            return [];
        }

        $ordre = $choisi === 'auto' ? [] : [$choisi];
        if ($choisi === 'auto' || Config::get('screenshot.fallback', true)) {
            foreach (['local', 'thumio', 'mshots', 'custom'] as $repli) {
                if (!in_array($repli, $ordre, true)) {
                    $ordre[] = $repli;
                }
            }
        }

        return array_values(array_filter($ordre, static fn (string $p): bool => self::indisponible($p) === ''));
    }

    /**
     * Pourquoi ce fournisseur ne peut pas être tenté — chaîne vide s'il le peut.
     * Le message compte : « rien ne marche » n'aide personne à choisir.
     */
    public static function indisponible(string $provider): string
    {
        if (!isset(self::PROVIDERS[$provider]) || $provider === 'none' || $provider === 'auto') {
            return 'ce n\'est pas un service de capture';
        }
        if ($provider === 'local') {
            if (!self::execPossible()) {
                return 'l\'hébergement interdit le lancement de programmes (proc_open désactivé)';
            }
            return self::navigateur() === null ? 'aucun navigateur trouvé sur le serveur' : '';
        }
        if ($provider === 'custom') {
            return trim((string) Config::get('screenshot.custom_template', '')) === ''
                ? 'aucun modèle d\'URL saisi' : '';
        }
        if ((self::PROVIDERS[$provider]['needs_key'] ?? false)
            && trim((string) Config::get('screenshot.api_key', '')) === '') {
            return 'clé absente';
        }
        return '';
    }

    /** URL de capture construite depuis les réglages, ou null si sans objet. */
    public static function providerUrl(string $targetUrl, ?string $provider = null): ?string
    {
        $provider ??= (string) Config::get('screenshot.provider', 'auto');
        if (!isset(self::PROVIDERS[$provider]) || in_array($provider, ['none', 'auto', 'local'], true)) {
            return null;
        }
        $template = $provider === 'custom'
            ? (string) Config::get('screenshot.custom_template', '')
            : (string) (self::PROVIDERS[$provider]['template'] ?? '');
        if (trim($template) === '') {
            return null;
        }
        $key = (string) Config::get('screenshot.api_key', '');
        if ((self::PROVIDERS[$provider]['needs_key'] ?? false) && $key === '') {
            return null;
        }
        return strtr($template, [
            '{url}' => $targetUrl,
            '{enc}' => rawurlencode($targetUrl),
            '{key}' => rawurlencode($key),
        ]);
    }

    // --------------------------------------------------- Navigateur local

    /** Le serveur autorise-t-il le lancement d'un programme ? */
    public static function execPossible(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $interdites = array_map(
            static fn (string $f): string => strtolower(trim($f)),
            explode(',', (string) ini_get('disable_functions'))
        );
        return !in_array('proc_open', $interdites, true);
    }

    /** Chemin du navigateur utilisable, ou null s'il n'y en a pas. */
    public static function navigateur(): ?string
    {
        $regle = trim((string) Config::get('screenshot.browser_path', ''));
        if ($regle !== '') {
            return is_file($regle) && is_executable($regle) ? $regle : null;
        }
        foreach (self::NAVIGATEURS as $chemin) {
            if (is_file($chemin) && is_executable($chemin)) {
                return $chemin;
            }
        }
        return null;
    }

    /**
     * Capture par le navigateur du serveur.
     *
     * @return array{ok:bool,error:string,body:string}
     */
    private static function captureLocale(string $targetUrl): array
    {
        $vide = ['ok' => false, 'body' => ''];
        if (!preg_match('~^https?://~i', $targetUrl)) {
            return $vide + ['error' => 'Adresse inattendue.'];
        }
        $binaire = self::navigateur();
        if ($binaire === null) {
            return $vide + ['error' => 'Aucun navigateur installé sur le serveur.'];
        }

        $travail = self::dossierTemporaire();
        if ($travail === null) {
            return $vide + ['error' => 'Aucun dossier temporaire inscriptible.'];
        }
        $sortie = $travail . '/capture.png';
        $profil = $travail . '/profil';

        // « =new » est le mode des versions récentes ; les anciennes ne
        // connaissent que « --headless » tout court et refusent de démarrer.
        foreach (['--headless=new', '--headless'] as $mode) {
            $commande = escapeshellarg($binaire) . ' ' . $mode . ' '
                . '--disable-gpu --no-sandbox --disable-dev-shm-usage --disable-extensions '
                . '--no-first-run --no-default-browser-check --disable-background-networking '
                . '--hide-scrollbars --force-device-scale-factor=1 --ignore-certificate-errors '
                . '--user-data-dir=' . escapeshellarg($profil) . ' '
                . '--window-size=' . self::LARGEUR . ',' . self::HAUTEUR . ' '
                . '--virtual-time-budget=12000 '
                . '--screenshot=' . escapeshellarg($sortie) . ' '
                . escapeshellarg($targetUrl);

            $journal = self::lancer($commande, self::DELAI_NAVIGATEUR);
            $binaireImage = is_file($sortie) ? (string) @file_get_contents($sortie) : '';
            @unlink($sortie);
            if ($binaireImage !== '' && Image::probe($binaireImage) !== null) {
                self::effacerDossier($travail);
                return ['ok' => true, 'error' => '', 'body' => $binaireImage];
            }
            $dernier = $journal;
        }

        self::effacerDossier($travail);
        $detail = trim((string) ($dernier ?? ''));
        return $vide + ['error' => 'Le navigateur du serveur n\'a pas produit d\'image'
            . ($detail !== '' ? ' : ' . mb_substr($detail, 0, 180) : '.')];
    }

    /**
     * Lance une commande avec une limite de temps et rend ce qu'elle a dit.
     *
     * Sans limite, une page qui ne finit jamais de charger bloquerait la
     * requête PHP jusqu'à son propre délai d'exécution.
     */
    private static function lancer(string $commande, int $secondes): string
    {
        $tubes = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $processus = @proc_open($commande, $tubes, $canaux);
        if (!is_resource($processus)) {
            return 'lancement impossible';
        }
        stream_set_blocking($canaux[1], false);
        stream_set_blocking($canaux[2], false);

        $journal = '';
        $limite = microtime(true) + $secondes;
        while (true) {
            $etat = proc_get_status($processus);
            $journal .= (string) stream_get_contents($canaux[1]) . (string) stream_get_contents($canaux[2]);
            if (!$etat['running']) {
                break;
            }
            if (microtime(true) > $limite) {
                proc_terminate($processus, 9);
                $journal .= ' (interrompu après ' . $secondes . ' s)';
                break;
            }
            usleep(100000);
        }
        foreach ($canaux as $canal) {
            if (is_resource($canal)) {
                fclose($canal);
            }
        }
        proc_close($processus);
        return $journal;
    }

    /** Dossier de travail jetable pour le navigateur. */
    private static function dossierTemporaire(): ?string
    {
        foreach ([DATA_DIR . '/tmp', sys_get_temp_dir()] as $racine) {
            if (!is_dir($racine)) {
                @mkdir($racine, 0775, true);
            }
            if (!is_dir($racine) || !is_writable($racine)) {
                continue;
            }
            self::menage($racine);
            $dossier = $racine . '/shot-' . bin2hex(random_bytes(6));
            if (@mkdir($dossier, 0775, true)) {
                return $dossier;
            }
        }
        return null;
    }

    /**
     * Efface les dossiers de travail abandonnés.
     *
     * Une requête interrompue en plein milieu laisse son profil de navigateur
     * derrière elle ; sans ce ménage, ils s'accumulent jusqu'à remplir le
     * disque d'un hébergement mutualisé.
     */
    private static function menage(string $racine): void
    {
        $limite = time() - 3600;
        foreach ((array) @glob($racine . '/shot-*') as $vieux) {
            if (is_dir($vieux) && (int) @filemtime($vieux) < $limite) {
                self::effacerDossier($vieux);
            }
        }
    }

    private static function effacerDossier(string $dossier): void
    {
        if (!is_dir($dossier)) {
            return;
        }
        $entrees = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($entrees as $entree) {
            $entree->isDir() ? @rmdir($entree->getPathname()) : @unlink($entree->getPathname());
        }
        @rmdir($dossier);
    }

    // ------------------------------------------------------------- Capture

    /**
     * Un essai auprès d'un fournisseur, sans rien enregistrer.
     * @return array{ok:bool,error:string,body:string,resume:string}
     */
    private static function tenter(string $provider, string $targetUrl): array
    {
        $debut = microtime(true);
        if ($provider === 'local') {
            $essai = self::captureLocale($targetUrl);
            $duree = round(microtime(true) - $debut, 1) . ' s';
            $essai['resume'] = $essai['ok'] ? 'image obtenue en ' . $duree : $essai['error'];
            return $essai;
        }

        $source = self::providerUrl($targetUrl, $provider);
        if ($source === null) {
            return ['ok' => false, 'error' => 'non configuré', 'body' => '', 'resume' => 'non configuré'];
        }

        // mShots répond du premier coup par une image d'attente : on repasse.
        $essais = !empty(self::PROVIDERS[$provider]['patiente']) ? 3 : 1;
        $dernier = '';
        for ($tour = 1; $tour <= $essais; $tour++) {
            if ($tour > 1) {
                if (microtime(true) - $debut > 20) {
                    break;
                }
                sleep(3);
            }
            $reponse = Http::get($source, self::DELAI_SERVICE, 0, self::MAX_BYTES);
            if (!$reponse['ok'] || $reponse['body'] === '') {
                $dernier = $reponse['error'] !== ''
                    ? $reponse['error']
                    : 'réponse HTTP ' . $reponse['status'] . ' sans image';
                continue;
            }
            $probe = Image::probe($reponse['body']);
            if ($probe === null) {
                $type = strtolower($reponse['headers']['content-type'] ?? '') ?: 'type inconnu';
                $dernier = 'réponse non exploitable (' . $type . ', ' . $reponse['size'] . ' octets) : '
                    . self::apercu($reponse['body']);
                continue;
            }
            if (self::estUnie($reponse['body'])) {
                $dernier = 'image d\'attente renvoyée (capture pas encore prête)';
                continue;
            }
            $duree = round(microtime(true) - $debut, 1) . ' s';
            return ['ok' => true, 'error' => '', 'body' => $reponse['body'],
                'resume' => $probe['width'] . '×' . $probe['height'] . ' ' . strtoupper($probe['extension'])
                    . ' en ' . $duree];
        }

        return ['ok' => false, 'error' => $dernier, 'body' => '', 'resume' => $dernier];
    }

    /**
     * L'image est-elle uniforme, donc vide de contenu ?
     *
     * C'est ainsi que se reconnaît une image d'attente : le service répond
     * bien, l'image est valide, elle est simplement grise. Enregistrée telle
     * quelle, elle donnait un « avant » rigoureusement vide au prospect.
     */
    private static function estUnie(string $binary): bool
    {
        if (!function_exists('imagecreatefromstring')) {
            return false;
        }
        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return false;
        }
        $largeur = imagesx($image);
        $hauteur = imagesy($image);
        $vues = [];
        for ($x = 1; $x < 8; $x++) {
            for ($y = 1; $y < 8; $y++) {
                $couleur = @imagecolorat($image, (int) ($largeur * $x / 8), (int) ($hauteur * $y / 8));
                $vues[$couleur === false ? 0 : $couleur] = true;
            }
        }
        imagedestroy($image);
        return count($vues) <= 2;
    }

    /**
     * Récupère et stocke la capture, en essayant tous les fournisseurs
     * disponibles jusqu'à ce que l'un rende une vraie image.
     *
     * @return array{ok:bool,error:string,path:?string,provider:string,journal:array<string,string>}
     */
    public static function capture(string $prospectId, string $targetUrl): array
    {
        $chaine = self::chaine();
        $journal = [];
        if ($chaine === []) {
            $raison = (string) Config::get('screenshot.provider', 'auto') === 'none'
                ? 'La capture automatique est désactivée dans les Réglages.'
                : 'Aucun service de capture disponible : ' . self::pourquoiRien();
            return ['ok' => false, 'error' => $raison, 'path' => null, 'provider' => '', 'journal' => []];
        }

        $limite = microtime(true) + self::BUDGET;
        foreach ($chaine as $provider) {
            if (microtime(true) > $limite) {
                $journal[self::label($provider)] = 'non tenté (temps imparti dépassé)';
                continue;
            }
            $essai = self::tenter($provider, $targetUrl);
            $journal[self::label($provider)] = $essai['resume'];
            if (!$essai['ok']) {
                continue;
            }
            if (strlen($essai['body']) > self::MAX_BYTES) {
                $journal[self::label($provider)] = 'image trop volumineuse ('
                    . Scraper::humanSize(strlen($essai['body'])) . ')';
                continue;
            }
            $probe = Image::probe($essai['body']);
            if ($probe === null) {
                continue;
            }
            self::clear($prospectId);
            $path = self::dir($prospectId) . '/avant.' . $probe['extension'];
            if (@file_put_contents($path, $essai['body']) === false) {
                return ['ok' => false, 'error' => 'Écriture impossible dans data/mockups.',
                    'path' => null, 'provider' => $provider, 'journal' => $journal];
            }
            return ['ok' => true, 'error' => '', 'path' => $path,
                'provider' => $provider, 'journal' => $journal];
        }

        $details = [];
        foreach ($journal as $nom => $resume) {
            $details[] = $nom . ' : ' . $resume;
        }
        return [
            'ok' => false,
            'error' => 'Aucun service n\'a rendu d\'image — ' . implode(' · ', $details)
                . '. Vous pouvez importer une capture à la main juste à côté.',
            'path' => null,
            'provider' => '',
            'journal' => $journal,
        ];
    }

    /** Pourquoi aucun fournisseur n'est disponible, dit simplement. */
    private static function pourquoiRien(): string
    {
        $raisons = [];
        foreach (['local', 'thumio', 'mshots', 'custom'] as $provider) {
            $raison = self::indisponible($provider);
            if ($raison !== '') {
                $raisons[] = self::label($provider) . ' — ' . $raison;
            }
        }
        return $raisons === [] ? 'cause inconnue' : implode(' · ', $raisons);
    }

    /**
     * Essai de capture, sans rien enregistrer.
     *
     * La capture échouait en silence : il ne restait qu'une image manquante,
     * sans moyen de savoir si le service refusait, si l'hébergement bloquait
     * la sortie, ou si l'octet reçu n'était pas une image. Cet essai passe
     * toute la chaîne en revue et rapporte ce que chaque maillon a répondu.
     *
     * @return array{ok:bool,message:string,details:array}
     */
    public static function test(string $targetUrl = 'https://example.com/'): array
    {
        $details = [
            'réglage' => self::label((string) Config::get('screenshot.provider', 'auto')),
            'navigateur du serveur' => self::navigateur() ?? (self::execPossible()
                ? 'aucun trouvé'
                : 'lancement de programmes interdit par l\'hébergement'),
        ];

        $chaine = self::chaine();
        if ($chaine === []) {
            $details['chaîne'] = 'vide';
            return ['ok' => false, 'message' => 'Aucun service de capture disponible.', 'details' => $details];
        }

        $limite = microtime(true) + self::BUDGET;
        foreach ($chaine as $provider) {
            if (microtime(true) > $limite) {
                $details[self::label($provider)] = 'non tenté (temps imparti dépassé)';
                continue;
            }
            $essai = self::tenter($provider, $targetUrl);
            $details[self::label($provider)] = $essai['resume'];
            if ($essai['ok']) {
                return ['ok' => true, 'message' => 'Capture obtenue par « ' . self::label($provider) . ' ».',
                    'details' => $details];
            }
        }

        return ['ok' => false, 'message' => 'Aucun maillon de la chaîne n\'a rendu d\'image.',
            'details' => $details];
    }

    /** Les premiers caractères lisibles d'une réponse qui n'est pas une image. */
    private static function apercu(string $corps): string
    {
        $texte = preg_replace('/\s+/', ' ', substr($corps, 0, 400)) ?? '';
        $texte = preg_replace('/[^\P{C}\n]/u', '·', $texte) ?? $texte;
        return trim(mb_substr($texte, 0, 220));
    }

    // ------------------------------------------------------------- Fichiers

    public static function dir(string $prospectId): string
    {
        $dir = DATA_DIR . '/mockups/' . preg_replace('/[^a-z0-9]/i', '', $prospectId);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public static function path(string $prospectId): ?string
    {
        foreach (['jpg', 'png', 'webp'] as $extension) {
            $file = self::dir($prospectId) . '/avant.' . $extension;
            if (is_file($file)) {
                return $file;
            }
        }
        return null;
    }

    /**
     * Une capture réellement affichable est-elle disponible ?
     *
     * La seule présence du fichier ne suffit pas : un service de capture peut
     * avoir renvoyé une page d'erreur, enregistrée telle quelle. Servir ces
     * octets produit une image cassée sur la page vue par le prospect.
     * Le contrôle ne lit que l'en-tête, il reste donc peu coûteux.
     */
    public static function exists(string $prospectId): bool
    {
        return self::usablePath($prospectId) !== null;
    }

    /** Chemin de la capture, uniquement si les octets sont bien une image. */
    public static function usablePath(string $prospectId): ?string
    {
        $path = self::path($prospectId);
        if ($path === null) {
            return null;
        }
        return Image::probeFile($path) !== null ? $path : null;
    }

    /** Le prospect a-t-il un fichier de capture inexploitable ? */
    public static function hasBrokenFile(string $prospectId): bool
    {
        return self::path($prospectId) !== null && self::usablePath($prospectId) === null;
    }

    /**
     * Supprime un fichier de capture inexploitable.
     * Appelé depuis l'administration : une page publique ne doit rien écrire.
     */
    public static function purgeIfInvalid(string $prospectId): bool
    {
        if (!self::hasBrokenFile($prospectId)) {
            return false;
        }
        self::clear($prospectId);
        return true;
    }

    public static function mediaType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    /** Enregistre une capture importée manuellement depuis la fiche prospect. */
    public static function storeUpload(string $prospectId, array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Aucun fichier reçu.'];
        }
        if (($file['size'] ?? 0) > self::MAX_BYTES) {
            return ['ok' => false, 'error' => 'Image trop lourde (4 Mo maximum).'];
        }
        $info = @getimagesize($file['tmp_name']);
        if ($info === false) {
            return ['ok' => false, 'error' => 'Ce fichier n\'est pas une image valide.'];
        }
        $extension = Image::ACCEPTED[$info[2]][1] ?? null;
        if ($extension === null) {
            return ['ok' => false, 'error' => 'Formats acceptés : JPEG, PNG, WebP et GIF.'];
        }
        self::clear($prospectId);
        $path = self::dir($prospectId) . '/avant.' . $extension;
        if (!@move_uploaded_file($file['tmp_name'], $path)) {
            return ['ok' => false, 'error' => 'Impossible d\'enregistrer le fichier.'];
        }
        return ['ok' => true, 'error' => ''];
    }

    public static function clear(string $prospectId): void
    {
        foreach (['jpg', 'png', 'webp'] as $extension) {
            @unlink(self::dir($prospectId) . '/avant.' . $extension);
        }
    }

    /**
     * Bloc image prêt à être joint à une requête Messages API.
     *
     * Le type déclaré est déduit des octets, jamais de l'extension du fichier :
     * une incohérence entre les deux fait rejeter toute la requête. Les images
     * trop grandes sont réduites, l'API les redimensionnant de toute façon.
     */
    public static function toImageBlock(string $prospectId): ?array
    {
        $path = self::path($prospectId);
        if ($path === null) {
            return null;
        }
        $binary = @file_get_contents($path);
        if ($binary === false) {
            return null;
        }

        $probe = Image::probe($binary);
        if ($probe === null) {
            return null;
        }

        $longEdge = max($probe['width'], $probe['height']);
        if ($longEdge > self::MAX_EDGE) {
            $reduced = Image::downscale($binary, $probe, self::MAX_EDGE);
            if ($reduced === null) {
                return null;
            }
            $binary = $reduced;
            $probe = Image::probe($binary) ?? $probe;
        }

        // La limite porte sur la charge encodée, qui pèse un tiers de plus.
        if ((int) (strlen($binary) * 4 / 3) > self::MAX_BYTES) {
            return null;
        }

        return [
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => $probe['media_type'],
                'data' => base64_encode($binary),
            ],
        ];
    }
}
