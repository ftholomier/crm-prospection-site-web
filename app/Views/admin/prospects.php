<?php
/** @var array $rows @var array $filters @var array $counts */
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
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $stats = $row['stats'] ?? []; ?>
                    <tr>
                        <td data-label="Entreprise">
                            <a href="<?= e(url('prospect', ['id' => $row['id']])) ?>" class="strong"><?= e(Prospect::displayName($row)) ?></a>
                            <div class="tiny muted"><?= e((string) $row['domain']) ?><?= ($row['city'] ?? '') !== '' ? ' · ' . e((string) $row['city']) : '' ?></div>
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
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>
