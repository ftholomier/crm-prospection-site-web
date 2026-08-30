<?php
/**
 * Comparaison de modèles, côte à côte.
 *
 * Ce qu'on cherche à savoir n'est pas lequel « code le mieux » : c'est lequel
 * tient la discipline du socle, lequel n'invente pas, et ce que chacun coûte.
 * Les trois se mesurent, et c'est ce que montre ce tableau.
 *
 * @var array $p @var string $id @var array $rapport @var array $candidats
 */
use App\Ai;
use App\Compare;
use App\Mockup;
use App\Models;
use App\Prospect;

require __DIR__ . '/../partials/header.php';

$mesures = (array) ($rapport['mesures'] ?? []);
$page = (string) ($rapport['page'] ?? 'accueil');
$defaut = implode(',', array_map(
    static fn (array $c): string => $c['provider'] . ':' . $c['model'],
    $candidats
));
?>

<div class="page-head">
    <div>
        <h1>Comparer les modèles</h1>
        <div class="sub">
            <a href="<?= e(url('prospect', ['id' => $id])) ?>"><?= e(Prospect::displayName($p)) ?></a>
            <?php if ($mesures !== []): ?>
                · page « <?= e(Mockup::PAGES[$page] ?? $page) ?> »
                · <?= e(ago((int) ($rapport['at'] ?? 0))) ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="actions">
        <a class="btn ghost" href="<?= e(url('prospect', ['id' => $id])) ?>">Retour à la fiche</a>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2>Lancer une comparaison</h2></div>
    <p class="small muted">
        La même page est produite par chaque modèle, <strong>à partir du même brief</strong> : une seule
        variable change d'un candidat à l'autre. Comptez une génération de page par modèle — quelques
        centimes, et la réponse vaut mieux que n'importe quel classement.
    </p>

    <div class="field">
        <label for="candidats">Modèles à comparer</label>
        <input type="text" id="candidats" class="mono" value="<?= e($defaut) ?>"
               spellcheck="false" data-candidats>
        <span class="hint muted tiny">
            Un candidat par virgule, sous la forme <span class="mono">fournisseur:modèle</span> —
            <span class="mono">claude:claude-haiku-4-5</span>, <span class="mono">deepseek:deepseek-chat</span>.
            Quatre au maximum. Le premier est votre réglage actuel : sans lui, vous compareriez deux
            inconnues sans savoir si l'une vaut mieux que ce que vous avez déjà.
        </span>
    </div>

    <div class="row">
        <?php foreach (Mockup::PAGES as $cle => $label): ?>
            <button class="btn<?= $cle === 'accueil' ? ' primary' : ' ghost' ?>" type="button"
                    data-analyze="<?= e(url('compare_stream', ['id' => $id, 'page' => $cle])) ?>"
                    data-busy="Comparaison en cours…"
                    data-title="Comparaison des modèles"
                    data-candidats-source="candidats">
                Comparer sur « <?= e($label) ?> »
            </button>
        <?php endforeach; ?>
    </div>
</div>

<div class="card" id="run-panel" hidden>
    <div class="card-head">
        <h2 id="run-title">Comparaison en cours</h2>
        <div class="actions"><span class="badge brand" id="run-clock">0 s</span></div>
    </div>
    <div class="progress" id="run-progress"><span></span></div>
    <div class="console" id="console"></div>
</div>

<?php if ($mesures === []): ?>
    <div class="card">
        <div class="empty">
            <h3>Aucune comparaison encore</h3>
            <p>Il faut une maquette déjà générée : la comparaison rejoue une page à partir de son brief.</p>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-head"><h2>Résultats</h2></div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Modèle</th>
                    <th>Conformité au socle</th>
                    <th>Chiffres sans source</th>
                    <th class="right nowrap">Jetons</th>
                    <th class="right nowrap">Durée</th>
                    <th class="right nowrap">Coût / page</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($mesures as $m): ?>
                    <tr>
                        <td data-label="Modèle">
                            <strong><?= e(Ai::label((string) $m['provider'])) ?></strong>
                            <div class="tiny muted mono"><?= e((string) $m['model']) ?></div>
                            <?php if (($m['note'] ?? '') !== ''): ?>
                                <span class="badge brand"><?= e((string) $m['note']) ?></span>
                            <?php endif; ?>
                        </td>
                        <?php if (isset($m['echec'])): ?>
                            <td colspan="5" data-label="Échec">
                                <span class="badge danger">Échec</span> <?= e((string) $m['echec']) ?>
                            </td>
                        <?php else: ?>
                            <td data-label="Conformité au socle">
                                <?php if ($m['conforme']): ?>
                                    <span class="badge ok">conforme</span>
                                <?php else: ?>
                                    <span class="badge warn"><?= count($m['ecarts']) ?> écart(s)</span>
                                    <ul class="tiny muted mt">
                                        <?php foreach (array_slice($m['ecarts'], 0, 3) as $ecart): ?>
                                            <li><?= e(mb_substr((string) $ecart, 0, 120)) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </td>
                            <td data-label="Chiffres sans source">
                                <?php $inventes = (array) ($m['chiffres_inventes'] ?? []); ?>
                                <?php if ($inventes === []): ?>
                                    <span class="badge ok">aucun</span>
                                <?php else: ?>
                                    <span class="badge danger"><?= count($inventes) ?></span>
                                    <ul class="tiny muted mt">
                                        <?php foreach (array_slice($inventes, 0, 3) as $extrait): ?>
                                            <li>« <?= e((string) $extrait) ?> »</li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </td>
                            <td class="right nowrap tiny muted" data-label="Jetons">
                                <?= number_format((int) $m['entree'], 0, ',', ' ') ?> ↓<br>
                                <?= number_format((int) $m['sortie'], 0, ',', ' ') ?> ↑
                            </td>
                            <td class="right nowrap" data-label="Durée"><?= e((string) $m['duree']) ?> s</td>
                            <td class="right nowrap strong" data-label="Coût / page">
                                <?= $m['cout'] === null
                                    ? '<span class="faint">tarif non relevé</span>'
                                    : e(Models::formatCost((float) $m['cout'])) ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="tiny muted">
            « Chiffres sans source » relève les nombres affichés dans la page qu'on ne retrouve ni dans le
            brief, ni dans le contenu relevé sur le site. C'est la faute que le contrôle de conformité ne
            peut pas voir — il ne juge que la forme — et celle qui coûte le plus cher : une maquette qui
            annonce à un prospect vingt-cinq ans d'expérience quand il en a huit. Vérifiez les extraits :
            un nombre légitime peut s'y glisser, par exemple une adresse reformulée.
        </p>
    </div>

    <div class="card">
        <div class="card-head"><h2>Les pages produites</h2></div>
        <div class="comparaison">
            <?php foreach ($mesures as $m): ?>
                <?php if (isset($m['echec'])) { continue; } ?>
                <figure class="comparaison__volet">
                    <figcaption>
                        <strong class="mono"><?= e((string) $m['model']) ?></strong>
                        <a class="tiny" target="_blank" rel="noopener"
                           href="<?= e(url('compare_preview', ['id' => $id, 'c' => (string) $m['slug']])) ?>">Ouvrir</a>
                    </figcaption>
                    <div class="comparaison__cadre" data-apercu-compare>
                        <iframe src="<?= e(url('compare_preview', ['id' => $id, 'c' => (string) $m['slug']])) ?>"
                                title="Page produite par <?= e((string) $m['model']) ?>" loading="lazy"
                                scrolling="no" tabindex="-1"></iframe>
                    </div>
                </figure>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../partials/footer.php'; ?>
