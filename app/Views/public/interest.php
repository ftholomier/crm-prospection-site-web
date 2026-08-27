<?php
/** @var array $prospect @var string $message */
use App\Config;
use App\Prospect;
use App\Router;

$sent = $_SERVER['REQUEST_METHOD'] === 'POST';
$company = Prospect::displayName($prospect);
$price = price((float) ($prospect['monthly_price'] ?? Config::get('offer.monthly_price', 79)));
$sender = (string) Config::get('smtp.from_name', '');
$replyTo = (string) (Config::get('smtp.reply_to') ?: Config::get('smtp.from_email', ''));

ob_start();
if ($sent):
?>
    <h1>C'est noté, merci.</h1>
    <div class="ok">Votre message est bien arrivé. Vous serez recontacté rapidement<?= $replyTo !== '' ? ', ou répondez directement à ' . e($replyTo) : '' ?>.</div>
    <p class="muted" style="margin-top:18px">Les trois pages restent accessibles depuis le lien que vous avez reçu, le temps d'y réfléchir ou de les montrer autour de vous.</p>
    <p><a class="btn" href="<?= e(Router::mockupUrl($prospect, 'accueil')) ?>">Revoir les pages</a></p>
<?php else: ?>
    <h1>Parlons de votre site complet</h1>
    <p>Les trois pages que vous avez vues sont un échantillon. La suite, c'est <strong><?= e($company) ?></strong>
       en entier : toutes vos pages reprises et remises en forme dans la même direction.</p>
    <p class="muted"><strong><?= e($price) ?> par mois</strong>, hébergement, sauvegardes, mises à jour techniques
       et modifications de contenu comprises. Aucune facture de création à régler d'avance, aucune durée minimum.</p>
    <p class="muted">Dites-moi ce qui vous plaît, ce que vous changeriez, ou ce qui vous fait hésiter.</p>
    <form method="post">
        <label for="telephone">Votre téléphone <span class="muted">(facultatif)</span></label>
        <input type="text" name="telephone" id="telephone" value="<?= e((string) $prospect['phone']) ?>">
        <label for="message">Votre message <span class="muted">(facultatif)</span></label>
        <textarea name="message" id="message" placeholder="Ce qui vous plaît, ce que vous aimeriez changer, vos questions…"><?= e($message) ?></textarea>
        <button type="submit">Envoyer</button>
    </form>
<?php
endif;
$pageBody = (string) ob_get_clean();
$pageTitle = $sent ? 'Message envoyé' : 'Votre nouveau site — ' . $company;
require __DIR__ . '/_shell.php';
