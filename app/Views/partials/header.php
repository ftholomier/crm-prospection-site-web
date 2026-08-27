<?php
/** @var string $title */
use App\Auth;
use App\Config;
use App\Flash;
use App\Prospect;
use App\Router;

$currentRoute = (string) ($_GET['r'] ?? 'dashboard');
$navGroups = [
    'dashboard' => ['Tableau de bord', ['dashboard']],
    'prospects' => ['Prospects', ['prospects', 'prospect']],
    'pipeline' => ['Pipeline', ['pipeline']],
    'templates' => ['Modèles d\'emails', ['templates']],
    'import' => ['Import en masse', ['import']],
    'suppression' => ['Désinscriptions', ['suppression']],
    'settings' => ['Réglages', ['settings']],
];
$pending = 0;
foreach (Prospect::index() as $indexRow) {
    if (($indexRow['status'] ?? '') === Prospect::NEW) {
        $pending++;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? 'Prospect Studio') ?> — <?= e((string) Config::get('app.name', 'Prospect Studio')) ?></title>
<link rel="stylesheet" href="assets/css/app.css?v=<?= e(APP_VERSION) ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='%232563eb'/><path d='M9 22V10h6a4 4 0 0 1 0 8H9' stroke='white' stroke-width='2.5' fill='none' stroke-linecap='round'/></svg>">
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <a class="brand" href="<?= e(url('dashboard')) ?>">
            <?= e((string) Config::get('app.name', 'Prospect Studio')) ?>
            <small>Prospection sites web</small>
        </a>
        <nav>
            <?php foreach ($navGroups as $route => [$label, $matches]): ?>
                <a href="<?= e(url($route)) ?>" class="<?= in_array($currentRoute, $matches, true) ? 'active' : '' ?>">
                    <?= e($label) ?>
                    <?php if ($route === 'prospects' && $pending > 0): ?>
                        <span class="count"><?= (int) $pending ?> à analyser</span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="spacer"></div>
        <a class="logout" href="<?= e(url('logout')) ?>">Se déconnecter</a>
        <div class="foot">Version <?= e(APP_VERSION) ?></div>
    </aside>
    <main class="main">
        <?php foreach (Flash::pull() as $flash): ?>
            <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endforeach; ?>
