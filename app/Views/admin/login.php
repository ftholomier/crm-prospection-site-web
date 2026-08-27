<?php
/** @var string $error @var string $mode */
use App\Config;
use App\Csrf;

$isInstall = ($mode ?? 'login') === 'install';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $isInstall ? 'Installation' : 'Connexion' ?> — <?= e((string) Config::get('app.name', 'Prospect Studio')) ?></title>
<link rel="stylesheet" href="assets/css/app.css?v=<?= e(APP_VERSION) ?>">
</head>
<body>
<div class="auth-page">
    <form class="auth-card" method="post">
        <h1><?= $isInstall ? 'Première installation' : e((string) Config::get('app.name', 'Prospect Studio')) ?></h1>

        <?php if ($isInstall): ?>
            <p class="muted small">Choisissez le mot de passe qui protégera votre CRM. Il est le seul accès à l'application : notez-le soigneusement, il n'existe aucune procédure de récupération.</p>
        <?php else: ?>
            <p class="muted small">Accès réservé.</p>
        <?php endif; ?>

        <?php if (($error ?? '') !== ''): ?>
            <div class="flash error"><?= e($error) ?></div>
        <?php endif; ?>

        <div class="field">
            <label for="password">Mot de passe</label>
            <input type="password" name="password" id="password" autocomplete="<?= $isInstall ? 'new-password' : 'current-password' ?>" required autofocus>
        </div>

        <?php if ($isInstall): ?>
            <div class="field">
                <label for="password_confirm">Confirmation</label>
                <input type="password" name="password_confirm" id="password_confirm" autocomplete="new-password" required>
                <span class="hint muted tiny">8 caractères minimum.</span>
            </div>
        <?php endif; ?>

        <button class="btn primary" type="submit" style="width:100%">
            <?= $isInstall ? 'Créer mon accès' : 'Se connecter' ?>
        </button>
    </form>
</div>
</body>
</html>
