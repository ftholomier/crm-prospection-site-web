<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Analyzer;
use App\Assets;
use App\Auth;
use App\Claude;
use App\Compare;
use App\Config;
use App\Consumption;
use App\Ai;
use App\Csrf;
use App\DeepSeek;
use App\Editor;
use App\Enrich;
use App\Events;
use App\Flash;
use App\Gemini;
use App\Generator;
use App\Mail\Smtp;
use App\Mailer;
use App\Models;
use App\Mockup;
use App\Palette;
use App\Portrait;
use App\Prospect;
use App\Router;
use App\Screenshot;
use App\Sequence;
use App\Stats;
use App\Suppression;
use App\Templates;
use App\Tracking;
use App\Util;

/** Écrans et actions de l'administration, tous protégés par mot de passe. */
final class Admin
{
    // ---------------------------------------------------------------- Accès

    public static function login(): void
    {
        if (!Config::isInstalled()) {
            Util::redirect(Router::url('install'));
        }
        if (Auth::check()) {
            Util::redirect(Router::url('dashboard'));
        }

        $error = '';
        $identifier = trim((string) ($_POST['identifier'] ?? ''));

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (Auth::isLocked()) {
                $error = 'Trop de tentatives. Réessayez dans ' . ceil(Auth::lockedFor() / 60) . ' minutes.';
            } elseif (Auth::attempt($identifier, (string) ($_POST['password'] ?? ''))) {
                Util::redirect(Router::url('dashboard'));
            } else {
                $error = 'Identifiant ou mot de passe incorrect.';
            }
        }

        echo render('admin/login', [
            'mode' => 'login',
            'error' => $error,
            'identifier' => $identifier,
        ]);
    }

    public static function install(): void
    {
        if (Config::isInstalled()) {
            Util::redirect(Router::url('login'));
        }

        $error = '';
        $identifier = trim((string) ($_POST['identifier'] ?? ''));

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $password = (string) ($_POST['password'] ?? '');
            if (!Util::isEmail($identifier)) {
                $error = 'Saisissez une adresse email valide : elle servira d\'identifiant et recevra les liens de récupération.';
            } elseif (strlen($password) < 8) {
                $error = 'Choisissez un mot de passe d\'au moins 8 caractères.';
            } elseif ($password !== (string) ($_POST['password_confirm'] ?? '')) {
                $error = 'Les deux mots de passe ne correspondent pas.';
            } elseif (Auth::install($identifier, $password)) {
                Flash::success('Compte créé. Complétez les réglages pour commencer.');
                Util::redirect(Router::url('settings'));
            } else {
                $error = is_writable(DATA_DIR)
                    ? 'Installation impossible : la configuration n\'a pas pu être enregistrée.'
                    : 'Le dossier data/ n\'est pas accessible en écriture : rien ne peut être enregistré. Appliquez chmod -R 775 data puis rechargez.';
            }
        }

        echo render('admin/login', [
            'mode' => 'install',
            'error' => $error,
            'identifier' => $identifier,
            'blocker' => is_writable(DATA_DIR)
                ? null
                : 'Le dossier data/ n\'est pas accessible en écriture. Appliquez chmod -R 775 data avant de continuer, sinon rien ne sera enregistré.',
        ]);
    }

    /** Demande d'un lien de réinitialisation. */
    public static function forgot(): void
    {
        if (Auth::check()) {
            Util::redirect(Router::url('settings') . '#password');
        }
        if (!Config::isInstalled()) {
            Util::redirect(Router::url('install'));
        }

        $notice = '';
        $error = '';
        $identifier = trim((string) ($_POST['identifier'] ?? ''));

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $result = Auth::requestReset($identifier);
            $result['ok'] ? ($notice = $result['message']) : ($error = $result['message']);
        }

        echo render('admin/login', [
            'mode' => 'forgot',
            'error' => $error,
            'notice' => $notice,
            'identifier' => $identifier,
            'canSend' => Auth::canSendReset(),
            'blocker' => Auth::resetBlocker(),
        ]);
    }

    /** Choix d'un nouveau mot de passe depuis le lien reçu par email. */
    public static function reset(): void
    {
        $token = (string) ($_GET['t'] ?? ($_POST['t'] ?? ''));
        $valid = Auth::resetTokenIsValid($token);
        $error = '';

        if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $password = (string) ($_POST['password'] ?? '');
            if (strlen($password) < 8) {
                $error = 'Choisissez un mot de passe d\'au moins 8 caractères.';
            } elseif ($password !== (string) ($_POST['password_confirm'] ?? '')) {
                $error = 'Les deux mots de passe ne correspondent pas.';
            } elseif (Auth::completeReset($token, $password)) {
                Flash::success('Mot de passe modifié. Vous êtes connecté.');
                Util::redirect(Router::url('dashboard'));
            } else {
                $error = 'Ce lien n\'est plus valide.';
                $valid = false;
            }
        }

        echo render('admin/login', [
            'mode' => 'reset',
            'error' => $error,
            'token' => $token,
            'valid' => $valid,
        ]);
    }

    public static function logout(): void
    {
        Auth::logout();
        Util::redirect(Router::url('login'));
    }

    // ------------------------------------------------------------- Tableaux

    public static function dashboard(): void
    {
        Auth::requireLogin();
        $data = Stats::dashboard();
        echo render('admin/dashboard', [
            'title' => 'Tableau de bord',
            'data' => $data,
            'funnel' => Stats::funnel($data),
            'activity' => Events::recent(15),
            'health' => self::health(),
        ]);
    }

    public static function prospects(): void
    {
        Auth::requireLogin();
        $filters = [
            'search' => (string) ($_GET['search'] ?? ''),
            'status' => (string) ($_GET['status'] ?? ''),
            'sort' => (string) ($_GET['sort'] ?? 'recent'),
        ];
        $doublon = trim((string) ($_GET['doublon'] ?? ''));
        echo render('admin/prospects', [
            'title' => 'Prospects',
            'rows' => Prospect::search($filters),
            'filters' => $filters,
            'counts' => Stats::dashboard()['by_status'],
            'rangs' => Prospect::ranksByDomain(),
            // Une adresse déjà suivie que l'on vient de soumettre : la liste
            // propose alors d'ouvrir la fiche ou d'en créer une seconde.
            'doublon' => $doublon,
            'doublonFiches' => $doublon === '' ? [] : Prospect::siblings(Util::domain($doublon)),
        ]);
    }

    public static function pipeline(): void
    {
        Auth::requireLogin();
        $columns = array_fill_keys(array_keys(Prospect::PIPELINE), []);
        foreach (Prospect::search(['sort' => 'recent']) as $row) {
            $status = (string) ($row['status'] ?? Prospect::NEW);
            $columns[$status][] = $row;
        }
        echo render('admin/pipeline', ['title' => 'Pipeline', 'columns' => $columns]);
    }

    // ------------------------------------------------------------- Prospect

    public static function prospect(): void
    {
        Auth::requireLogin();
        $prospect = Prospect::find((string) ($_GET['id'] ?? ''));
        if ($prospect === null) {
            Flash::error('Prospect introuvable.');
            Util::redirect(Router::url('prospects'));
        }
        $id = (string) $prospect['id'];
        $versions = Mockup::versions($id);

        echo render('admin/prospect', [
            'title' => Prospect::displayName($prospect),
            'p' => $prospect,
            'versions' => $versions,
            'currentVersion' => (string) ($prospect['mockup']['current'] ?? ''),
            'timeline' => Events::recent(40, $id),
            'sends' => self::sendsOf($id),
            'schedule' => Sequence::preview(),
            'mockupUrl' => Router::mockupUrl($prospect),
            'hasShot' => Screenshot::exists($id),
            'brokenShot' => Screenshot::hasBrokenFile($id),
            'hasManualSource' => Analyzer::hasStoredSource($id),
            'shotUrl' => Router::url('shot_admin', ['id' => $id, 'v' => time()]),
            'palette' => $prospect['palette'] ?? [],
            'actifs' => Assets::catalogue($id),
            'consommation' => Consumption::byVersion($id),
            // Les autres fiches du même site : c'est par là que passe une
            // comparaison entre deux modèles sur le même prospect.
            'fichesDuDomaine' => Prospect::siblings((string) $prospect['domain']),
        ]);
    }

    public static function prospectAdd(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();

        $url = Util::normalizeUrl((string) ($_POST['url'] ?? ''));
        if ($url === null) {
            Flash::error('URL invalide. Exemple attendu : monentreprise.fr');
            Util::redirect(Router::url('prospects'));
        }
        // Un domaine déjà suivi n'est plus un refus mais une question : suivre
        // deux fois le même site est le seul moyen de comparer deux maquettes
        // du même prospect. Le doublon reste explicite — on ne le crée jamais
        // par inadvertance.
        $existing = Prospect::findByUrl($url);
        if ($existing !== null && empty($_POST['force'])) {
            Util::redirect(Router::url('prospects', ['doublon' => $url]));
        }

        $prospect = Prospect::create($url);
        Flash::success($existing === null
            ? 'Prospect ajouté. Lancez l\'analyse du site.'
            : 'Seconde fiche créée pour ' . $prospect['domain']
                . ' : les deux sont indépendantes, y compris leurs maquettes et leur consommation.');
        Util::redirect(Router::url('prospect', ['id' => $prospect['id'], 'autorun' => 'analyze']));
    }

    public static function prospectSave(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();

        $id = (string) ($_POST['id'] ?? '');
        $updated = Prospect::update($id, static function (array $p): array {
            foreach (['company', 'first_name', 'last_name', 'phone', 'city', 'sector', 'siren', 'notes'] as $field) {
                if (isset($_POST[$field])) {
                    $p[$field] = trim((string) $_POST[$field]);
                }
            }
            $email = trim((string) ($_POST['email'] ?? ''));
            $p['email'] = $email === '' || Util::isEmail($email) ? $email : $p['email'];

            if (isset($_POST['monthly_price'])) {
                $price = (float) str_replace(',', '.', (string) $_POST['monthly_price']);
                $p['monthly_price'] = $price > 0 ? $price : $p['monthly_price'];
            }
            if (isset($_POST['status']) && array_key_exists((string) $_POST['status'], Prospect::PIPELINE)) {
                $p['status'] = (string) $_POST['status'];
            }
            if (isset($_POST['design_prompt'])) {
                $p['design_prompt'] = trim((string) $_POST['design_prompt']);
            }
            return $p;
        });

        if ($updated === null) {
            Flash::error('Prospect introuvable.');
            Util::redirect(Router::url('prospects'));
        }
        Flash::success('Fiche enregistrée.');
        Util::redirect(Router::url('prospect', ['id' => $id]));
    }

    public static function prospectDelete(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $id = (string) ($_POST['id'] ?? '');

        $prospect = Prospect::find($id);
        if ($prospect === null) {
            Flash::error('Prospect introuvable : il a peut-être déjà été supprimé.');
            Util::redirect(Router::url('prospects'));
        }

        Prospect::delete($id);
        Flash::success('« ' . Prospect::displayName($prospect) . ' » supprimé, maquettes comprises.');

        // Supprimer depuis une liste filtrée doit ramener à cette liste-là :
        // les filtres sont reconstruits ici plutôt que repris d'une URL fournie
        // par le formulaire, qui pourrait pointer ailleurs.
        Util::redirect(Router::url('prospects', [
            'search' => (string) ($_POST['search'] ?? ''),
            'status' => (string) ($_POST['status'] ?? ''),
            'sort' => (string) ($_POST['sort'] ?? ''),
        ]));
    }

    /**
     * Analyse à partir du code source collé à la main, pour les sites qui
     * refusent toute lecture automatique.
     */
    public static function prospectManual(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();

        $id = (string) ($_POST['id'] ?? '');

        $sources = [];
        foreach (['accueil', 'contact', 'legal', 'services'] as $role) {
            $value = trim((string) ($_POST['html_' . $role] ?? ''));
            if ($value !== '') {
                $sources[$role] = $value;
            }
        }

        if (!isset($sources['accueil'])) {
            Flash::error('Collez au minimum le code source de la page d\'accueil.');
            Util::redirect(Router::url('prospect', ['id' => $id]));
        }

        $result = Analyzer::runFromHtml($id, $sources);
        if (!$result['ok']) {
            Flash::error($result['error']);
        } else {
            $prospect = $result['prospect'];
            $audit = $prospect['audit'] ?? [];
            $analysis = $prospect['analysis'] ?? [];

            // Le collage laisse la même trace consultable qu'une analyse en flux.
            $steps = [];
            foreach (array_keys($sources) as $role) {
                $steps[] = ['message' => 'Page « ' . $role . ' » analysée', 'state' => 'done', 'at' => time()];
            }
            foreach ([
                'Société : ' . ($prospect['company'] ?: 'non identifiée'),
                'Email : ' . ($prospect['email'] ?: 'non trouvé'),
                'Téléphone : ' . ($prospect['phone'] ?: 'non trouvé'),
                'SIREN : ' . ($prospect['siren'] ?: 'non trouvé'),
                count($analysis['services'] ?? []) . ' prestation(s) relevée(s)',
                count($analysis['images'] ?? []) . ' photo(s) repérée(s)'
                    . (($analysis['logo'] ?? '') !== '' ? ', logo identifié' : ', aucun logo identifié'),
            ] as $ligne) {
                $steps[] = ['message' => $ligne, 'state' => 'done', 'at' => time()];
            }

            Prospect::update($id, static function (array $p) use ($steps, $audit): array {
                $p['last_run'] = [
                    'type' => 'Analyse depuis le code collé',
                    'at' => time(),
                    'ok' => true,
                    'conclusion' => 'Score ' . ($audit['score'] ?? '?') . '/100 — '
                        . ($audit['level'] ?? '') . ' · ' . count($audit['findings'] ?? []) . ' constat(s)',
                    'steps' => $steps,
                ];
                return $p;
            });

            Flash::success(count($sources) . ' page(s) analysée(s) — score '
                . ($audit['score'] ?? '?') . '/100. Le détail reste consultable sur la fiche.');
        }
        Util::redirect(Router::url('prospect', ['id' => $id]));
    }

    /** Relance l'enrichissement seul, sans refaire l'analyse complète. */
    public static function prospectEnrich(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $id = (string) ($_POST['id'] ?? '');
        $prospect = Prospect::find($id);
        if ($prospect === null) {
            Flash::error('Prospect introuvable.');
            Util::redirect(Router::url('prospects'));
        }
        $company = Enrich::lookupCompany(
            (string) ($prospect['siren'] ?? ''),
            (string) ($prospect['company'] ?? ''),
            (string) ($prospect['city'] ?? '')
        );
        if (!$company['ok']) {
            Flash::error('Base entreprise : ' . $company['error']);
        } else {
            Prospect::update($id, static function (array $p) use ($company): array {
                foreach (['company', 'siren', 'city', 'sector'] as $field) {
                    if (trim((string) ($p[$field] ?? '')) === '' && !empty($company['data'][$field])) {
                        $p[$field] = $company['data'][$field];
                    }
                }
                if (trim((string) ($p['first_name'] ?? '')) === '' && !empty($company['data']['director'])) {
                    [$first, $last] = Util::splitName((string) $company['data']['director']);
                    $p['first_name'] = $first;
                    $p['last_name'] = $last;
                }
                $p['enrichment']['company'] = $company['data'];
                return $p;
            });
            Flash::success('Fiche entreprise récupérée.');
        }
        Util::redirect(Router::url('prospect', ['id' => $id]));
    }

    /** Capture manuelle ou import d'une capture du site actuel. */
    public static function screenshot(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $id = (string) ($_POST['id'] ?? '');
        $prospect = Prospect::find($id);
        if ($prospect === null) {
            Flash::error('Prospect introuvable.');
            Util::redirect(Router::url('prospects'));
        }

        Screenshot::purgeIfInvalid($id);

        if (!empty($_FILES['capture']['name'])) {
            $result = Screenshot::storeUpload($id, $_FILES['capture']);
            $result['ok'] ? Flash::success('Capture importée.') : Flash::error($result['error']);
        } else {
            $result = Screenshot::capture($id, (string) $prospect['url']);
            $result['ok'] ? Flash::success('Capture réalisée.') : Flash::error($result['error']);
        }
        Util::redirect(Router::url('prospect', ['id' => $id]));
    }

    /** Sert le portrait dans l'interface. */
    public static function portraitAdmin(): void
    {
        Auth::requireLogin();
        $path = Portrait::path();
        if ($path === null) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: ' . Portrait::mediaType($path));
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
    }

    /** Sert la capture dans l'interface (elle est stockée hors racine web). */
    public static function shotAdmin(): void
    {
        Auth::requireLogin();
        $path = Screenshot::usablePath((string) ($_GET['id'] ?? ''));
        if ($path === null) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: ' . Screenshot::mediaType($path));
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
    }

    // -------------------------------------------------------------- Maquette

    /** Prévisualisation d'une page de maquette dans l'interface. */
    public static function mockupPreview(): void
    {
        Auth::requireLogin();
        $id = (string) ($_GET['id'] ?? '');
        $prospect = Prospect::find($id);
        if ($prospect === null) {
            http_response_code(404);
            exit('Prospect introuvable.');
        }
        $version = (string) ($_GET['v'] ?? ($prospect['mockup']['current'] ?? ''));
        $page = Mockup::safePage((string) ($_GET['p'] ?? 'accueil'));
        $html = Mockup::readPage($id, $version, $page);
        if ($html === null) {
            http_response_code(404);
            exit('Page de maquette introuvable.');
        }

        $links = [];
        foreach (array_keys(Mockup::PAGES) as $key) {
            $links[$key] = Router::url('mockup_preview', ['id' => $id, 'v' => $version, 'p' => $key]);
        }
        $ressources = Mockup::resources(Mockup::assetPattern(
            Router::url('mockup_asset', ['id' => $id, 'f' => '{f}'])
        ));
        // La charte servie est celle de la fiche, pas celle figée à la génération.
        $ressources['palette'] = Generator::palette($prospect);

        header('Content-Type: text/html; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow');
        echo Mockup::forPublic($html, $links, '', $ressources);
    }

    /**
     * Enregistre les trois couleurs réglables de la fiche.
     *
     * Elles sont conservées à part de la palette calculée : une nouvelle
     * analyse recalcule tout le reste, mais ne défait pas une correction faite
     * à la main.
     */
    public static function prospectPalette(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();

        $id = (string) ($_POST['id'] ?? '');
        $prospect = Prospect::find($id);
        if ($prospect === null) {
            Flash::error('Prospect introuvable.');
            Util::redirect(Router::url('prospects'));
        }

        if (($_POST['action'] ?? '') === 'reset') {
            $updated = Prospect::update($id, static function (array $p): array {
                unset($p['palette_manuelle']);
                $p['palette'] = Palette::forAnalysis($p['analysis'] ?? []);
                return $p;
            });
            Flash::success('Couleurs remises sur ce qui a été relevé du site.');
            Util::redirect(Router::url('prospect', ['id' => (string) ($updated['id'] ?? $id)]));
        }

        $manuelle = [];
        $refusees = [];
        foreach (['marque', 'titres', 'corps'] as $cle) {
            $saisie = trim((string) ($_POST['couleur_' . $cle] ?? ''));
            if ($saisie === '') {
                continue;
            }
            $normalisee = Palette::normalize($saisie);
            if ($normalisee === null) {
                $refusees[] = $saisie;
                continue;
            }
            $manuelle[$cle] = $normalisee;
        }

        if ($refusees !== []) {
            Flash::error('Couleur non reconnue : ' . implode(', ', $refusees) . '. Attendu : #a1b2c3.');
            Util::redirect(Router::url('prospect', ['id' => $id]));
        }

        // La disposition de menu voyage avec la charte : elle s'applique au
        // moment de servir la page, comme les couleurs, et ne demande donc
        // aucune régénération.
        $menu = (string) ($_POST['menu'] ?? '');
        $menu = isset(Mockup::MENUS[$menu]) ? $menu : '';

        $updated = Prospect::update($id, static function (array $p) use ($manuelle, $menu): array {
            $p['palette_manuelle'] = $manuelle;
            $p['palette'] = Palette::forAnalysis($p['analysis'] ?? [], $manuelle);
            if ($menu !== '') {
                $p['palette']['menu'] = $menu;
                $p['palette_manuelle']['menu'] = $menu;
            }
            return $p;
        });

        $palette = $updated['palette'] ?? [];
        $fragiles = [];
        foreach (Palette::reglages($palette) as $reglage) {
            if (!Palette::lisible($reglage['ratio'])) {
                $fragiles[] = mb_strtolower($reglage['label']) . ' (' . number_format((float) $reglage['ratio'], 2, ',', ' ') . ':1)';
            }
        }

        if ($fragiles !== []) {
            Flash::error('Charte enregistrée, mais sous le seuil de lisibilité : '
                . implode(', ', $fragiles) . '. Il en faut 4,5:1 sur les deux fonds du socle.');
        } else {
            // La charte s'applique au moment de servir la page : dire « à la
            // prochaine génération » ferait régénérer pour rien.
            Flash::success('Charte enregistrée. Elle s\'applique immédiatement à la maquette en ligne,'
                . ' sans régénération.');
        }
        Util::redirect(Router::url('prospect', ['id' => $id]));
    }

    /** Dépôt manuel du logo, quand la lecture du site ne l'a pas trouvé. */
    public static function prospectLogo(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();

        $id = (string) ($_POST['id'] ?? '');
        $prospect = Prospect::find($id);
        if ($prospect === null) {
            Flash::error('Prospect introuvable.');
            Util::redirect(Router::url('prospects'));
        }
        $id = (string) $prospect['id'];

        if (($_POST['action'] ?? '') === 'delete') {
            Assets::forgetLogo($id);
            Flash::success('Logo retiré.');
            Util::redirect(Router::url('prospect', ['id' => $id]));
        }

        $result = Assets::replaceLogo($id, $_FILES['logo'] ?? []);
        if ($result['ok']) {
            Events::log($id, 'assets', ['message' => 'Logo déposé à la main']);
            Flash::success('Logo enregistré. Il sera repris dans les maquettes générées ensuite.');
        } else {
            Flash::error($result['error']);
        }
        Util::redirect(Router::url('prospect', ['id' => $id]));
    }

    /**
     * Gestion du catalogue d'actifs : en retirer, en ajouter.
     *
     * Le catalogue est ce que le modèle reçoit, et le contrôle de conformité
     * refuse toute photo qui n'y figure pas. Le tenir à la main est donc le
     * seul moyen de peser sur ce que la génération suivante affichera — sans
     * quoi il faudrait relancer et espérer.
     */
    public static function prospectAssets(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();

        $id = (string) ($_POST['id'] ?? '');
        $prospect = Prospect::find($id);
        if ($prospect === null) {
            Flash::error('Prospect introuvable.');
            Util::redirect(Router::url('prospects'));
        }
        $id = (string) $prospect['id'];
        $retour = Router::url('prospect', ['id' => $id]) . '#actifs';

        switch ((string) ($_POST['action'] ?? '')) {
            case 'retirer':
                $result = Assets::removeImage($id, (string) ($_POST['fichier'] ?? ''));
                if ($result['ok']) {
                    Events::log($id, 'assets', ['message' => 'Photo écartée du catalogue']);
                    Flash::success('Photo écartée. Elle ne reviendra pas, même après une nouvelle analyse,'
                        . ' et n\'apparaîtra pas dans les maquettes générées ensuite.');
                } else {
                    Flash::error($result['error']);
                }
                break;

            case 'ajouter_url':
                $result = Assets::addImageByUrl($id, (string) ($_POST['url'] ?? ''));
                $result['ok']
                    ? Flash::success('Image ajoutée au catalogue par son adresse.')
                    : Flash::error($result['error']);
                break;

            case 'ajouter_fichier':
                $result = Assets::addImage($id, $_FILES['photo'] ?? []);
                $result['ok']
                    ? Flash::success('Photo déposée. Elle survivra aux prochaines analyses.')
                    : Flash::error($result['error']);
                break;

            default:
                Flash::error('Action inconnue.');
        }
        Util::redirect($retour);
    }

    /** Sert un actif de maquette dans la prévisualisation de l'administration. */
    public static function mockupAsset(): void
    {
        Auth::requireLogin();
        $fichier = (string) ($_GET['f'] ?? '');
        // L'emplacement à pourvoir n'est pas un fichier : il est dessiné ici,
        // pour qu'un bloc illustré sans photo reste visible et réparable.
        if (Assets::isPlaceholder($fichier)) {
            header('Content-Type: image/svg+xml');
            header('Cache-Control: public, max-age=86400');
            echo Assets::placeholderSvg(true);
            exit;
        }

        $path = Assets::pathOf((string) ($_GET['id'] ?? ''), $fichier);
        if ($path === null) {
            http_response_code(404);
            exit;
        }
        // Un SVG est un document : servi depuis notre domaine, il pourrait
        // exécuter du script. Il est déjà nettoyé au dépôt ; cet en-tête ferme
        // ce qui aurait pu passer au travers.
        header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; img-src data:');
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: ' . Assets::mediaType($path));
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
    }

    /**
     * Éditeur de maquette : le panneau de champs et l'aperçu.
     *
     * Tout s'y modifie sans repasser par le modèle — textes, images, liens,
     * couleurs, logo. C'est ce qui permet de rattraper une maquette en cinq
     * minutes au lieu de relancer une génération et d'espérer mieux.
     */
    public static function mockupEdit(): void
    {
        Auth::requireLogin();
        $id = (string) ($_GET['id'] ?? '');
        $prospect = Prospect::find($id);
        if ($prospect === null) {
            Flash::error('Prospect introuvable.');
            Util::redirect(Router::url('prospects'));
        }
        $id = (string) $prospect['id'];

        $version = (string) ($_GET['v'] ?? ($prospect['mockup']['current'] ?? ''));
        if ($version === '' || !Mockup::isComplete($id, $version)) {
            Flash::error('Aucune maquette complète à modifier.');
            Util::redirect(Router::url('prospect', ['id' => $id]));
        }

        $page = Mockup::safePage((string) ($_GET['p'] ?? 'accueil'));
        $html = (string) Mockup::readPage($id, $version, $page);

        echo render('admin/editor', [
            'title' => 'Éditer la maquette — ' . Prospect::displayName($prospect),
            'p' => $prospect,
            'id' => $id,
            'version' => $version,
            'page' => $page,
            'groupes' => Editor::groupes($html),
            'palette' => Generator::palette($prospect),
            'actifs' => Assets::catalogue($id),
            'previewUrl' => Router::url('mockup_preview', ['id' => $id, 'v' => $version, 'p' => $page]),
            // Une image posée en direct dans l'aperçu doit pointer l'adresse
            // servie, pas le chemin relatif du fichier enregistré.
            'assetPattern' => Mockup::assetPattern(Router::url('mockup_asset', ['id' => $id, 'f' => '{f}'])),
        ]);
    }

    /** Enregistre les modifications d'une page. */
    public static function mockupEditSave(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();

        $id = (string) ($_POST['id'] ?? '');
        $prospect = Prospect::find($id);
        if ($prospect === null) {
            Flash::error('Prospect introuvable.');
            Util::redirect(Router::url('prospects'));
        }
        $id = (string) $prospect['id'];
        $version = (string) ($_POST['v'] ?? '');
        $page = Mockup::safePage((string) ($_POST['p'] ?? 'accueil'));

        $html = Mockup::readPage($id, $version, $page);
        if ($html === null) {
            Flash::error('Page de maquette introuvable.');
            Util::redirect(Router::url('prospect', ['id' => $id]));
        }

        $patch = [];
        foreach ((array) ($_POST['champs'] ?? []) as $cle => $valeur) {
            if (is_string($cle) && is_string($valeur)) {
                $patch[$cle] = trim($valeur);
            }
        }

        $result = Editor::apply($html, $patch);
        if (!Mockup::writePage($id, $version, $page, $result['html'])) {
            Flash::error('Écriture impossible dans data/mockups. Vérifiez les droits du dossier.');
            Util::redirect(Router::url('mockup_edit', ['id' => $id, 'v' => $version, 'p' => $page]));
        }

        // Les couleurs valent pour les trois pages : on les range avec les
        // réglages manuels de la fiche, d'où elles seront servies à chaque
        // affichage, y compris d'une maquette déjà envoyée.
        $manuelle = [];
        foreach (['marque', 'titres', 'corps'] as $cle) {
            $couleur = Palette::normalize((string) ($_POST['couleur_' . $cle] ?? ''));
            if ($couleur !== null) {
                $manuelle[$cle] = $couleur;
            }
        }
        $charteChangee = $manuelle !== [] && $manuelle !== (array) ($prospect['palette_manuelle'] ?? []);
        if ($charteChangee) {
            Prospect::update($id, static function (array $fiche) use ($manuelle): array {
                $fiche['palette_manuelle'] = $manuelle;
                $fiche['palette'] = Palette::forAnalysis($fiche['analysis'] ?? [], $manuelle);
                return $fiche;
            });
        }

        Events::log($id, 'edit', ['page' => $page, 'version' => $version, 'champs' => $result['appliques']]);
        $quoi = match (true) {
            $result['appliques'] === 0 && !$charteChangee => 'Aucune modification à enregistrer',
            $result['appliques'] === 0 => 'Charte mise à jour sur les trois pages',
            default => $result['appliques'] . ' modification(s) enregistrée(s) sur « ' . Mockup::PAGES[$page] . ' »'
                . ($charteChangee ? ', charte mise à jour sur les trois pages' : ''),
        };
        Flash::success($quoi . '.');
        Util::redirect(Router::url('mockup_edit', ['id' => $id, 'v' => $version, 'p' => $page]));
    }

    /**
     * Dépôt d'une image depuis l'éditeur.
     *
     * Répond en JSON : l'éditeur remplace l'image dans l'aperçu sans recharger
     * la page, ce qui garde les modifications en cours de saisie.
     */
    public static function mockupMedia(): void
    {
        Auth::requireLogin();
        header('Content-Type: application/json; charset=UTF-8');

        $repondre = static function (array $data, int $code = 200): never {
            http_response_code($code);
            echo json_encode($data, JSON_UNESCAPED_UNICODE);
            exit;
        };

        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            $repondre(['ok' => false, 'error' => 'Session expirée. Rechargez la page.'], 419);
        }

        $prospect = Prospect::find((string) ($_POST['id'] ?? ''));
        if ($prospect === null) {
            $repondre(['ok' => false, 'error' => 'Prospect introuvable.'], 404);
        }

        $result = Assets::addImage((string) $prospect['id'], $_FILES['media'] ?? []);
        if (!$result['ok']) {
            $repondre(['ok' => false, 'error' => $result['error']], 422);
        }

        $repondre([
            'ok' => true,
            'src' => $result['src'],
            'url' => Router::url('mockup_asset', ['id' => (string) $prospect['id'], 'f' => basename($result['src'])]),
        ]);
    }

    /**
     * Dérive la palette pour l'aperçu de l'éditeur.
     *
     * Les variantes ne sont pas recalculées en JavaScript : le contraste se
     * mesure ici, et une seconde implémentation finirait par diverger de
     * celle qui produit réellement les maquettes.
     */
    public static function paletteDerive(): void
    {
        Auth::requireLogin();
        header('Content-Type: application/json; charset=UTF-8');

        $palette = Palette::derive(
            (string) ($_GET['marque'] ?? ''),
            (string) ($_GET['titres'] ?? ''),
            (string) ($_GET['corps'] ?? '')
        );

        echo json_encode([
            'ok' => true,
            'jetons' => [
                '--marque' => $palette['marque'],
                '--marque-fonce' => $palette['marque_fonce'],
                '--marque-texte' => $palette['marque_texte'],
                '--marque-claire' => $palette['marque_claire'],
                '--marque-voile' => $palette['marque_voile'],
                '--encre' => $palette['titres'],
                '--texte' => $palette['corps'],
                '--texte-doux' => $palette['corps_doux'],
            ],
            'mesures' => $palette['mesures'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** Résultats de la dernière comparaison de modèles. */
    public static function compare(): void
    {
        Auth::requireLogin();
        $id = (string) ($_GET['id'] ?? '');
        $prospect = Prospect::find($id);
        if ($prospect === null) {
            Flash::error('Prospect introuvable.');
            Util::redirect(Router::url('prospects'));
        }
        $id = (string) $prospect['id'];

        echo render('admin/compare', [
            'title' => 'Comparer les modèles — ' . Prospect::displayName($prospect),
            'p' => $prospect,
            'id' => $id,
            'rapport' => Compare::report($id),
            'candidats' => Compare::defaults(),
        ]);
    }

    /** Sert la page produite par un candidat, pour l'aperçu côte à côte. */
    public static function comparePreview(): void
    {
        Auth::requireLogin();
        $id = (string) ($_GET['id'] ?? '');
        $path = Compare::pagePath($id, (string) ($_GET['c'] ?? ''));
        if ($path === null) {
            http_response_code(404);
            exit('Page de comparaison introuvable.');
        }

        $html = (string) file_get_contents($path);
        $ressources = Mockup::resources(Mockup::assetPattern(
            Router::url('mockup_asset', ['id' => $id, 'f' => '{f}'])
        ));
        $ressources['palette'] = Generator::palette(Prospect::find($id) ?? []);

        header('Content-Type: text/html; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow');
        echo Mockup::forPublic($html, [], '', $ressources);
    }

    /** Télécharge une version complète sous forme de fichiers HTML concaténés. */
    public static function mockupDownload(): void
    {
        Auth::requireLogin();
        $id = (string) ($_GET['id'] ?? '');
        $prospect = Prospect::find($id);
        $version = (string) ($_GET['v'] ?? ($prospect['mockup']['current'] ?? ''));
        if ($prospect === null || !Mockup::isComplete($id, $version)) {
            http_response_code(404);
            exit('Maquette introuvable.');
        }

        $page = Mockup::safePage((string) ($_GET['p'] ?? 'accueil'));
        $html = (string) Mockup::readPage($id, $version, $page);
        $name = Util::slug(Prospect::displayName($prospect)) . '-' . $page . '.html';

        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        echo $html;
    }

    public static function mockupValidate(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $id = (string) ($_POST['id'] ?? '');
        $validate = ($_POST['action'] ?? 'validate') === 'validate';

        $updated = Prospect::update($id, static function (array $p) use ($validate): array {
            $p['mockup']['validated'] = $validate;
            if ($validate && in_array($p['status'] ?? '', [Prospect::ANALYZED, Prospect::MOCKUP], true)) {
                $p['status'] = Prospect::VALIDATED;
            }
            if (!$validate && ($p['status'] ?? '') === Prospect::VALIDATED) {
                $p['status'] = Prospect::MOCKUP;
            }
            return $p;
        });

        if ($updated === null) {
            Flash::error('Prospect introuvable.');
            Util::redirect(Router::url('prospects'));
        }
        if ($validate) {
            Events::log($id, 'mockup_validated', ['version' => $updated['mockup']['current'] ?? '']);
            Flash::success('Maquette validée. Vous pouvez lancer la séquence.');
        } else {
            Sequence::stop($id, 'Maquette dévalidée.');
            Flash::info('Validation retirée.');
        }
        Util::redirect(Router::url('prospect', ['id' => $id]));
    }

    public static function mockupUseVersion(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $id = (string) ($_POST['id'] ?? '');
        $version = (string) ($_POST['version'] ?? '');
        if (!Mockup::isComplete($id, $version)) {
            Flash::error('Cette version est incomplète.');
            Util::redirect(Router::url('prospect', ['id' => $id]));
        }
        Prospect::update($id, static function (array $p) use ($version): array {
            $p['mockup']['current'] = $version;
            return $p;
        });
        Flash::success('Version ' . $version . ' active. C\'est elle que verra le prospect.');
        Util::redirect(Router::url('prospect', ['id' => $id]));
    }

    public static function mockupDeleteVersion(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $id = (string) ($_POST['id'] ?? '');
        $version = (string) ($_POST['version'] ?? '');
        $prospect = Prospect::find($id);
        if ($prospect !== null && ($prospect['mockup']['current'] ?? '') === $version) {
            Flash::error('Impossible de supprimer la version active.');
            Util::redirect(Router::url('prospect', ['id' => $id]));
        }
        Mockup::deleteVersion($id, $version);
        Prospect::update($id, static function (array $p) use ($version): array {
            unset($p['mockup']['versions'][$version]);
            return $p;
        });
        Flash::success('Version ' . $version . ' supprimée.');
        Util::redirect(Router::url('prospect', ['id' => $id]));
    }

    // -------------------------------------------------------------- Séquence

    public static function sequenceStart(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $id = (string) ($_POST['id'] ?? '');
        $result = Sequence::start($id);
        $result['ok'] ? Flash::success('Séquence lancée. Le premier email partira au prochain créneau.')
            : Flash::error($result['error']);
        Util::redirect(Router::url('prospect', ['id' => $id]));
    }

    public static function sequenceStop(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $id = (string) ($_POST['id'] ?? '');
        Sequence::stop($id, 'Arrêt manuel depuis la fiche prospect.');
        Flash::info('Séquence arrêtée.');
        Util::redirect(Router::url('prospect', ['id' => $id]));
    }

    /** Envoi immédiat d'une étape, sans attendre le créneau du cron. */
    public static function sendNow(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $id = (string) ($_POST['id'] ?? '');
        $step = Util::clamp((int) ($_POST['step'] ?? 1), 1, Sequence::LAST_STEP);
        $prospect = Prospect::find($id);
        if ($prospect === null) {
            Flash::error('Prospect introuvable.');
            Util::redirect(Router::url('prospects'));
        }

        $result = Mailer::sendStep($prospect, $step);
        if (!$result['ok']) {
            Flash::error('Envoi impossible : ' . $result['error']);
            Util::redirect(Router::url('prospect', ['id' => $id]));
        }

        Prospect::update($id, static function (array $p) use ($step, $result): array {
            $p['sequence']['step'] = max((int) ($p['sequence']['step'] ?? 0), $step);
            $p['sequence']['sent'][] = ['step' => $step, 'token' => $result['token'], 'at' => time()];
            if ($p['status'] === Prospect::VALIDATED) {
                $p['status'] = Prospect::SEQUENCE;
            }
            if ($step < Sequence::LAST_STEP && !empty($p['sequence']['active'])) {
                $delays = (array) Config::get('sequence.delays_days', [0, 4, 8]);
                $p['sequence']['next_at'] = Sequence::nextSlot(time() + ((int) ($delays[$step] ?? 4)) * 86400);
            }
            return $p;
        });
        Flash::success('Email ' . $step . ' envoyé à ' . $prospect['email'] . '.');
        Util::redirect(Router::url('prospect', ['id' => $id]));
    }

    /** Aperçu d'un email tel qu'il sera reçu, variables résolues. */
    public static function emailPreview(): void
    {
        Auth::requireLogin();
        $prospect = Prospect::find((string) ($_GET['id'] ?? ''));
        if ($prospect === null) {
            http_response_code(404);
            exit('Prospect introuvable.');
        }
        $step = Util::clamp((int) ($_GET['step'] ?? 1), 1, Sequence::LAST_STEP);
        $rendered = Mailer::render($prospect, $step);
        header('Content-Type: text/html; charset=UTF-8');
        echo $rendered['html'];
    }

    // ---------------------------------------------------------------- Import

    public static function import(): void
    {
        Auth::requireLogin();
        echo render('admin/import', ['title' => 'Import en masse']);
    }

    public static function importRun(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();

        $raw = (string) ($_POST['lines'] ?? '');
        if (!empty($_FILES['fichier']['tmp_name']) && is_uploaded_file($_FILES['fichier']['tmp_name'])) {
            $contents = @file_get_contents($_FILES['fichier']['tmp_name']);
            if ($contents !== false) {
                $raw .= "\n" . $contents;
            }
        }

        $created = 0;
        $skipped = 0;
        $invalid = 0;

        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^(url|site|domaine)[,;]/i', $line)) {
                continue;
            }
            $columns = array_map('trim', preg_split('/[;,\t]/', $line) ?: []);
            $url = Util::normalizeUrl($columns[0] ?? '');
            if ($url === null) {
                $invalid++;
                continue;
            }
            if (Prospect::findByUrl($url) !== null) {
                $skipped++;
                continue;
            }

            $prospect = Prospect::create($url);
            $email = (string) ($columns[1] ?? '');
            $prospect['email'] = Util::isEmail($email) ? $email : '';
            $prospect['first_name'] = (string) ($columns[2] ?? '');
            $prospect['last_name'] = (string) ($columns[3] ?? '');
            $prospect['company'] = (string) ($columns[4] ?? '');
            $price = (float) str_replace(',', '.', (string) ($columns[5] ?? ''));
            if ($price > 0) {
                $prospect['monthly_price'] = $price;
            }
            Prospect::save($prospect);
            $created++;
        }

        $message = $created . ' prospect(s) ajouté(s)';
        if ($skipped > 0) {
            $message .= ', ' . $skipped . ' doublon(s) ignoré(s)';
        }
        if ($invalid > 0) {
            $message .= ', ' . $invalid . ' ligne(s) invalide(s)';
        }
        Flash::success($message . '.');

        if (Config::get('batch.auto_analyze', true)) {
            Flash::info('Les analyses seront traitées automatiquement par le cron, ou manuellement depuis chaque fiche.');
        }
        Util::redirect(Router::url('prospects'));
    }

    // -------------------------------------------------------------- Modèles

    public static function templates(): void
    {
        Auth::requireLogin();
        echo render('admin/templates', [
            'title' => 'Modèles d\'emails',
            'templates' => Templates::all(),
            'variables' => Templates::variables(),
            'syntax' => Templates::syntaxHelp(),
        ]);
    }

    public static function templateSave(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $step = Util::clamp((int) ($_POST['step'] ?? 1), 1, 3);

        if (($_POST['action'] ?? '') === 'reset') {
            Templates::reset($step);
            Flash::info('Modèle ' . $step . ' réinitialisé.');
        } else {
            Templates::save($step, [
                'subject' => (string) ($_POST['subject'] ?? ''),
                'body' => (string) ($_POST['body'] ?? ''),
                'enabled' => !empty($_POST['enabled']),
            ]);
            Flash::success('Modèle ' . $step . ' enregistré.');
        }
        Util::redirect(Router::url('templates') . '#email-' . $step);
    }

    // -------------------------------------------------------------- Réglages

    public static function settings(): void
    {
        Auth::requireLogin();
        Models::refreshIfStale();

        echo render('admin/settings', [
            'title' => 'Réglages',
            'config' => Config::all(),
            'models' => Models::catalog(),
            'modelsFetchedAt' => Models::fetchedAt(),
            'profile' => Models::profile(),
            'spent' => Models::spentSoFar(),
            'modes' => Enrich::modes(),
            'providers' => Screenshot::PROVIDERS,
            'health' => self::health(),
            'cronUrl' => Config::baseUrl() . '/index.php?r=cron&key=' . rawurlencode((string) Config::get('app.cron_key', '')),
            'hasPortrait' => Portrait::exists(),
            'portraitUrl' => Router::url('portrait_admin', ['v' => time()]),
        ]);
    }

    public static function settingsSave(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();

        $post = $_POST;
        $patch = [
            'app' => [
                'name' => trim((string) ($post['app_name'] ?? 'Prospect Studio')),
                'base_url' => rtrim(trim((string) ($post['base_url'] ?? '')), '/'),
                'pretty_urls' => !empty($post['pretty_urls']),
                'timezone' => (string) ($post['timezone'] ?? 'Europe/Paris'),
                'signature' => (string) ($post['signature'] ?? ''),
            ],
            'ai' => [
                'provider' => Ai::normalize((string) ($post['ai_provider'] ?? '')),
                'steps' => [
                    'lecture' => self::stepChoice($post, 'lecture'),
                    'brief' => self::stepChoice($post, 'brief'),
                    'pages' => self::stepChoice($post, 'pages'),
                ],
            ],
            'deepseek' => [
                'tarifs' => self::tarifsSaisis($post),
                'model' => trim((string) ($post['deepseek_model'] ?? DeepSeek::DEFAUT)) ?: DeepSeek::DEFAUT,
                'max_tokens' => Util::clamp((int) ($post['deepseek_max_tokens'] ?? 8000), 2000, 64000),
            ],
            'gemini' => [
                'model' => trim((string) ($post['gemini_model'] ?? Gemini::DEFAUT)) ?: Gemini::DEFAUT,
                'max_tokens' => Util::clamp((int) ($post['gemini_max_tokens'] ?? 8000), 2000, 64000),
            ],
            'claude' => [
                'model' => self::chosenModel($post),
                'effort' => (string) ($post['claude_effort'] ?? 'high'),
                'max_tokens' => Util::clamp((int) ($post['claude_max_tokens'] ?? 24000), 4000, 64000),
            ],
            'design' => [
                'global_prompt' => (string) ($post['design_prompt'] ?? ''),
                'allow_google_fonts' => !empty($post['allow_google_fonts']),
                'use_site_images' => !empty($post['use_site_images']),
                'assets_mode' => ($post['assets_mode'] ?? '') === 'copie' ? 'copie' : 'liens',
            ],
            'billing' => [
                'eur_rate' => max(0.0, min(10.0, (float) str_replace(',', '.', (string) ($post['eur_rate'] ?? 0)))),
                'rate_note' => trim((string) ($post['rate_note'] ?? '')),
            ],
            'offer' => [
                'monthly_price' => (float) str_replace(',', '.', (string) ($post['monthly_price'] ?? 79)),
                'currency' => (string) ($post['currency'] ?? '€'),
                'included' => array_values(array_filter(array_map(
                    'trim',
                    preg_split('/\r\n|\r|\n/', (string) ($post['included'] ?? '')) ?: []
                ), static fn (string $line): bool => $line !== '')),
            ],
            'smtp' => [
                'host' => trim((string) ($post['smtp_host'] ?? '')),
                'port' => Util::clamp((int) ($post['smtp_port'] ?? 587), 1, 65535),
                'security' => (string) ($post['smtp_security'] ?? 'tls'),
                'user' => trim((string) ($post['smtp_user'] ?? '')),
                'from_email' => trim((string) ($post['from_email'] ?? '')),
                'from_name' => trim((string) ($post['from_name'] ?? '')),
                'reply_to' => trim((string) ($post['reply_to'] ?? '')),
                'verify_peer' => !empty($post['verify_peer']),
            ],
            'sequence' => [
                'enabled' => !empty($post['sequence_enabled']),
                'delays_days' => [
                    Util::clamp((int) ($post['delay_1'] ?? 0), 0, 90),
                    Util::clamp((int) ($post['delay_2'] ?? 4), 0, 90),
                    Util::clamp((int) ($post['delay_3'] ?? 8), 0, 90),
                ],
                'daily_limit' => Util::clamp((int) ($post['daily_limit'] ?? 40), 1, 500),
                'send_days' => array_map('intval', (array) ($post['send_days'] ?? [1, 2, 3, 4, 5])),
                'send_from' => (string) ($post['send_from'] ?? '09:00'),
                'send_to' => (string) ($post['send_to'] ?? '18:00'),
                'min_gap_seconds' => Util::clamp((int) ($post['min_gap'] ?? 120), 0, 3600),
                'stop_on_click' => !empty($post['stop_on_click']),
                'stop_on_view' => !empty($post['stop_on_view']),
            ],
            'enrichment' => [
                'mode' => (string) ($post['enrich_mode'] ?? 'site'),
            ],
            'screenshot' => [
                'provider' => (string) ($post['shot_provider'] ?? 'thumio'),
                'custom_template' => trim((string) ($post['shot_custom'] ?? '')),
                'auto' => !empty($post['shot_auto']),
                'send_to_model' => !empty($post['shot_to_model']),
            ],
            'about' => [
                'enabled' => !empty($post['about_enabled']),
                'title' => trim((string) ($post['about_title'] ?? 'Qui suis-je')),
                'name' => trim((string) ($post['about_name'] ?? '')),
                'role' => trim((string) ($post['about_role'] ?? '')),
                'bio' => trim((string) ($post['about_bio'] ?? '')),
                'quote' => trim((string) ($post['about_quote'] ?? '')),
                'points' => array_values(array_filter(array_map(
                    'trim',
                    preg_split('/\r\n|\r|\n/', (string) ($post['about_points'] ?? '')) ?: []
                ), static fn (string $line): bool => $line !== '')),
                'phone' => trim((string) ($post['about_phone'] ?? '')),
                'whatsapp' => trim((string) ($post['about_whatsapp'] ?? '')),
                'zone' => trim((string) ($post['about_zone'] ?? '')),
                'site_url' => trim((string) ($post['about_site_url'] ?? '')),
                'site_label' => trim((string) ($post['about_site_label'] ?? '')),
            ],
            'alerts' => [
                'email' => trim((string) ($post['alert_email'] ?? '')),
                'on_interest' => !empty($post['alert_interest']),
                'on_view' => !empty($post['alert_view']),
            ],
            'scraper' => [
                'user_agent' => trim((string) ($post['user_agent'] ?? '')),
                'retry_blocked' => !empty($post['retry_blocked']),
            ],
            'batch' => [
                'auto_analyze' => !empty($post['auto_analyze']),
                'auto_generate' => !empty($post['auto_generate']),
                'per_run' => Util::clamp((int) ($post['batch_per_run'] ?? 3), 1, 20),
            ],
        ];

        // Ce qui a RÉELLEMENT été écrit : une clé refusée ne doit pas être
        // annoncée comme enregistrée deux lignes après son refus.
        $secretsEcrits = [];

        // Les secrets ne sont réécrits que si un nouveau est fourni : le
        // formulaire n'affiche jamais la valeur en clair.
        //
        // Une valeur trop courte est refusée plutôt qu'enregistrée. Ce n'est pas
        // de la pédanterie : les champs sont de type « password », et un
        // gestionnaire de mots de passe du navigateur les remplit volontiers
        // avec le mot de passe du site, malgré autocomplete="off". La clé API
        // se trouve alors remplacée par autre chose sans que personne y ait
        // touché, et l'API répond 401 sur une clé pourtant valide.
        foreach ([
            'claude_api_key' => 'claude.api_key',
            'deepseek_api_key' => 'deepseek.api_key',
            'gemini_api_key' => 'gemini.api_key',
            'smtp_pass' => 'smtp.pass',
            'pappers_key' => 'enrichment.pappers_api_key',
            'shot_key' => 'screenshot.api_key',
        ] as $field => $path) {
            $value = trim((string) ($post[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            [$section, $key] = explode('.', $path);

            // Le mot de passe SMTP, lui, est un vrai mot de passe : il peut être
            // court, et la garde ne s'applique pas.
            if ($section !== 'smtp' && strlen($value) < 20) {
                Flash::error('Clé « ' . $field . ' » NON enregistrée : ' . strlen($value)
                    . ' caractères seulement, ce n\'est pas une clé API. La clé précédente est conservée.'
                    . ' Si votre navigateur a rempli ce champ tout seul, videz-le avant d\'enregistrer.');
                continue;
            }
            $patch[$section][$key] = $value;
            // La date de la dernière saisie : elle dit tout de suite si un
            // enregistrement a remplacé une clé qu'on croyait intacte.
            $patch[$section][$key . '_at'] = time();
            $secretsEcrits[] = $section;
        }

        // L'écriture peut échouer sans lever d'erreur — un dossier data/
        // déposé par FTP sous un autre compte que celui qui exécute PHP suffit.
        // Annoncer « enregistré » dans ce cas envoie chercher la panne du côté
        // de la clé API alors que rien n'a jamais été stocké.
        if (!Config::merge($patch)) {
            Flash::error('Rien n\'a pu être enregistré : ' . Config::raisonEcritureImpossible() . '.');
            Util::redirect(Router::url('settings'));
        }

        if (!empty($_FILES['portrait']['name'])) {
            $upload = Portrait::store($_FILES['portrait']);
            $upload['ok'] ? Flash::success('Portrait enregistré.') : Flash::error('Portrait : ' . $upload['error']);
        }
        if (!empty($post['remove_portrait'])) {
            Portrait::clear();
            Flash::info('Portrait retiré.');
        }

        if ((string) Config::get('app.cron_key', '') === '') {
            Config::set('app.cron_key', Util::token(16));
        }

        $newIdentifier = strtolower(trim((string) ($post['login_email'] ?? '')));
        if ($newIdentifier !== '' && $newIdentifier !== Auth::identifier()) {
            if (Util::isEmail($newIdentifier)) {
                Config::set('app.email', $newIdentifier);
                Flash::success('Identifiant de connexion mis à jour : ' . $newIdentifier);
            } else {
                Flash::error('Identifiant non modifié : adresse email invalide.');
            }
        }

        $newPassword = (string) ($post['new_password'] ?? '');
        if ($newPassword !== '') {
            if (Auth::changePassword((string) ($post['current_password'] ?? ''), $newPassword)) {
                Flash::success('Mot de passe modifié.');
            } else {
                Flash::error('Mot de passe non modifié : mot de passe actuel incorrect ou nouveau trop court.');
            }
        }

        // On relit le fichier pour confirmer : c'est ce qui distingue
        // « enregistré » de « la requête s'est bien terminée ».
        Config::load(true);
        $clesEnregistrees = [];
        foreach (Ai::FOURNISSEURS as $cleFournisseur => $nomFournisseur) {
            if (in_array($cleFournisseur, $secretsEcrits, true) && Ai::isConfiguredFor($cleFournisseur)) {
                $clesEnregistrees[] = Ai::label($cleFournisseur);
            }
        }
        Flash::success('Réglages enregistrés.'
            . ($clesEnregistrees === [] ? '' : ' Clé API ' . implode(' et ', $clesEnregistrees)
                . ' enregistrée' . (count($clesEnregistrees) > 1 ? 's' : '') . '.'));
        Util::redirect(Router::url('settings'));
    }

    public static function testSmtp(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();

        $to = trim((string) ($_POST['test_email'] ?? ''));
        if ($to !== '' && Util::isEmail($to)) {
            $result = Mailer::deliver(
                $to,
                '',
                'Test — ' . Config::get('app.name', 'Prospect Studio'),
                '<p>Si vous lisez ce message, la configuration SMTP fonctionne.</p>',
                'Si vous lisez ce message, la configuration SMTP fonctionne.'
            );
            $result['ok'] ? Flash::success('Email de test envoyé à ' . $to . '.')
                : Flash::error('Envoi impossible : ' . $result['error']);
        } else {
            $result = Smtp::fromConfig()->test();
            $result['ok'] ? Flash::success('Connexion et authentification SMTP réussies.')
                : Flash::error('SMTP : ' . $result['error']);
        }
        Util::redirect(Router::url('settings') . '#smtp');
    }

    /**
     * Modèle retenu : la valeur de la liste, ou l'identifiant saisi à la main
     * quand l'option « Autre » est sélectionnée.
     */
    private static function chosenModel(array $post): string
    {
        $selected = trim((string) ($post['claude_model'] ?? ''));
        if ($selected === '__custom__') {
            $custom = trim((string) ($post['claude_model_custom'] ?? ''));
            if ($custom !== '') {
                return $custom;
            }
            return (string) Config::get('claude.model', 'claude-opus-5');
        }
        return $selected !== '' ? $selected : (string) Config::get('claude.model', 'claude-opus-5');
    }

    /** Recharge la liste des modèles depuis l'API. */
    public static function modelsRefresh(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $result = Models::refresh();
        $result['ok']
            ? Flash::success($result['count'] . ' modèle(s) récupéré(s) depuis l\'API.')
            : Flash::error('Liste des modèles : ' . $result['error']);
        Util::redirect(Router::url('settings') . '#claude');
    }

    /**
     * Réglage d'une étape. Un champ vide veut dire « comme le principal » :
     * on ne le remplace pas par une valeur, sinon le réglage cesserait de
     * suivre le fournisseur quand on en change.
     */
    private static function stepChoice(array $post, string $etape): array
    {
        $fournisseur = (string) ($post['step_' . $etape . '_provider'] ?? '');
        $modele = trim((string) ($post['step_' . $etape . '_model'] ?? ''));
        // « Autre » ouvre un champ libre : un modèle sorti après cette version
        // doit rester saisissable, sinon la liste devient une prison.
        if ($modele === '__libre__') {
            $modele = trim((string) ($post['step_' . $etape . '_model_libre'] ?? ''));
        }
        return [
            'provider' => isset(Ai::FOURNISSEURS[$fournisseur]) ? $fournisseur : '',
            'model' => $modele,
        ];
    }

    /**
     * Tarifs corrigés à la main.
     *
     * Une ligne identique à celle livrée avec l'application n'est pas
     * enregistrée : elle deviendrait figée, et ne suivrait plus une mise à jour
     * de la grille de référence.
     */
    private static function tarifsSaisis(array $post): array
    {
        $tarifs = [];
        foreach ((array) ($post['tarif'] ?? []) as $modele => $valeurs) {
            $modele = (string) $modele;
            if (!in_array($modele, Models::modelesTarifables(), true) || !is_array($valeurs)) {
                continue;
            }
            // Six nombres : entrée, sortie et entrée en cache, pour chacune
            // des deux tranches horaires.
            $nombres = [];
            foreach ([0, 1, 2, 3, 4, 5] as $rang) {
                $nombres[$rang] = max(0.0, (float) str_replace(',', '.', (string) ($valeurs[$rang] ?? 0)));
            }
            if (max($nombres) <= 0) {
                continue;
            }
            $livre = Models::priceRangeLivre($modele);
            if ($livre !== null && $nombres === [
                $livre['creuse'][0], $livre['creuse'][1],
                $livre['pleine'][0], $livre['pleine'][1],
                $livre['creuse'][2] ?? 0.0, $livre['pleine'][2] ?? 0.0,
            ]) {
                continue;
            }
            $tarifs[$modele] = $nombres;
        }
        return $tarifs;
    }

    /** Rafraîchit la liste des modèles DeepSeek depuis le compte. */
    public static function deepseekRefresh(): void
    {
        self::rafraichirModeles(Ai::DEEPSEEK);
    }

    /** Rafraîchit la liste des modèles Gemini depuis le compte. */
    public static function geminiRefresh(): void
    {
        self::rafraichirModeles(Ai::GEMINI);
    }

    /**
     * Relit le catalogue d'un fournisseur.
     *
     * La liste est ce qui garantit qu'un nom de modèle proposé existe encore :
     * deux modèles DeepSeek retirés en juillet ont déjà fait échouer des
     * générations au premier appel.
     *
     * Le bouton vit dans le formulaire des Réglages : une clé qu'on vient de
     * taper part avec la requête. La jeter pour aller interroger l'API avec
     * l'ancienne — ou avec rien — était le moyen le plus sûr de perdre une clé
     * sans le dire. On l'enregistre donc d'abord.
     */
    private static function rafraichirModeles(string $provider): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $nom = Ai::label($provider);

        $cle = trim((string) ($_POST[$provider . '_api_key'] ?? ''));
        if ($cle !== '') {
            if (Config::merge([$provider => ['api_key' => $cle]])) {
                Flash::success('Clé API ' . $nom . ' enregistrée.');
            } else {
                Flash::error('Clé API ' . $nom . ' non enregistrée : '
                    . Config::raisonEcritureImpossible() . '.');
                Util::redirect(Router::url('settings') . '#claude');
            }
        }

        $result = $provider === Ai::GEMINI ? Gemini::refresh() : DeepSeek::refresh();
        $result['ok']
            ? Flash::success($result['count'] . ' modèle(s) ' . $nom . ' récupéré(s).')
            : Flash::error('Modèles ' . $nom . ' : ' . $result['error']);
        Util::redirect(Router::url('settings') . '#claude');
    }

    /**
     * Fournisseurs pour lesquels un bouton d'essai a un sens.
     *
     * Pas seulement ceux qui génèrent : Claude reste requis pour la lecture
     * d'un site bloqué, même quand les maquettes sont produites ailleurs.
     * L'omettre privait justement de l'outil de diagnostic au moment où sa clé
     * cessait de fonctionner.
     */
    public static function providersTestables(): array
    {
        $liste = Ai::providersUsed();
        foreach (array_keys(Ai::FOURNISSEURS) as $fournisseur) {
            if (!in_array($fournisseur, $liste, true) && Ai::isConfiguredFor($fournisseur)) {
                $liste[] = $fournisseur;
            }
        }
        if (!in_array(Ai::CLAUDE, $liste, true)) {
            $liste[] = Ai::CLAUDE;
        }
        return $liste;
    }

    /** Essai réel du service de capture, avec tout ce que le serveur a vu. */
    public static function testShot(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();

        $cible = trim((string) ($_POST['url'] ?? ''));
        $cible = $cible !== '' ? (Util::normalizeUrl($cible) ?? 'https://example.com/') : 'https://example.com/';

        $result = Screenshot::test($cible);
        $lignes = [];
        foreach ($result['details'] as $cle => $valeur) {
            $lignes[] = $cle . ' : ' . $valeur;
        }
        $texte = $result['message'] . ' — ' . implode(' · ', $lignes);
        $result['ok'] ? Flash::success($texte) : Flash::error($texte);
        Util::redirect(Router::url('settings') . '#capture');
    }

    /** Essai de la clé du fournisseur actif. */
    public static function testClaude(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        // Le bouton peut viser un fournisseur précis : avec trois clés
        // possibles, « tester la clé » sans dire laquelle ne veut plus rien
        // dire une fois les réglages par étape en place.
        $vise = trim((string) ($_POST['provider'] ?? ''));
        $provider = $vise !== '' ? Ai::normalize($vise) : Ai::provider();
        $result = Ai::test($provider);
        $nom = Ai::label($provider);
        $result['ok']
            ? Flash::success('Clé API ' . $nom . ' valide, le modèle ' . $result['model'] . ' répond.')
            : Flash::error('API ' . $nom . ' : ' . $result['error']);
        Util::redirect(Router::url('settings') . '#claude');
    }

    // ------------------------------------------------------------ Divers

    public static function suppression(): void
    {
        Auth::requireLogin();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::requireValid();
            $email = trim((string) ($_POST['email'] ?? ''));
            if (($_POST['action'] ?? '') === 'remove') {
                Suppression::remove($email);
                Flash::info($email . ' retiré de la liste.');
            } elseif (Util::isEmail($email)) {
                Suppression::add($email, 'ajout manuel');
                Flash::success($email . ' ajouté à la liste de suppression.');
            }
            Util::redirect(Router::url('suppression'));
        }
        echo render('admin/suppression', ['title' => 'Désinscriptions', 'rows' => Suppression::all()]);
    }

    /** Déclenchement manuel du traitement, identique à celui du cron. */
    public static function cronManual(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $report = Sequence::runDue();
        Flash::info($report['sent'] . ' email(s) envoyé(s), ' . $report['errors'] . ' erreur(s).');
        foreach (array_slice($report['messages'], 0, 6) as $message) {
            Flash::info($message);
        }
        Util::redirect(Router::url('dashboard'));
    }

    /** Journal des envois d'un prospect, enrichi de l'état d'ouverture. */
    private static function sendsOf(string $prospectId): array
    {
        $rows = [];
        foreach (\App\Store::read(Tracking::path()) as $token => $send) {
            if (($send['prospect_id'] ?? '') === $prospectId) {
                $rows[] = $send + ['token' => $token];
            }
        }
        usort($rows, static fn (array $a, array $b): int => ((int) $b['sent_at']) <=> ((int) $a['sent_at']));
        return $rows;
    }

    /** État de la configuration, affiché en tête du tableau de bord. */
    public static function health(): array
    {
        $checks = [];
        $checks[] = [
            'label' => 'Identifiant de connexion',
            'ok' => Auth::identifier() !== '',
            'hint' => 'Sans adresse email, la récupération du mot de passe est impossible.',
        ];
        $checks[] = [
            'label' => 'Clé API ' . Ai::label(),
            'ok' => Ai::isConfigured(),
            'hint' => 'Nécessaire pour générer les maquettes.',
        ];
        $checks[] = [
            'label' => 'Serveur SMTP',
            'ok' => trim((string) Config::get('smtp.host', '')) !== ''
                && trim((string) Config::get('smtp.from_email', '')) !== '',
            'hint' => 'Nécessaire pour envoyer la séquence.',
        ];
        $checks[] = [
            'label' => 'URL publique',
            'ok' => trim((string) Config::get('app.base_url', '')) !== '',
            'hint' => 'Sans elle, les liens des emails peuvent être erronés.',
        ];
        $checks[] = [
            'label' => 'Extension cURL',
            'ok' => function_exists('curl_init'),
            'hint' => 'Requise pour l\'API et l\'analyse des sites.',
        ];
        // Sans écriture, rien de ce qu'on saisit dans les Réglages ne survit à
        // la redirection : c'est la panne à diagnostiquer en premier.
        $inscriptible = is_writable(DATA_DIR);
        $checks[] = [
            'label' => 'Dossier data accessible en écriture',
            'ok' => $inscriptible,
            'hint' => $inscriptible
                ? 'Les réglages et les clés API y sont stockés.'
                : 'Aucun réglage ne peut être enregistré : ' . Config::raisonEcritureImpossible() . '.',
        ];
        return $checks;
    }
}
