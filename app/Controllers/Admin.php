<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Claude;
use App\Config;
use App\Csrf;
use App\Enrich;
use App\Events;
use App\Flash;
use App\Mail\Smtp;
use App\Mailer;
use App\Mockup;
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
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (Auth::isLocked()) {
                $error = 'Trop de tentatives. Réessayez dans ' . ceil(Auth::lockedFor() / 60) . ' minutes.';
            } elseif (Auth::attempt((string) ($_POST['password'] ?? ''))) {
                Util::redirect(Router::url('dashboard'));
            } else {
                $error = 'Mot de passe incorrect.';
            }
        }
        echo render('admin/login', ['error' => $error, 'mode' => 'login']);
    }

    public static function install(): void
    {
        if (Config::isInstalled()) {
            Util::redirect(Router::url('login'));
        }
        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $password = (string) ($_POST['password'] ?? '');
            if (strlen($password) < 8) {
                $error = 'Choisissez un mot de passe d\'au moins 8 caractères.';
            } elseif ($password !== (string) ($_POST['password_confirm'] ?? '')) {
                $error = 'Les deux mots de passe ne correspondent pas.';
            } elseif (Auth::install($password)) {
                Flash::success('Mot de passe enregistré. Complétez les réglages pour commencer.');
                Util::redirect(Router::url('settings'));
            } else {
                $error = 'Installation impossible.';
            }
        }
        echo render('admin/login', ['error' => $error, 'mode' => 'install']);
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
        echo render('admin/prospects', [
            'title' => 'Prospects',
            'rows' => Prospect::search($filters),
            'filters' => $filters,
            'counts' => Stats::dashboard()['by_status'],
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
            'shotUrl' => Router::url('shot_admin', ['id' => $id, 'v' => time()]),
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
        $existing = Prospect::findByUrl($url);
        if ($existing !== null) {
            Flash::info('Ce domaine est déjà suivi.');
            Util::redirect(Router::url('prospect', ['id' => $existing['id']]));
        }

        $prospect = Prospect::create($url);
        Flash::success('Prospect ajouté. Lancez l\'analyse du site.');
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
        Prospect::delete($id);
        Flash::success('Prospect supprimé, maquettes comprises.');
        Util::redirect(Router::url('prospects'));
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

        if (!empty($_FILES['capture']['name'])) {
            $result = Screenshot::storeUpload($id, $_FILES['capture']);
            $result['ok'] ? Flash::success('Capture importée.') : Flash::error($result['error']);
        } else {
            $result = Screenshot::capture($id, (string) $prospect['url']);
            $result['ok'] ? Flash::success('Capture réalisée.') : Flash::error($result['error']);
        }
        Util::redirect(Router::url('prospect', ['id' => $id]));
    }

    /** Sert la capture dans l'interface (elle est stockée hors racine web). */
    public static function shotAdmin(): void
    {
        Auth::requireLogin();
        $path = Screenshot::path((string) ($_GET['id'] ?? ''));
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
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow');
        echo Mockup::forPublic($html, $links);
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
        echo render('admin/settings', [
            'title' => 'Réglages',
            'config' => Config::all(),
            'modes' => Enrich::modes(),
            'providers' => Screenshot::PROVIDERS,
            'health' => self::health(),
            'cronUrl' => Config::baseUrl() . '/index.php?r=cron&key=' . rawurlencode((string) Config::get('app.cron_key', '')),
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
            'claude' => [
                'model' => trim((string) ($post['claude_model'] ?? 'claude-opus-5')),
                'effort' => (string) ($post['claude_effort'] ?? 'high'),
                'max_tokens' => Util::clamp((int) ($post['claude_max_tokens'] ?? 24000), 4000, 64000),
            ],
            'design' => [
                'global_prompt' => (string) ($post['design_prompt'] ?? ''),
                'allow_google_fonts' => !empty($post['allow_google_fonts']),
                'use_site_images' => !empty($post['use_site_images']),
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
            'alerts' => [
                'email' => trim((string) ($post['alert_email'] ?? '')),
                'on_interest' => !empty($post['alert_interest']),
                'on_view' => !empty($post['alert_view']),
            ],
            'batch' => [
                'auto_analyze' => !empty($post['auto_analyze']),
                'auto_generate' => !empty($post['auto_generate']),
                'per_run' => Util::clamp((int) ($post['batch_per_run'] ?? 3), 1, 20),
            ],
        ];

        // Les secrets ne sont réécrits que si un nouveau est fourni : le
        // formulaire n'affiche jamais la valeur en clair.
        foreach ([
            'claude_api_key' => 'claude.api_key',
            'smtp_pass' => 'smtp.pass',
            'pappers_key' => 'enrichment.pappers_api_key',
            'shot_key' => 'screenshot.api_key',
        ] as $field => $path) {
            $value = (string) ($post[$field] ?? '');
            if ($value !== '') {
                [$section, $key] = explode('.', $path);
                $patch[$section][$key] = trim($value);
            }
        }

        Config::merge($patch);

        if ((string) Config::get('app.cron_key', '') === '') {
            Config::set('app.cron_key', Util::token(16));
        }

        $newPassword = (string) ($post['new_password'] ?? '');
        if ($newPassword !== '') {
            if (Auth::changePassword((string) ($post['current_password'] ?? ''), $newPassword)) {
                Flash::success('Mot de passe modifié.');
            } else {
                Flash::error('Mot de passe non modifié : mot de passe actuel incorrect ou nouveau trop court.');
            }
        }

        Flash::success('Réglages enregistrés.');
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

    public static function testClaude(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $result = Claude::test();
        $result['ok'] ? Flash::success('Clé API valide, le modèle répond.')
            : Flash::error('API Claude : ' . $result['error']);
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
            'label' => 'Clé API Claude',
            'ok' => Claude::isConfigured(),
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
        $checks[] = [
            'label' => 'Dossier data accessible en écriture',
            'ok' => is_writable(DATA_DIR),
            'hint' => 'Donnez les droits d\'écriture au dossier data/.',
        ];
        return $checks;
    }
}
