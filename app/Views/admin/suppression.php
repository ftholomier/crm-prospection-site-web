<?php
/** @var array $rows */
use App\Csrf;

require __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1>Désinscriptions</h1>
        <div class="sub">Aucune adresse figurant ici ne peut recevoir d'email, quelle que soit la séquence.</div>
    </div>
</div>

<div class="card">
    <form method="post" class="row">
        <?= Csrf::field() ?>
        <input type="email" name="email" placeholder="Ajouter une adresse à exclure" required style="flex:1;min-width:220px">
        <button class="btn" type="submit">Ajouter</button>
    </form>
</div>

<div class="card">
    <?php if ($rows === []): ?>
        <div class="empty">
            <h3>Aucune désinscription</h3>
            <p>Les adresses s'ajoutent ici automatiquement quand un prospect clique sur le lien de désinscription.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Adresse</th><th>Motif</th><th>Date</th><th class="right"></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="mono" data-label="Adresse"><?= e((string) $row['email']) ?></td>
                        <td class="small muted" data-label="Motif"><?= e((string) $row['reason']) ?></td>
                        <td class="small muted" data-label="Date"><?= e(dt((int) $row['at'])) ?></td>
                        <td class="right">
                            <form method="post" data-confirm="Retirer cette adresse de la liste ? Elle pourra de nouveau être contactée.">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="email" value="<?= e((string) $row['email']) ?>">
                                <input type="hidden" name="action" value="remove">
                                <button class="btn small ghost" type="submit">Retirer</button>
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
