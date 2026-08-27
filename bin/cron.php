#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tâche planifiée. À exécuter toutes les 15 minutes, par exemple :
 *
 *   0,15,30,45 * * * * /usr/bin/php /chemin/vers/le/projet/bin/cron.php > /dev/null 2>&1
 *
 * Si votre hébergeur ne propose que des crons de type URL, utilisez l'adresse
 * affichée dans Réglages → Tâche planifiée.
 *
 * Options : --max=N limite le nombre d'emails envoyés lors de ce passage.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ce script s'exécute uniquement en ligne de commande.\n");
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$maxSends = 0;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--max=(\d+)$/', $argument, $m)) {
        $maxSends = (int) $m[1];
    }
}

$result = App\Cron::run($maxSends);
echo implode("\n", $result['lines']) . "\n";
exit($result['ok'] ? 0 : 1);
