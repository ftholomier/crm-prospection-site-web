<?php
/**
 * Éditeur de maquette : les champs à gauche, la maquette à droite.
 *
 * Tout ce qui est saisi se voit immédiatement dans l'aperçu, sans aller-retour
 * avec le serveur : le cadre est servi depuis notre domaine, donc le script de
 * cette page peut y écrire directement. L'enregistrement n'intervient qu'au
 * moment où on le demande.
 *
 * @var array $p @var string $id @var string $version @var string $page
 * @var array $groupes @var array $palette @var array $actifs @var string $previewUrl
 * @var string $assetPattern
 */
use App\Assets;
use App\Csrf;
use App\Mockup;
use App\Palette;
use App\Prospect;

require __DIR__ . '/../partials/header.php';

$reglages = Palette::reglages($palette);
$logo = Assets::src($actifs['logo'] ?? null);
$logoUrl = $logo === null ? null : (str_starts_with($logo, 'assets/')
    ? url('mockup_asset', ['id' => $id, 'f' => basename($logo)])
    : $logo);
?>

<div class="page-head">
    <div>
        <h1>Modifier la maquette</h1>
        <div class="sub">
            <a href="<?= e(url('prospect', ['id' => $id])) ?>"><?= e(Prospect::displayName($p)) ?></a>
            · version <?= e($version) ?>
            · <span class="muted">aucune IA n'est sollicitée ici</span>
        </div>
    </div>
    <div class="actions">
        <?php foreach (Mockup::PAGES as $cle => $label): ?>
            <a class="btn small<?= $cle === $page ? ' primary' : ' ghost' ?>"
               href="<?= e(url('mockup_edit', ['id' => $id, 'v' => $version, 'p' => $cle])) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<form method="post" action="<?= e(url('mockup_edit_save')) ?>" class="editeur"
      data-editeur
      data-media="<?= e(url('mockup_media')) ?>"
      data-id="<?= e($id) ?>"
      data-actifs="<?= e($assetPattern) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= e($id) ?>">
    <input type="hidden" name="v" value="<?= e($version) ?>">
    <input type="hidden" name="p" value="<?= e($page) ?>">

    <aside class="editeur__panneau">
        <div class="editeur__barre">
            <button class="btn small primary" type="submit">Enregistrer</button>
            <button class="btn small ghost" type="reset" data-annuler>Annuler mes retouches</button>
            <input type="search" class="editeur__filtre" data-filtre placeholder="Filtrer les champs…"
                   aria-label="Filtrer les champs">
        </div>

        <details class="bloc" open>
            <summary><span>Charte</span><span class="tiny muted">couleurs et logo</span></summary>
            <div class="bloc__corps">
                <p class="tiny muted">
                    Les couleurs s'appliquent aux trois pages à la fois, et prennent effet immédiatement —
                    y compris sur une maquette déjà envoyée.
                </p>
                <?php foreach ($reglages as $cle => $reglage): ?>
                    <div class="champ">
                        <label for="charte_<?= e($cle) ?>"><?= e($reglage['label']) ?></label>
                        <div class="champ__couleur">
                            <input type="color" id="charte_<?= e($cle) ?>" value="<?= e($reglage['valeur']) ?>"
                                   name="couleur_<?= e($cle) ?>" data-charte="<?= e($cle) ?>">
                            <input type="text" class="code" value="<?= e($reglage['valeur']) ?>"
                                   data-charte-code="<?= e($cle) ?>" spellcheck="false"
                                   aria-label="Code hexadécimal — <?= e($reglage['label']) ?>">
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="champ">
                    <label>Logo</label>
                    <?php if ($logoUrl !== null): ?>
                        <div class="logo-apercu"><img src="<?= e($logoUrl) ?>" alt="Logo"></div>
                    <?php else: ?>
                        <p class="tiny muted">
                            Aucun logo. <a href="<?= e(url('prospect', ['id' => $id])) ?>">Déposez-le depuis la fiche</a>,
                            puis revenez ici le placer dans l'en-tête.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </details>

        <?php foreach ($groupes as $index => $groupe): ?>
            <details class="bloc"<?= $index === 0 ? ' open' : '' ?>>
                <summary>
                    <span><?= e($groupe['titre']) ?></span>
                    <span class="tiny muted"><?= count($groupe['champs']) ?></span>
                </summary>
                <div class="bloc__corps">
                    <?php foreach ($groupe['champs'] as $rang => $champ): ?>
                        <?php
                        $nom = 'champs[' . $champ['chemin'] . '#' . $champ['type'] . ']';
                        $ident = 'c' . str_replace('/', '_', $champ['chemin']) . '_' . $champ['type'];
                        ?>
                        <div class="champ<?= ($champ['type'] === 'image' && !empty($champ['a_pourvoir'])) ? ' champ--a-pourvoir' : '' ?>">
                            <label for="<?= e($ident) ?>">
                                <?= e($champ['label']) ?>
                                <?php if ($champ['type'] === 'image' && !empty($champ['a_pourvoir'])): ?>
                                    <span class="badge warn">à pourvoir</span>
                                <?php endif; ?>
                            </label>

                            <?php if ($champ['type'] === 'image'): ?>
                                <div class="champ__image">
                                    <input type="text" id="<?= e($ident) ?>" name="<?= e($nom) ?>"
                                           value="<?= e((string) $champ['valeur']) ?>"
                                           data-champ="<?= e($champ['chemin']) ?>" data-type="image"
                                           placeholder="assets/photo-01.jpg ou https://…" spellcheck="false">
                                    <label class="btn small ghost" style="margin:0">
                                        Déposer
                                        <input type="file" accept="image/png,image/jpeg,image/webp,image/gif"
                                               hidden data-depot="<?= e($ident) ?>">
                                    </label>
                                </div>
                                <span class="hint muted tiny">
                                    Collez l'adresse d'une image du site, ou déposez un fichier.
                                </span>
                            <?php elseif (!empty($champ['long'])): ?>
                                <textarea id="<?= e($ident) ?>" name="<?= e($nom) ?>" rows="3"
                                          data-champ="<?= e($champ['chemin']) ?>" data-type="<?= e($champ['type']) ?>"><?= e((string) $champ['valeur']) ?></textarea>
                            <?php else: ?>
                                <input type="text" id="<?= e($ident) ?>" name="<?= e($nom) ?>"
                                       value="<?= e((string) $champ['valeur']) ?>"
                                       data-champ="<?= e($champ['chemin']) ?>" data-type="<?= e($champ['type']) ?>">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endforeach; ?>

        <?php if ($groupes === []): ?>
            <div class="empty">
                <h3>Rien à modifier</h3>
                <p>Cette page ne contient aucun texte ni aucune image reconnaissable.</p>
            </div>
        <?php endif; ?>
    </aside>

    <div class="editeur__apercu">
        <div class="editeur__outils">
            <div class="row">
                <button class="btn small ghost" type="button" data-largeur="100%">Bureau</button>
                <button class="btn small ghost" type="button" data-largeur="820px">Tablette</button>
                <button class="btn small ghost" type="button" data-largeur="390px">Mobile</button>
            </div>
            <a class="btn small ghost" href="<?= e($previewUrl) ?>" target="_blank" rel="noopener">Ouvrir dans un onglet</a>
        </div>
        <div class="editeur__cadre">
            <iframe src="<?= e($previewUrl) ?>" title="Aperçu de la maquette" data-apercu></iframe>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../partials/footer.php'; ?>
