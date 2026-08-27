<?php
declare(strict_types=1);

/**
 * Point d'entrée unique. Seul ce dossier doit être exposé par le serveur web :
 * le code applicatif et les données restent au-dessus de la racine publique.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Auth;
use App\Config;
use App\Controllers\Admin;
use App\Controllers\PublicSite;
use App\Controllers\Stream;
use App\Cron;
use App\Router;
use App\Util;

// Politique de sécurité applicable aux écrans d'administration. Les maquettes
// et le pixel de suivi envoient leurs propres en-têtes.
$sendAdminHeaders = static function (): void {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: SAMEORIGIN');
};

$dispatch = Router::dispatch();
$route = (string) $dispatch['route'];
$params = (array) $dispatch['params'];

/** Table de routage : route => [callable, exige une authentification]. */
$routes = [
    // Pages publiques vues par le prospect
    'mockup' => [static fn () => PublicSite::mockup($params), false],
    'shot' => [static fn () => PublicSite::shot($params), false],
    'track_open' => [static fn () => PublicSite::trackOpen($params), false],
    'track_click' => [static fn () => PublicSite::trackClick($params), false],
    'unsubscribe' => [static fn () => PublicSite::unsubscribe($params), false],
    'interest' => [static fn () => PublicSite::interest($params), false],
    'cron' => [static fn () => Cron::web(), false],

    // Accès
    'login' => [Admin::login(...), false],
    'install' => [Admin::install(...), false],
    'logout' => [Admin::logout(...), false],
    'forgot' => [Admin::forgot(...), false],
    'reset' => [Admin::reset(...), false],

    // Tableaux
    'dashboard' => [Admin::dashboard(...), true],
    'prospects' => [Admin::prospects(...), true],
    'pipeline' => [Admin::pipeline(...), true],

    // Prospect
    'prospect' => [Admin::prospect(...), true],
    'prospect_add' => [Admin::prospectAdd(...), true],
    'prospect_save' => [Admin::prospectSave(...), true],
    'prospect_delete' => [Admin::prospectDelete(...), true],
    'prospect_enrich' => [Admin::prospectEnrich(...), true],
    'prospect_manual' => [Admin::prospectManual(...), true],
    'screenshot' => [Admin::screenshot(...), true],
    'shot_admin' => [Admin::shotAdmin(...), true],

    // Maquette
    'mockup_preview' => [Admin::mockupPreview(...), true],
    'mockup_download' => [Admin::mockupDownload(...), true],
    'mockup_validate' => [Admin::mockupValidate(...), true],
    'mockup_use' => [Admin::mockupUseVersion(...), true],
    'mockup_delete' => [Admin::mockupDeleteVersion(...), true],

    // Séquence et emails
    'sequence_start' => [Admin::sequenceStart(...), true],
    'sequence_stop' => [Admin::sequenceStop(...), true],
    'send_now' => [Admin::sendNow(...), true],
    'email_preview' => [Admin::emailPreview(...), true],
    'templates' => [Admin::templates(...), true],
    'template_save' => [Admin::templateSave(...), true],

    // Outils
    'import' => [Admin::import(...), true],
    'import_run' => [Admin::importRun(...), true],
    'suppression' => [Admin::suppression(...), true],
    'cron_manual' => [Admin::cronManual(...), true],

    // Réglages
    'settings' => [Admin::settings(...), true],
    'settings_save' => [Admin::settingsSave(...), true],
    'test_smtp' => [Admin::testSmtp(...), true],
    'test_claude' => [Admin::testClaude(...), true],
    'models_refresh' => [Admin::modelsRefresh(...), true],

    // Traitements longs en flux
    'analyze_stream' => [Stream::analyze(...), true],
    'generate_stream' => [Stream::generate(...), true],
];

if (!isset($routes[$route])) {
    $sendAdminHeaders();
    http_response_code(404);
    Auth::start();
    echo render('admin/error', [
        'title' => 'Page introuvable',
        'message' => 'La page demandée n\'existe pas.',
    ]);
    exit;
}

[$handler, $requiresAuth] = $routes[$route];

// Les écrans d'accès ne demandent pas de session mais restent des pages
// d'administration : ils reçoivent les mêmes en-têtes de sécurité.
$prospectFacing = ['mockup', 'shot', 'track_open', 'track_click', 'unsubscribe', 'interest', 'cron'];
if (!in_array($route, $prospectFacing, true)) {
    $sendAdminHeaders();
}

if ($requiresAuth) {
    if (!Config::isInstalled()) {
        Util::redirect(Router::url('install'));
    }
    Auth::requireLogin();
}

$handler();
