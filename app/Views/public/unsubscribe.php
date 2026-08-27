<?php
/** @var array $prospect @var bool $done @var string $action */
use App\Prospect;

ob_start();
if ($done):
?>
    <h1>C'est fait.</h1>
    <div class="ok">L'adresse <strong><?= e((string) $prospect['email']) ?></strong> ne recevra plus aucun message.</div>
    <p class="muted" style="margin-top:18px">Désolé pour le dérangement, et bonne continuation.</p>
<?php else: ?>
    <h1>Ne plus recevoir de messages</h1>
    <p>Confirmez que l'adresse <strong><?= e((string) $prospect['email']) ?></strong> ne doit plus jamais être contactée.</p>
    <form method="post" action="<?= e($action) ?>">
        <button type="submit">Confirmer la désinscription</button>
    </form>
<?php
endif;
$pageBody = (string) ob_get_clean();
$pageTitle = 'Désinscription';
require __DIR__ . '/_shell.php';
