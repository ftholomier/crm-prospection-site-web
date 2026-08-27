<?php
/** @var array $columns */
use App\Prospect;

require __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1>Pipeline</h1>
        <div class="sub">Vue d'ensemble de l'avancement, du site détecté au client signé.</div>
    </div>
    <div class="actions"><a class="btn" href="<?= e(url('prospects')) ?>">Vue liste</a></div>
</div>

<div class="pipeline">
    <?php foreach (Prospect::PIPELINE as $status => $label): ?>
        <div class="col">
            <h3><?= e($label) ?><span><?= count($columns[$status] ?? []) ?></span></h3>
            <?php foreach ($columns[$status] ?? [] as $row): ?>
                <a class="item" href="<?= e(url('prospect', ['id' => $row['id']])) ?>">
                    <div class="name"><?= e(Prospect::displayName($row)) ?></div>
                    <div class="tiny muted"><?= e((string) $row['domain']) ?></div>
                    <div class="row tiny muted" style="margin-top:6px;gap:6px">
                        <?php if ($row['score'] !== null): ?><span class="badge">Score <?= (int) $row['score'] ?></span><?php endif; ?>
                        <span><?= e(price((float) ($row['monthly_price'] ?? 0))) ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
            <?php if (($columns[$status] ?? []) === []): ?>
                <p class="tiny faint center" style="padding:10px 0">Vide</p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>
