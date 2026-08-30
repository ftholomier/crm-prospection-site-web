<?php
/** @var array $rows @var array $filters @var array $counts @var array $rangs
 *  @var string $doublon @var array $doublonFiches */
use App\Csrf;
use App\Prospect;
use App\Util;

require __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1>Prospects</h1>
        <div class="sub"><?= count($rows) ?> fiche(s) affichée(s)</div>
    </div>
</div>

<div class="card">
    <form method="post" action="<?= e(url('prospect_add')) ?>" class="row">
        <?= Csrf::field() ?>
        <input type="text" name="url" placeholder="Collez l'adresse d'un site : monentreprise.fr" required style="flex:1;min-width:240px">
        <button class="btn primary" type="submit">Analyser ce site</button>
        <a class="btn" href="<?= e(url('import')) ?>">Import en masse</a>
    </form>

    <?php if ($doublon !== '' && $doublonFiches !== []): ?>
        <div class="flash info mt">
            <strong><?= e(App\Util::domain($doublon)) ?></strong> est déjà suivi
            (<?= count($doublonFiches) ?> fiche<?= count($doublonFiches) > 1 ? 's' : '' ?>).
            <div class="row mt">
                <?php foreach ($doublonFiches as $rang => $fiche): ?>
                    <a class="btn small" href="<?= e(url('prospect', ['id' => $fiche['id']])) ?>">
                        Ouvrir la fiche <?= $rang + 1 ?>
                        <span class="tiny muted">— <?= e(dt((int) $fiche['created_at'], 'd/m/Y à H:i')) ?></span>
                    </a>
                <?php endforeach; ?>
                <form method="post" action="<?= e(url('prospect_add')) ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="url" value="<?= e($doublon) ?>">
                    <input type="hidden" name="force" value="1">
                    <button class="btn small primary" type="submit">
                        Créer une <?= count($doublonFiches) + 1 ?><sup>e</sup> fiche pour comparer
                    </button>
                </form>
            </div>
            <div class="tiny muted mt">
                Les fiches d'un même site sont indépendantes : analyse, maquettes, séquence et relevé
                de consommation. C'est ce qui permet de générer deux fois le même prospect avec deux
                modèles différents et de comparer la dépense réelle.
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="card tight">
    <form method="get" class="row">
        <input type="hidden" name="r" value="prospects">
        <input type="text" name="search" value="<?= e($filters['search']) ?>" placeholder="Rechercher une société, un domaine, un email…" style="flex:1;min-width:200px">
        <select name="status" style="width:auto">
            <option value="">Tous les statuts</option>
            <?php foreach (Prospect::PIPELINE as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>>
                    <?= e($label) ?><?= isset($counts[$value]) && $counts[$value] > 0 ? ' (' . (int) $counts[$value] . ')' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="sort" style="width:auto">
            <option value="recent" <?= $filters['sort'] === 'recent' ? 'selected' : '' ?>>Plus récents</option>
            <option value="score" <?= $filters['sort'] === 'score' ? 'selected' : '' ?>>Score de vétusté</option>
            <option value="company" <?= $filters['sort'] === 'company' ? 'selected' : '' ?>>Ordre alphabétique</option>
        </select>
        <button class="btn" type="submit">Filtrer</button>
        <?php if ($filters['search'] !== '' || $filters['status'] !== ''): ?>
            <a class="btn ghost" href="<?= e(url('prospects')) ?>">Réinitialiser</a>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <?php if ($rows === []): ?>
        <div class="empty">
            <h3>Aucun prospect pour l'instant</h3>
            <p>Collez l'adresse d'un site ci-dessus : l'analyse démarre, le score de vétusté est calculé et les coordonnées sont extraites automatiquement.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Entreprise</th>
                    <th>Score</th>
                    <th>Contact</th>
                    <th>Statut</th>
                    <th>Séquence</th>
                    <th class="right">Tarif</th>
                    <th class="right">Suivi</th>
                    <th class="right">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $stats = $row['stats'] ?? []; ?>
                    <tr>
                        <td data-label="Entreprise">
                            <a href="<?= e(url('prospect', ['id' => $row['id']])) ?>" class="strong"><?= e(Prospect::displayName($row)) ?></a>
                            <div class="tiny muted">
                                <?= e((string) $row['domain']) ?><?= ($row['city'] ?? '') !== '' ? ' · ' . e((string) $row['city']) : '' ?>
                                <?php $rang = $rangs[$row['id']] ?? ['rang' => 1, 'total' => 1]; ?>
                                <?php if ($rang['total'] > 1): ?>
                                    <?php // Deux fiches du même site seraient sinon indiscernables :
                                          // l'heure ne suffit pas, la seconde naît dans la minute. ?>
                                    · <span class="badge brand">fiche <?= (int) $rang['rang'] ?>
                                        sur <?= (int) $rang['total'] ?></span>
                                    <span class="faint">du <?= e(dt((int) $row['created_at'], 'd/m à H:i')) ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td data-label="Score"><?php $score = $row['score']; require __DIR__ . '/../partials/score.php'; ?></td>
                        <td data-label="Contact">
                            <?php $contact = Prospect::contactName($row); ?>
                            <?php if ($contact !== ''): ?><div class="small"><?= e($contact) ?></div><?php endif; ?>
                            <?php if (($row['email'] ?? '') !== ''): ?>
                                <div class="tiny muted"><?= e((string) $row['email']) ?></div>
                            <?php else: ?>
                                <span class="badge warn">Email manquant</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Statut"><?php $status = (string) $row['status']; require __DIR__ . '/../partials/status.php'; ?></td>
                        <td class="small" data-label="Séquence">
                            <?php if (!empty($row['sequence']['active'])): ?>
                                <span class="badge brand">Email <?= (int) $row['sequence']['step'] + 1 ?> le <?= e(dt((int) $row['sequence']['next_at'], 'd/m')) ?></span>
                            <?php elseif ((int) ($row['sequence']['step'] ?? 0) > 0): ?>
                                <span class="muted"><?= (int) $row['sequence']['step'] ?>/3 envoyés</span>
                            <?php else: ?>
                                <span class="faint">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="right nowrap" data-label="Tarif"><?= e(price((float) ($row['monthly_price'] ?? 0))) ?></td>
                        <td class="right nowrap tiny muted" data-label="Suivi">
                            <?= (int) ($stats['opens'] ?? 0) ?> ouv. · <?= (int) ($stats['views'] ?? 0) ?> vues
                        </td>
                        <td class="right nowrap" data-label="Actions">
                            <form method="post" action="<?= e(url('prospect_delete')) ?>"
                                  data-confirm="Supprimer « <?= e(Prospect::displayName($row)) ?> » et toutes ses maquettes ? Cette action est définitive.">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= e((string) $row['id']) ?>">
                                <?php // Les filtres suivent, pour revenir à la liste qu'on regardait. ?>
                                <input type="hidden" name="search" value="<?= e($filters['search']) ?>">
                                <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
                                <input type="hidden" name="sort" value="<?= e($filters['sort']) ?>">
                                <button class="btn danger small ghost" type="submit"
                                        title="Supprimer cette fiche">Supprimer</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>
