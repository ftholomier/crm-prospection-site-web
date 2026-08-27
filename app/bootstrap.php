<?php
declare(strict_types=1);

/**
 * Amorçage de l'application. Charge les constantes de chemin, l'autoloader
 * et la configuration. Aucun composant externe : PHP natif uniquement.
 */

define('APP_ROOT', dirname(__DIR__));
define('APP_DIR', APP_ROOT . '/app');
define('DATA_DIR', APP_ROOT . '/data');
define('PUBLIC_DIR', APP_ROOT . '/public');
define('APP_VERSION', '1.0.0');

mb_internal_encoding('UTF-8');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, 4));
    $path = APP_DIR . '/' . $relative . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

require_once APP_DIR . '/functions.php';

App\Store::ensureLayout();
App\Config::load();

date_default_timezone_set(App\Config::get('app.timezone', 'Europe/Paris'));
