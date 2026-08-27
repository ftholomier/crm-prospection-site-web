<?php
/** @var array $templates @var array $variables @var array $syntax */
use App\Csrf;
use App\Templates;

require __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1>Modèles d'emails</h1>
        <div class="sub">Les trois messages de la séquence. Modifiez-les librement : les variables entre accolades sont remplacées à l'envoi.</div>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2>Variables disponibles</h2></div>
    <p class="small muted">Cliquez sur une variable pour l'insérer à l'endroit du curseur dans le champ que vous êtes en train d'éditer.</p>
    <div class="var-list">
        <?php foreach ($variables as $variable => $description): ?>
            <code data-insert="<?= e($variable) ?>" title="<?= e($description) ?>"><?= e($variable) ?></code>
        <?php endforeach; ?>
    </div>
    <div class="divider"></div>
    <h3>Deux syntaxes utiles</h3>
    <div class="table-wrap">
        <table>
            <tbody>
            <?php foreach ($syntax as $example => $description): ?>
                <tr>
                    <td class="mono nowrap"><?= e($example) ?></td>
                    <td class="small muted"><?= e($description) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="tiny muted mt">Le lien de désinscription et le pied de page légal sont ajoutés automatiquement à chaque email : inutile de les écrire.</p>
</div>

<?php foreach (Templates::STEPS as $step => $label): ?>
    <?php $template = $templates[$step]; ?>
    <div class="card" id="email-<?= (int) $step ?>">
        <div class="card-head">
            <h2><?= e($label) ?></h2>
            <div class="actions">
                <?php if (empty($template['enabled'])): ?><span class="badge warn">Désactivé</span><?php endif; ?>
            </div>
        </div>
        <form method="post" action="<?= e(url('template_save')) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="step" value="<?= (int) $step ?>">

            <div class="field">
                <label for="subject-<?= (int) $step ?>">Objet</label>
                <input type="text" name="subject" id="subject-<?= (int) $step ?>" value="<?= e((string) $template['subject']) ?>">
            </div>
            <div class="field">
                <label for="body-<?= (int) $step ?>">Contenu <span class="muted">— HTML simple accepté ; <code>class="bouton"</code> transforme un lien en bouton</span></label>
                <textarea class="code" name="body" id="body-<?= (int) $step ?>" rows="16"><?= e((string) $template['body']) ?></textarea>
            </div>
            <label class="check">
                <input type="checkbox" name="enabled" value="1" <?= !empty($template['enabled']) ? 'checked' : '' ?>>
                <span>Cet email fait partie de la séquence</span>
            </label>
            <div class="row">
                <button class="btn primary" type="submit">Enregistrer</button>
                <button class="btn ghost" type="submit" name="action" value="reset"
                        onclick="return confirm('Rétablir le texte livré par défaut ?')">Réinitialiser</button>
            </div>
        </form>
    </div>
<?php endforeach; ?>

<?php require __DIR__ . '/../partials/footer.php'; ?>
