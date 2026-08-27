<?php
/**
 * Écrans d'accès, en un seul gabarit.
 * @var string $mode  install | login | forgot | reset
 * @var string $error
 */
use App\Auth;
use App\Config;
use App\Router;

$mode = $mode ?? 'login';
$identifier = $identifier ?? '';
$notice = $notice ?? '';
$token = $token ?? '';
$valid = $valid ?? false;
$canSend = $canSend ?? true;

$titles = [
    'install' => 'Première installation',
    'login' => (string) Config::get('app.name', 'Prospect Studio'),
    'forgot' => 'Mot de passe oublié',
    'reset' => 'Nouveau mot de passe',
];
$pageTitle = $mode === 'login' ? 'Connexion' : $titles[$mode];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($pageTitle) ?> — <?= e((string) Config::get('app.name', 'Prospect Studio')) ?></title>
<link rel="stylesheet" href="assets/css/app.css?v=<?= e(APP_VERSION) ?>">
</head>
<body>
<div class="auth-page">
    <form class="auth-card" method="post"<?= $mode === 'reset' ? ' action="' . e(url('reset', ['t' => $token])) . '"' : '' ?>>
        <h1><?= e($titles[$mode]) ?></h1>

        <?php if ($mode === 'install'): ?>
            <p class="muted small">Créez votre accès. L'adresse email servira d'identifiant de connexion et recevra les liens de récupération : choisissez une adresse que vous consultez.</p>
        <?php elseif ($mode === 'login'): ?>
            <p class="muted small">Accès réservé.</p>
        <?php elseif ($mode === 'forgot'): ?>
            <p class="muted small">Indiquez l'adresse email du compte. Vous recevrez un lien valable une heure pour choisir un nouveau mot de passe.</p>
        <?php elseif ($mode === 'reset'): ?>
            <p class="muted small"><?= $valid ? 'Choisissez votre nouveau mot de passe. Toutes les sessions ouvertes ailleurs seront fermées.' : '' ?></p>
        <?php endif; ?>

        <?php if (($error ?? '') !== ''): ?>
            <div class="flash error"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($notice !== ''): ?>
            <div class="flash success"><?= e($notice) ?></div>
        <?php endif; ?>

        <?php if (($blocker ?? null) !== null && $mode === 'install'): ?>
            <div class="flash error"><?= e($blocker) ?></div>
        <?php endif; ?>

        <?php if ($mode === 'forgot' && ($blocker ?? null) !== null): ?>
            <div class="flash info">
                <strong>Reprendre la main sans email</strong><br>
                En ligne de commande, depuis la racine du projet :<br>
                <code>php bin/reset-password.php vous@votredomaine.fr votreNouveauMotDePasse</code><br>
                Sans accès SSH, videz la valeur <code>app.password_hash</code> dans
                <code>data/config.json</code> : l'application repassera par l'écran d'installation.
            </div>
        <?php endif; ?>

        <?php if ($mode === 'reset' && !$valid): ?>
            <div class="flash error">Ce lien est expiré ou a déjà été utilisé. Demandez-en un nouveau.</div>
            <a class="btn primary" href="<?= e(url('forgot')) ?>" style="width:100%">Demander un nouveau lien</a>

        <?php elseif ($mode === 'reset'): ?>
            <input type="hidden" name="t" value="<?= e($token) ?>">
            <div class="field">
                <label for="password">Nouveau mot de passe</label>
                <input type="password" name="password" id="password" autocomplete="new-password" required autofocus>
            </div>
            <div class="field">
                <label for="password_confirm">Confirmation</label>
                <input type="password" name="password_confirm" id="password_confirm" autocomplete="new-password" required>
                <span class="hint muted tiny">8 caractères minimum.</span>
            </div>
            <button class="btn primary" type="submit" style="width:100%">Enregistrer et me connecter</button>

        <?php else: ?>
            <div class="field">
                <label for="identifier">
                    <?= $mode === 'install' ? 'Adresse email (votre identifiant)' : 'Identifiant' ?>
                </label>
                <input type="email" name="identifier" id="identifier"
                       value="<?= e($identifier) ?>"
                       autocomplete="username"
                       placeholder="vous@votredomaine.fr"
                       required <?= $mode !== 'reset' ? 'autofocus' : '' ?>>
            </div>

            <?php if ($mode !== 'forgot'): ?>
                <div class="field">
                    <label for="password">Mot de passe</label>
                    <input type="password" name="password" id="password"
                           autocomplete="<?= $mode === 'install' ? 'new-password' : 'current-password' ?>" required>
                </div>
            <?php endif; ?>

            <?php if ($mode === 'install'): ?>
                <div class="field">
                    <label for="password_confirm">Confirmation</label>
                    <input type="password" name="password_confirm" id="password_confirm" autocomplete="new-password" required>
                    <span class="hint muted tiny">8 caractères minimum.</span>
                </div>
            <?php endif; ?>

            <button class="btn primary" type="submit" style="width:100%">
                <?= $mode === 'install' ? 'Créer mon accès' : ($mode === 'forgot' ? 'Envoyer le lien' : 'Se connecter') ?>
            </button>

            <?php if ($mode === 'login'): ?>
                <p class="center small mt" style="margin-bottom:0">
                    <a href="<?= e(url('forgot')) ?>">Mot de passe oublié ?</a>
                </p>
            <?php elseif ($mode === 'forgot'): ?>
                <p class="center small mt" style="margin-bottom:0">
                    <a href="<?= e(url('login')) ?>">Revenir à la connexion</a>
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </form>
</div>
</body>
</html>
