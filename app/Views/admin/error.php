<?php
/** @var string $title @var string $message */
use App\Auth;
use App\Config;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<link rel="stylesheet" href="assets/css/app.css?v=<?= e(APP_VERSION) ?>">
</head>
<body>
<div class="auth-page">
    <div class="auth-card center">
        <h1><?= e($title) ?></h1>
        <p class="muted"><?= e($message) ?></p>
        <a class="btn primary" href="<?= e(url(Auth::check() ? 'dashboard' : 'login')) ?>">Retour</a>
    </div>
</div>
</body>
</html>
