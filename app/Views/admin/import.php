<?php
use App\Config;
use App\Csrf;

require __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1>Import en masse</h1>
        <div class="sub">Ajoutez une liste de sites d'un coup. Les doublons de domaine sont ignorés automatiquement.</div>
    </div>
</div>

<div class="grid side">
    <div>
        <div class="card">
            <form method="post" action="<?= e(url('import_run')) ?>" enctype="multipart/form-data">
                <?= Csrf::field() ?>
                <div class="field">
                    <label for="lines">Collez vos lignes</label>
                    <textarea class="code" name="lines" id="lines" rows="12" placeholder="monentreprise.fr
https://autre-site.com;contact@autre-site.com;Marie;Durand;Autre Site SARL;99
troisieme-site.fr;;;;;59"></textarea>
                    <span class="hint muted tiny">Une ligne par site. Séparateurs acceptés : point-virgule, virgule ou tabulation.</span>
                </div>
                <div class="field">
                    <label for="fichier">Ou importez un fichier CSV</label>
                    <input type="file" name="fichier" id="fichier" accept=".csv,.txt">
                </div>
                <button class="btn primary" type="submit">Importer</button>
            </form>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="card-head"><h2>Format des colonnes</h2></div>
            <div class="table-wrap">
                <table>
                    <tbody>
                    <tr><td class="mono">1</td><td class="small"><strong>URL</strong> — seule colonne obligatoire</td></tr>
                    <tr><td class="mono">2</td><td class="small">Email du contact</td></tr>
                    <tr><td class="mono">3</td><td class="small">Prénom</td></tr>
                    <tr><td class="mono">4</td><td class="small">Nom</td></tr>
                    <tr><td class="mono">5</td><td class="small">Raison sociale</td></tr>
                    <tr><td class="mono">6</td><td class="small">Tarif mensuel</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="tiny muted mt">Les colonnes laissées vides seront complétées automatiquement lors de l'analyse du site.</p>
        </div>

        <div class="card">
            <div class="card-head"><h2>Après l'import</h2></div>
            <p class="small">
                <?php if (Config::get('batch.auto_analyze', true)): ?>
                    Le cron analyse automatiquement <?= (int) Config::get('batch.per_run', 3) ?> site(s) à chaque passage.
                <?php else: ?>
                    L'analyse automatique est désactivée : lancez-la depuis chaque fiche.
                <?php endif; ?>
            </p>
            <p class="small">
                <?php if (Config::get('batch.auto_generate', false)): ?>
                    La génération automatique des maquettes est <strong>activée</strong> pour les sites dont le score dépasse
                    <?= (int) Config::get('audit.min_score_to_prospect', 40) ?>/100. Chaque maquette consomme des crédits API.
                <?php else: ?>
                    La génération des maquettes reste manuelle : vous gardez la main sur la dépense API.
                <?php endif; ?>
            </p>
            <a class="btn small" href="<?= e(url('settings')) ?>#batch">Modifier ce comportement</a>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>
