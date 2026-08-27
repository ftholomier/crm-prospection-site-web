<?php
/** @var string $message */
ob_start();
?>
<h1>Lien indisponible</h1>
<p><?= e($message) ?></p>
<p class="muted">Si vous pensez qu'il s'agit d'une erreur, répondez simplement à l'email que vous avez reçu.</p>
<?php
$pageBody = (string) ob_get_clean();
$pageTitle = 'Lien indisponible';
require __DIR__ . '/_shell.php';
