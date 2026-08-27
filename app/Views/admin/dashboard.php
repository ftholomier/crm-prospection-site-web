<?php
/** @var array $data @var array $funnel @var array $activity @var array $health */
use App\Csrf;
use App\Events;
use App\Prospect;

require __DIR__ . '/../partials/header.php';

$totals = $data['totals'];
$rates = $data['rates'];
$sends = $data['sends'];
$series = $data['series'];
$maxDaily = max(1, max(array_map(static fn (array $day): int => array_sum($day), $series)));
$failing = array_filter($health, static fn (array $check): bool => !$check['ok']);
?>

<div class="page-head">
    <div>
        <h1>Tableau de bord</h1>
        <div class="sub"><?= (int) $data['today'] ?> email(s) envoyé(s) aujourd'hui sur un plafond de <?= (int) $data['daily_limit'] ?>.</div>
    </div>
    <div class="actions">
        <form method="post" action="<?= e(url('cron_manual')) ?>">
            <?= Csrf::field() ?>
            <button class="btn" type="submit">Traiter les envois dus</button>
        </form>
        <a class="btn primary" href="<?= e(url('prospects')) ?>">Ajouter un prospect</a>
    </div>
</div>

<?php if ($failing !== []): ?>
    <div class="card tight" style="border-color:#fcd34d;background:var(--warn-soft)">
        <strong>Configuration incomplète</strong>
        <ul class="small" style="margin:8px 0 0;padding-left:18px">
            <?php foreach ($failing as $check): ?>
                <li><?= e($check['label']) ?> — <?= e($check['hint']) ?></li>
            <?php endforeach; ?>
        </ul>
        <div class="mt"><a class="btn small" href="<?= e(url('settings')) ?>">Compléter les réglages</a></div>
    </div>
<?php endif; ?>

<div class="grid cols-4 mb">
    <div class="kpi">
        <div class="label">Prospects</div>
        <div class="value"><?= (int) $totals['prospects'] ?></div>
        <div class="delta"><?= (int) $totals['analyzed'] ?> analysés · score moyen <?= (int) $totals['avg_score'] ?>/100</div>
    </div>
    <div class="kpi">
        <div class="label">Maquettes</div>
        <div class="value"><?= (int) $totals['with_mockup'] ?></div>
        <div class="delta"><?= (int) $totals['validated'] ?> validée(s) et prête(s) à partir</div>
    </div>
    <div class="kpi">
        <div class="label">Taux d'ouverture</div>
        <div class="value"><?= number_format($rates['open'], 1, ',', ' ') ?> %</div>
        <div class="delta"><?= (int) $sends['opened'] ?> ouvertures sur <?= (int) $sends['sent'] ?> envois</div>
    </div>
    <div class="kpi">
        <div class="label">Maquettes consultées</div>
        <div class="value"><?= number_format($rates['click'], 1, ',', ' ') ?> %</div>
        <div class="delta"><?= (int) $totals['interested'] ?> prospect(s) intéressé(s)</div>
    </div>
</div>

<div class="grid side">
    <div>
        <div class="card">
            <div class="card-head">
                <h2>Activité des 30 derniers jours</h2>
            </div>
            <div class="chart">
                <?php foreach ($series as $day => $counts): ?>
                    <?php
                    $sent = (int) ($counts[Events::SENT] ?? 0);
                    $open = (int) ($counts[Events::OPEN] ?? 0);
                    $click = (int) ($counts[Events::CLICK] ?? 0);
                    $scale = static fn (int $value): int => $value === 0 ? 0 : max(3, (int) round($value / $maxDaily * 92));
                    ?>
                    <div class="col" title="<?= e(date('d/m', strtotime($day))) ?> — <?= $sent ?> envoi(s), <?= $open ?> ouverture(s), <?= $click ?> clic(s)">
                        <i class="click" style="height:<?= $scale($click) ?>px"></i>
                        <i class="open" style="height:<?= $scale($open) ?>px"></i>
                        <i style="height:<?= $scale($sent) ?>px"></i>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="chart-legend">
                <span><b style="background:#2563eb"></b>Envois</span>
                <span><b style="background:#60a5fa"></b>Ouvertures</span>
                <span><b style="background:#059669"></b>Clics</span>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>Entonnoir de conversion</h2></div>
            <?php $top = max(1, (int) ($funnel[0]['value'] ?? 1)); ?>
            <?php foreach ($funnel as $stage): ?>
                <div class="funnel-row">
                    <span class="muted"><?= e($stage['label']) ?></span>
                    <span class="bar"><span style="width:<?= max(0, min(100, (int) round($stage['value'] / $top * 100))) ?>%"></span></span>
                    <strong class="right"><?= (int) $stage['value'] ?></strong>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card">
            <div class="card-head"><h2>Performance par email</h2></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Étape</th><th class="right">Envoyés</th><th class="right">Ouverts</th><th class="right">Cliqués</th><th class="right">Taux de clic</th></tr></thead>
                    <tbody>
                    <?php foreach (App\Templates::STEPS as $step => $label): ?>
                        <?php $row = $sends['by_step'][$step] ?? []; ?>
                        <tr>
                            <td data-label="Étape"><?= e($label) ?></td>
                            <td class="right" data-label="Envoyés"><?= (int) ($row['sent'] ?? 0) ?></td>
                            <td class="right" data-label="Ouverts"><?= (int) ($row['opened'] ?? 0) ?></td>
                            <td class="right" data-label="Cliqués"><?= (int) ($row['clicked'] ?? 0) ?></td>
                            <td class="right" data-label="Taux de clic"><?= number_format(App\Stats::rate((int) ($row['clicked'] ?? 0), (int) ($row['sent'] ?? 0)), 1, ',', ' ') ?> %</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="card-head"><h2>Prochains envois</h2></div>
            <?php if ($data['upcoming'] === []): ?>
                <p class="muted small">Aucun envoi programmé. Validez une maquette puis lancez sa séquence.</p>
            <?php else: ?>
                <ul class="timeline">
                    <?php foreach ($data['upcoming'] as $item): ?>
                        <li>
                            <div>
                                <a href="<?= e(url('prospect', ['id' => $item['id']])) ?>"><?= e($item['name']) ?></a>
                                <div class="tiny muted">Email <?= (int) $item['step'] ?> · <?= e($item['email']) ?></div>
                            </div>
                            <span class="when"><?= e(dt((int) $item['next_at'], 'd/m à H:i')) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-head"><h2>Répartition</h2></div>
            <?php foreach ($data['by_status'] as $status => $count): ?>
                <?php if ($count === 0) { continue; } ?>
                <div class="row" style="justify-content:space-between;padding:5px 0">
                    <a href="<?= e(url('prospects', ['status' => $status])) ?>" style="text-decoration:none">
                        <?php require __DIR__ . '/../partials/status.php'; ?>
                    </a>
                    <strong><?= (int) $count ?></strong>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card">
            <div class="card-head"><h2>Dernière activité</h2></div>
            <?php if ($activity === []): ?>
                <p class="muted small">Rien à afficher pour l'instant.</p>
            <?php else: ?>
                <ul class="timeline">
                    <?php foreach ($activity as $event): ?>
                        <li>
                            <div>
                                <?= e(Events::label((string) $event['type'])) ?>
                                <?php if (!empty($event['prospect_id'])): ?>
                                    <?php $linked = Prospect::index()[$event['prospect_id']] ?? null; ?>
                                    <?php if ($linked !== null): ?>
                                        <div class="tiny"><a href="<?= e(url('prospect', ['id' => $event['prospect_id']])) ?>"><?= e(Prospect::displayName($linked)) ?></a></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <span class="when"><?= e(ago((int) $event['ts'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>
