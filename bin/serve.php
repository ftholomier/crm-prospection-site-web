<?php
declare(strict_types=1);

/**
 * Routeur pour le serveur de développement intégré à PHP :
 *
 *   php -S localhost:8000 -t public bin/serve.php
 *
 * Il sert les fichiers statiques existants et confie tout le reste au point
 * d'entrée, ce qui reproduit le comportement de mod_rewrite en production.
 */

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = dirname(__DIR__) . '/public' . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
require dirname(__DIR__) . '/public/index.php';
