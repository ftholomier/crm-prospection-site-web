<?php
/**
 * @var array $p @var array $versions @var string $currentVersion @var array $timeline
 * @var array $sends @var array $schedule @var string $mockupUrl @var bool $hasShot @var string $shotUrl
 * @var array $palette @var array $actifs @var array $consommation @var array $fichesDuDomaine
 */
use App\Assets;
use App\Palette;
use App\Config;
use App\Csrf;
use App\Events;
use App\Mockup;
use App\Prospect;
use App\Sequence;
use App\Suppression;
use App\Templates;
use App\Util;

require __DIR__ . '/../partials/header.php';

$id = (string) $p['id'];
$audit = $p['audit'] ?? [];
$hasAnalysis = !empty($p['audit']);
$hasMockup = $currentVersion !== '' && Mockup::isComplete($id, $currentVersion);
$isValidated = !empty($p['mockup']['validated']);
$sequence = $p['sequence'] ?? [];
$previewPage = Mockup::safePage((string) ($_GET['page'] ?? 'accueil'));
$autorun = ($_GET['autorun'] ?? '') === 'analyze' && !$hasAnalysis;
$mailable = Prospect::isMailable($p);

/* Un actif copié chez nous se sert par une route de l'administration ; un actif
   pointé à distance garde son adresse. Les deux cartes qui affichent des images
   — les rôles et le catalogue — s'en servent, d'où la définition ici. */
$servirActif = static fn (string $src): string => str_starts_with($src, 'assets/')
    ? url('mockup_asset', ['id' => $id, 'f' => basename($src)])
    : $src;
?>

<div class="page-head">
    <div>
        <h1><?= e(Prospect::displayName($p)) ?></h1>
        <div class="sub">
            <a href="<?= e((string) $p['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e((string) $p['url']) ?></a>
            · <?php $status = (string) $p['status']; require __DIR__ . '/../partials/status.php'; ?>
            <?php if (Suppression::has((string) $p['email'])): ?>
                <span class="badge danger">Désinscrit</span>
            <?php endif; ?>
            <?php if (count($fichesDuDomaine) > 1): ?>
                <?php
                // Plusieurs fiches pour un même site : on est en comparaison.
                // Sans ce rappel, on ne sait plus laquelle on regarde.
                $rangIci = 0;
                foreach ($fichesDuDomaine as $i => $f) {
                    if ((string) $f['id'] === $id) { $rangIci = $i + 1; }
                }
                ?>
                <br>
                <span class="badge brand">fiche <?= $rangIci ?> sur <?= count($fichesDuDomaine) ?></span>
                <span class="tiny muted">pour ce domaine —</span>
                <?php foreach ($fichesDuDomaine as $i => $f): ?>
                    <?php if ((string) $f['id'] !== $id): ?>
                        <a class="tiny" href="<?= e(url('prospect', ['id' => $f['id']])) ?>">fiche <?= $i + 1 ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="actions">
        <button class="btn" type="button"
                data-analyze="<?= e(url('analyze_stream', ['id' => $id])) ?>"
                data-busy="Analyse en cours…"
                data-title="Analyse du site"
                data-autorun="<?= $autorun ? '1' : '0' ?>">
            <?= $hasAnalysis ? 'Relancer l\'analyse' : 'Analyser le site' ?>
        </button>
        <?php if ($hasAnalysis && !$hasMockup): ?>
            <form data-generate="<?= e(url('generate_stream', ['id' => $id])) ?>"
                  data-mode="new"
                  data-pages="<?= e(implode(',', array_keys(Mockup::PAGES))) ?>">
                <button class="btn primary" type="submit">Générer les 3 maquettes</button>
                <span class="hint muted tiny">
                    Une fois les pages produites, l'<strong>éditeur</strong> s'ouvre depuis la maquette :
                    couleurs, photos et textes se corrigent à la main, sans repasser par l'IA ni
                    consommer de jetons.
                </span>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card" id="run-panel" hidden>
    <div class="card-head">
        <h2 id="run-title">Traitement en cours</h2>
        <div class="actions">
            <span class="badge brand" id="run-clock">0 s</span>
        </div>
    </div>
    <div class="progress" id="run-progress"><span></span></div>
    <div class="console" id="console"></div>
    <p class="tiny muted mt">Ne fermez pas cet onglet : chaque étape est enregistrée dès qu'elle est terminée, et le détail reste consultable après coup.</p>
</div>

<?php $run = $p['last_run'] ?? null; ?>
<?php if (is_array($run) && !empty($run['steps'])): ?>
    <div class="card" id="dernier-traitement">
        <div class="card-head">
            <h2><?= e((string) ($run['type'] ?? 'Dernier traitement')) ?></h2>
            <div class="actions">
                <span class="badge <?= !empty($run['ok']) ? 'ok' : 'danger' ?>">
                    <?= !empty($run['ok']) ? 'Terminé' : 'Interrompu' ?>
                </span>
                <span class="tiny muted"><?= e(ago((int) ($run['at'] ?? 0))) ?></span>
            </div>
        </div>
        <?php if (trim((string) ($run['conclusion'] ?? '')) !== ''): ?>
            <p class="strong"><?= e((string) $run['conclusion']) ?></p>
        <?php endif; ?>
        <ol class="run-steps">
            <?php foreach ($run['steps'] as $step): ?>
                <li class="run-step run-step--<?= e((string) ($step['state'] ?? 'running')) ?>">
                    <?= e((string) $step['message']) ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
<?php endif; ?>

<div class="grid side">
    <div>
        <?php if ($hasAnalysis): ?>
            <div class="card">
                <div class="card-head">
                    <h2>Audit du site actuel</h2>
                    <div class="actions">
                        <span class="badge <?= (int) $audit['score'] >= 45 ? 'danger' : 'warn' ?>"><?= e((string) $audit['level']) ?></span>
                    </div>
                </div>
                <div class="row mb">
                    <?php $score = (int) $audit['score']; require __DIR__ . '/../partials/score.php'; ?>
                    <div>
                        <strong><?= (int) $audit['score'] ?>/100 de vétusté</strong>
                        <div class="tiny muted">Plus le score est élevé, plus le site est daté — et plus l'argumentaire est solide.</div>
                    </div>
                </div>
                <ul class="findings">
                    <?php foreach ($audit['findings'] ?? [] as $finding): ?>
                        <li>
                            <span class="w badge <?= $finding['severity'] === 'critique' ? 'danger' : ($finding['severity'] === 'important' ? 'warn' : '') ?>">
                                +<?= (int) $finding['weight'] ?>
                            </span>
                            <div>
                                <strong><?= e((string) $finding['label']) ?></strong>
                                <div class="muted"><?= e((string) $finding['detail']) ?></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (($audit['findings'] ?? []) === []): ?>
                    <p class="muted small">Aucun défaut majeur détecté : ce site n'est probablement pas une bonne cible.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="empty">
                    <h3>Site pas encore analysé</h3>
                    <p>L'analyse lit la page d'accueil et les pages clés, calcule le score de vétusté et récupère les coordonnées.</p>
                    <p class="small">Si le site refuse la lecture automatique, fournissez la page vous-même
                        <a href="#html_accueil">dans le champ juste en dessous</a> : le résultat est identique.</p>
                </div>
            </div>
        <?php endif; ?>

        <div class="card" id="saisie-manuelle">
            <div class="card-head">
                <h2 id="saisie-manuelle">Le site bloque l'analyse ?</h2>
                <?php if ($hasManualSource): ?>
                    <div class="actions"><span class="badge ok">Code source enregistré</span></div>
                <?php endif; ?>
            </div>
            <p class="small muted">
                Certains sites refusent toute lecture automatique et répondent une erreur 403.
                Deux solutions, indépendantes et cumulables.
            </p>

            <div class="divider"></div>

            <h3>1. Laisser l'IA lire le site</h3>
            <p class="small muted">
                La lecture part alors de l'infrastructure d'Anthropic, pas de votre serveur : le pare-feu
                qui filtre l'adresse IP de votre hébergement ne s'y applique pas. Le modèle parcourt la page
                d'accueil <strong>et les pages internes</strong> — contact, mentions légales, à propos,
                prestations — et en rapporte le contenu, avec ses photos, son logo et ses couleurs.
                Consomme des crédits API, environ le tiers d'une maquette.
            </p>
            <?php if (!App\Ai::canReadSites()): ?>
                <p class="small muted">
                    <span class="badge warn">Claude requis</span>
                    Cette étape passe toujours par Claude : elle repose sur un outil exécuté chez Anthropic,
                    que ni DeepSeek ni Gemini n'ont. Une clé Claude renseignée suffit, même si vos
                    maquettes sont générées ailleurs.
                </p>
            <?php endif; ?>
            <button class="btn primary" type="button"
                    data-analyze="<?= e(url('read_site_stream', ['id' => $id])) ?>"
                    data-busy="Lecture du site en cours…"
                    data-title="Lecture du site par l'IA"
                    data-autorun="0">Lire le site avec l'IA</button>
            <p class="tiny muted mt">
                Le modèle rapporte ce qu'il lit, sans rien inventer. Il ne voit ni les couleurs, ni les
                polices, ni le code technique : le score de vétusté reste calculé sur du HTML réel, donc
                issu d'une lecture directe ou du collage ci-dessous.
            </p>

            <div class="divider"></div>

            <h3>2. Coller le code source vous-même</h3>
            <p class="small muted">
                Ouvrez <a href="<?= e((string) $p['url']) ?>" target="_blank" rel="noopener noreferrer">le site</a>,
                affichez le code source (<strong>Ctrl+U</strong>, ou <strong>⌥⌘U</strong> sur Mac),
                sélectionnez tout (<strong>Ctrl+A</strong>) et collez. C'est la seule voie qui donne
                le score de vétusté, puisqu'il se calcule sur le code lui-même.
            </p>
            <?php /* Le bouton suivait les trois champs facultatifs : cinq cents pixels
                     sous le champ où l'on venait de coller, et gris parce qu'une
                     analyse existait déjà. On collait, et plus rien n'indiquait quoi
                     faire. L'action est maintenant collée au champ qui la commande,
                     et les pages facultatives sont repliées en dessous. */ ?>
            <form method="post" action="<?= e(url('prospect_manual')) ?>" data-collage>
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= e($id) ?>">
                <div class="field">
                    <label for="html_accueil">Page d'accueil <span class="muted">— indispensable</span></label>
                    <textarea class="code" name="html_accueil" id="html_accueil" rows="6"
                              data-collage-champ
                              placeholder="&lt;!DOCTYPE html&gt;&#10;&lt;html lang=&quot;fr&quot;&gt;…"></textarea>
                </div>

                <div class="row">
                    <button class="btn primary" type="submit">
                        <?= $hasManualSource ? 'Remplacer les pages collées' : 'Analyser ces pages' ?>
                    </button>
                    <span class="tiny muted" data-collage-etat>Collez le code source ci-dessus, puis cliquez ici.</span>
                </div>

                <details class="mt">
                    <summary class="small">Ajouter d'autres pages — facultatif, améliore le relevé</summary>
                    <?php foreach ([
                        'contact' => ['Page contact', 'Porte le plus souvent l\'email et le téléphone.'],
                        'legal' => ['Mentions légales', 'Porte la raison sociale exacte et le SIREN.'],
                        'services' => ['Page prestations', 'Alimente la page Prestations de la maquette.'],
                    ] as $role => [$titre, $aide]): ?>
                        <div class="field mt">
                            <label for="html_<?= e($role) ?>"><?= e($titre) ?></label>
                            <textarea class="code" name="html_<?= e($role) ?>" id="html_<?= e($role) ?>" rows="3"
                                      data-collage-champ></textarea>
                            <span class="hint muted tiny"><?= e($aide) ?></span>
                        </div>
                    <?php endforeach; ?>
                </details>

                <p class="tiny muted mt">
                    Les feuilles de style externes restent hors de portée : les couleurs sont déduites du
                    code collé. Une page dépassant la taille acceptée par votre hébergement est refusée
                    avec un message explicite — collez-en une à la fois si le cas se présente.
                </p>
            </form>
        </div>

        <?php if ($hasMockup): ?>
            <div class="card">
                <div class="card-head">
                    <h2>Maquette <?= e($currentVersion) ?></h2>
                    <div class="actions">
                        <?php if ($isValidated): ?>
                            <span class="badge ok">Validée</span>
                        <?php else: ?>
                            <span class="badge warn">À valider</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="tabs">
                    <?php foreach (Mockup::PAGES as $key => $label): ?>
                        <a href="<?= e(url('prospect', ['id' => $id, 'page' => $key])) ?>#maquette"
                           class="<?= $previewPage === $key ? 'active' : '' ?>"><?= e($label) ?></a>
                    <?php endforeach; ?>
                </div>

                <div class="row mb" id="maquette">
                    <div class="device-bar" data-devices="preview">
                        <button type="button" data-width="100%" class="active">Ordinateur</button>
                        <button type="button" data-width="820px">Tablette</button>
                        <button type="button" data-width="390px">Mobile</button>
                    </div>
                    <div class="actions" style="margin-left:auto">
                        <a class="btn small primary" href="<?= e(url('mockup_edit', ['id' => $id, 'v' => $currentVersion, 'p' => $previewPage])) ?>">
                            Éditeur — couleurs, photos, textes
                        </a>
                        <a class="btn small" href="<?= e(url('compare', ['id' => $id])) ?>">Comparer les modèles</a>
                        <a class="btn small" href="<?= e(url('mockup_preview', ['id' => $id, 'v' => $currentVersion, 'p' => $previewPage])) ?>" target="_blank" rel="noopener">Ouvrir en grand</a>
                        <a class="btn small" href="<?= e(url('mockup_download', ['id' => $id, 'v' => $currentVersion, 'p' => $previewPage])) ?>">Télécharger</a>
                    </div>
                </div>

                <iframe id="preview" class="preview-frame"
                        src="<?= e(url('mockup_preview', ['id' => $id, 'v' => $currentVersion, 'p' => $previewPage])) ?>"
                        title="Prévisualisation de la maquette" loading="lazy"></iframe>

                <div class="divider"></div>

                <div class="row">
                    <form method="post" action="<?= e(url('mockup_validate')) ?>">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="id" value="<?= e($id) ?>">
                        <input type="hidden" name="action" value="<?= $isValidated ? 'unvalidate' : 'validate' ?>">
                        <button class="btn <?= $isValidated ? '' : 'ok' ?>" type="submit">
                            <?= $isValidated ? 'Retirer la validation' : 'Valider cette maquette' ?>
                        </button>
                    </form>
                    <button class="btn small" type="button" data-copy="<?= e($mockupUrl) ?>">Copier le lien prospect</button>
                    <a class="btn small ghost" href="<?= e($mockupUrl) ?>" target="_blank" rel="noopener">Voir comme le prospect</a>
                </div>
            </div>

            <div class="card">
                <div class="card-head"><h2>Retoucher par prompt</h2></div>
                <form data-generate="<?= e(url('generate_stream', ['id' => $id])) ?>" data-mode="revise">
                    <div class="field">
                        <label for="instruction">Ce que vous voulez changer</label>
                        <textarea name="instruction" id="instruction" placeholder="Exemples : passe l'ensemble en tons plus sombres et plus haut de gamme. Remplace la section témoignages par une galerie de réalisations. Rends l'en-tête plus compact et ajoute le numéro de téléphone en évidence."></textarea>
                        <span class="hint muted tiny">Une nouvelle version est créée : la version actuelle reste intacte tant que vous ne changez pas de version active.</span>
                    </div>
                    <div class="field">
                        <label>Pages à retoucher</label>
                        <div class="row">
                            <?php foreach (Mockup::PAGES as $key => $label): ?>
                                <label class="check" style="margin:0">
                                    <input type="checkbox" name="pages[]" value="<?= e($key) ?>" checked>
                                    <span><?= e($label) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <button class="btn primary" type="submit">Générer une nouvelle version</button>
                </form>

                <?php if (count($versions) > 1): ?>
                    <div class="divider"></div>
                    <h3>Versions</h3>
                    <div class="table-wrap">
                        <table>
                            <tbody>
                            <?php foreach ($versions as $version): ?>
                                <?php $meta = Mockup::meta($id, $version); ?>
                                <tr>
                                    <td>
                                        <strong><?= e($version) ?></strong>
                                        <?php if ($version === $currentVersion): ?><span class="badge brand">Active</span><?php endif; ?>
                                        <div class="tiny muted">
                                            <?= e(dt((int) ($meta['created_at'] ?? 0))) ?>
                                            <?php if (!empty($meta['instruction'])): ?>
                                                — « <?= e(Util::truncate((string) $meta['instruction'], 90)) ?> »
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="right nowrap">
                                        <a class="btn small ghost" href="<?= e(url('mockup_preview', ['id' => $id, 'v' => $version, 'p' => 'accueil'])) ?>" target="_blank" rel="noopener">Voir</a>
                                        <?php if ($version !== $currentVersion): ?>
                                            <form method="post" action="<?= e(url('mockup_use')) ?>" style="display:inline">
                                                <?= Csrf::field() ?>
                                                <input type="hidden" name="id" value="<?= e($id) ?>">
                                                <input type="hidden" name="version" value="<?= e($version) ?>">
                                                <button class="btn small" type="submit">Activer</button>
                                            </form>
                                            <form method="post" action="<?= e(url('mockup_delete')) ?>" style="display:inline"
                                                  data-confirm="Supprimer définitivement la version <?= e($version) ?> ?">
                                                <?= Csrf::field() ?>
                                                <input type="hidden" name="id" value="<?= e($id) ?>">
                                                <input type="hidden" name="version" value="<?= e($version) ?>">
                                                <button class="btn small danger" type="submit">Supprimer</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($hasMockup): ?>
            <div class="card">
                <div class="card-head">
                    <h2>Séquence d'emails</h2>
                    <div class="actions">
                        <?php if (!empty($sequence['active'])): ?>
                            <span class="badge brand">En cours — email <?= (int) $sequence['step'] + 1 ?> le <?= e(dt((int) $sequence['next_at'], 'd/m à H:i')) ?></span>
                        <?php elseif ((string) ($sequence['stopped_reason'] ?? '') !== ''): ?>
                            <span class="badge"><?= e((string) $sequence['stopped_reason']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!$isValidated): ?>
                    <div class="flash info">Validez la maquette pour pouvoir lancer la séquence.</div>
                <?php elseif (!$mailable): ?>
                    <div class="flash error">Renseignez une adresse email valide dans la fiche pour lancer la séquence.</div>
                <?php endif; ?>

                <div class="row mb">
                    <?php if (empty($sequence['active'])): ?>
                        <form method="post" action="<?= e(url('sequence_start')) ?>">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= e($id) ?>">
                            <button class="btn primary" type="submit" <?= (!$isValidated || !$mailable) ? 'disabled' : '' ?>>
                                Lancer la séquence automatique
                            </button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="<?= e(url('sequence_stop')) ?>">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= e($id) ?>">
                            <button class="btn danger" type="submit">Arrêter la séquence</button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Étape</th><th>État</th><th class="right">Actions</th></tr></thead>
                        <tbody>
                        <?php foreach (Templates::STEPS as $step => $label): ?>
                            <?php
                            $sent = array_values(array_filter($sends, static fn (array $s): bool => (int) $s['step'] === $step));
                            $last = $sent[0] ?? null;
                            ?>
                            <tr>
                                <td data-label="Étape">
                                    <strong><?= e($label) ?></strong>
                                    <?php if ($last === null && !empty($sequence['active']) && (int) $sequence['step'] + 1 === $step): ?>
                                        <div class="tiny muted">Programmé le <?= e(dt((int) $sequence['next_at'], 'd/m à H:i')) ?></div>
                                    <?php elseif ($last === null): ?>
                                        <div class="tiny faint">Prévu <?= e(dt((int) ($schedule[$step] ?? 0), 'd/m')) ?> si lancée aujourd'hui</div>
                                    <?php endif; ?>
                                </td>
                                <td class="small" data-label="État">
                                    <?php if ($last === null): ?>
                                        <span class="faint">Pas encore envoyé</span>
                                    <?php else: ?>
                                        <span class="badge ok">Envoyé <?= e(dt((int) $last['sent_at'], 'd/m à H:i')) ?></span>
                                        <?php if (!empty($last['opened_at'])): ?><span class="badge brand">Ouvert</span><?php endif; ?>
                                        <?php if (!empty($last['clicked_at'])): ?><span class="badge ok">Cliqué</span><?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="right nowrap" data-label="Actions">
                                    <a class="btn small ghost" href="<?= e(url('email_preview', ['id' => $id, 'step' => $step])) ?>" target="_blank" rel="noopener">Aperçu</a>
                                    <form method="post" action="<?= e(url('send_now')) ?>" style="display:inline"
                                          data-confirm="Envoyer maintenant l'email <?= (int) $step ?> à <?= e((string) $p['email']) ?> ?">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="id" value="<?= e($id) ?>">
                                        <input type="hidden" name="step" value="<?= (int) $step ?>">
                                        <button class="btn small" type="submit" <?= (!$isValidated || !$mailable) ? 'disabled' : '' ?>>Envoyer</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div>
        <div class="card">
            <div class="card-head"><h2>Fiche</h2></div>
            <form method="post" action="<?= e(url('prospect_save')) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= e($id) ?>">

                <div class="field">
                    <label for="company">Raison sociale</label>
                    <input type="text" name="company" id="company" value="<?= e((string) $p['company']) ?>">
                </div>
                <div class="field-row">
                    <div class="field">
                        <label for="first_name">Prénom</label>
                        <input type="text" name="first_name" id="first_name" value="<?= e((string) $p['first_name']) ?>">
                    </div>
                    <div class="field">
                        <label for="last_name">Nom</label>
                        <input type="text" name="last_name" id="last_name" value="<?= e((string) $p['last_name']) ?>">
                    </div>
                </div>
                <div class="field">
                    <label for="email">Email <span class="muted">— indispensable pour la séquence</span></label>
                    <input type="email" name="email" id="email" value="<?= e((string) $p['email']) ?>">
                    <?php if (!empty($p['candidate_emails']) && count($p['candidate_emails']) > 1): ?>
                        <div class="var-list mt">
                            <?php foreach ($p['candidate_emails'] as $candidate): ?>
                                <code data-insert="<?= e($candidate) ?>" data-target="#email"><?= e($candidate) ?></code>
                            <?php endforeach; ?>
                        </div>
                        <span class="hint muted tiny">Adresses trouvées sur le site — cliquez pour l'insérer.</span>
                    <?php endif; ?>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label for="phone">Téléphone</label>
                        <input type="text" name="phone" id="phone" value="<?= e((string) $p['phone']) ?>">
                    </div>
                    <div class="field">
                        <label for="city">Ville</label>
                        <input type="text" name="city" id="city" value="<?= e((string) $p['city']) ?>">
                    </div>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label for="sector">Secteur</label>
                        <input type="text" name="sector" id="sector" value="<?= e((string) $p['sector']) ?>">
                    </div>
                    <div class="field">
                        <label for="siren">SIREN</label>
                        <input type="text" name="siren" id="siren" value="<?= e((string) $p['siren']) ?>">
                    </div>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label for="monthly_price">Tarif mensuel proposé</label>
                        <input type="number" step="0.01" min="0" name="monthly_price" id="monthly_price" value="<?= e((string) $p['monthly_price']) ?>">
                    </div>
                    <div class="field">
                        <label for="status">Statut</label>
                        <select name="status" id="status">
                            <?php foreach (Prospect::PIPELINE as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= (string) $p['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="field">
                    <label for="notes">Notes</label>
                    <textarea name="notes" id="notes" rows="4"><?= e((string) $p['notes']) ?></textarea>
                </div>
                <div class="field">
                    <label for="design_prompt">Consignes de design propres à ce prospect</label>
                    <textarea name="design_prompt" id="design_prompt" rows="3" placeholder="S'ajoute au prompt global des Réglages. Exemple : secteur médical, ton rassurant, éviter le rouge."><?= e((string) ($p['design_prompt'] ?? '')) ?></textarea>
                </div>
                <button class="btn primary" type="submit">Enregistrer</button>
            </form>
        </div>

        <div class="card">
            <div class="card-head"><h2>Site actuel</h2></div>
            <?php if ($hasShot): ?>
                <img src="<?= e($shotUrl) ?>" alt="Capture du site actuel" style="width:100%;border-radius:8px;border:1px solid var(--line)">
            <?php elseif ($brokenShot): ?>
                <div class="flash error">
                    Le fichier de capture enregistré n'est pas une image exploitable — le service a probablement
                    renvoyé une page d'erreur. Il est ignoré partout, et sera remplacé à la prochaine capture.
                </div>
            <?php else: ?>
                <p class="muted small">Aucune capture. Elle sert au comparatif avant/après montré au prospect, et permet au modèle de voir réellement le site.</p>
            <?php endif; ?>
            <form method="post" action="<?= e(url('screenshot')) ?>" enctype="multipart/form-data" class="stack mt">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= e($id) ?>">
                <div class="row">
                    <button class="btn small" type="submit"><?= $hasShot ? 'Recapturer' : 'Capturer maintenant' ?></button>
                    <label class="btn small ghost" style="margin:0">
                        Importer une image
                        <input type="file" name="capture" accept="image/png,image/jpeg,image/webp" hidden onchange="this.form.submit()">
                    </label>
                </div>
            </form>
        </div>

        <?php if (!empty($palette['marque'])): ?>
            <?php
            $reglages = Palette::reglages($palette);
            $source = (string) ($palette['source'] ?? 'repli');
            $fragile = false;
            foreach ($reglages as $r) {
                $fragile = $fragile || !Palette::lisible($r['ratio']);
            }
            ?>
            <div class="card">
                <div class="card-head">
                    <h2>Charte reprise du site</h2>
                    <span class="badge <?= $source === 'repli' ? 'warn' : 'ok' ?>">
                        <?= ['manuelle' => 'réglée à la main', 'site' => 'couleur du site'][$source] ?? 'teinte de repli' ?>
                    </span>
                </div>
                <p class="muted small">
                    <?php if ($source === 'manuelle'): ?>
                        Ces couleurs ont été réglées à la main. Elles sont conservées telles quelles, y compris
                        si vous relancez l'analyse du site.
                    <?php elseif ($source === 'site'): ?>
                        Couleur relevée sur le site du prospect. Ajustez-la si elle n'est pas la bonne :
                        les aplats, les accents et le gris des légendes sont recalculés à partir d'elle.
                    <?php else: ?>
                        Aucune couleur de charte nette n'a été trouvée — le site n'emploie que des gris ou
                        des beiges. Réglez la dominante ci-dessous si vous connaissez la couleur de l'entreprise.
                    <?php endif; ?>
                </p>

                <form method="post" action="<?= e(url('prospect_palette')) ?>" class="stack">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= e($id) ?>">
                    <div class="palette">
                        <?php foreach ($reglages as $cle => $reglage): ?>
                            <?php $ko = !Palette::lisible($reglage['ratio']); ?>
                            <div class="reglage<?= $ko ? ' reglage--fragile' : '' ?>">
                                <label for="couleur_<?= e($cle) ?>"><?= e($reglage['label']) ?></label>
                                <div class="reglage__saisie">
                                    <input type="color" name="couleur_<?= e($cle) ?>" id="couleur_<?= e($cle) ?>"
                                           value="<?= e($reglage['valeur']) ?>" data-couleur="<?= e($cle) ?>">
                                    <input type="text" class="code" value="<?= e($reglage['valeur']) ?>"
                                           data-miroir="<?= e($cle) ?>" spellcheck="false" aria-label="Code hexadécimal — <?= e($reglage['label']) ?>">
                                    <?php if ($reglage['ratio'] !== null): ?>
                                        <span class="tiny <?= $ko ? 'danger' : 'muted' ?>" title="Contraste le plus défavorable, sur les deux fonds du socle">
                                            <?= e(number_format((float) $reglage['ratio'], 2, ',', ' ')) ?>:1
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <span class="hint muted tiny"><?= e($reglage['aide']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($fragile): ?>
                        <p class="flash error small">
                            Une couleur au moins n'atteint pas 4,5:1 sur les deux fonds du socle : elle sera
                            pénible à lire. Vous pouvez l'enregistrer quand même — c'est votre décision, pas la mienne.
                        </p>
                    <?php endif; ?>

                    <?php $menuActuel = (string) ($palette['menu'] ?? 'lateral'); ?>
                    <div class="field">
                        <label for="menu">Menu affiché en premier</label>
                        <select name="menu" id="menu">
                            <?php foreach (App\Mockup::MENUS as $cleMenu => $labelMenu): ?>
                                <option value="<?= e($cleMenu) ?>" <?= $menuActuel === $cleMenu ? 'selected' : '' ?>>
                                    <?= e($labelMenu) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="hint muted tiny">
                            La maquette porte <strong>les deux menus</strong> et le prospect bascule de l'un
                            à l'autre par le bouton en bas à droite de sa page : c'est une décision qui se
                            prend en regardant. Ce réglage ne fixe que celui qu'il voit en arrivant, et il
                            s'applique sans régénérer.
                        </span>
                    </div>

                    <div class="row">
                        <button class="btn small primary" type="submit">Enregistrer la charte</button>
                        <?php if (!empty($p['palette_manuelle'])): ?>
                            <button class="btn small ghost" type="submit" name="action" value="reset">
                                Revenir aux couleurs du site
                            </button>
                        <?php endif; ?>
                    </div>
                </form>

                <details class="mt">
                    <summary class="small">Les déclinaisons calculées à partir de la dominante</summary>
                    <div class="palette mt">
                        <?php
                        $derivees = [
                            'marque_fonce' => ['Aplats portant du texte clair', $palette['mesures']['fonce_sur_blanc'] ?? null],
                            'marque_texte' => ['Petit texte de marque', $palette['mesures']['texte_sur_teinte'] ?? null],
                            'marque_claire' => ['Accents sur fond sombre', $palette['mesures']['claire_sur_sombre'] ?? null],
                            'marque_voile' => ['Aplats très pâles', null],
                            'corps_doux' => ['Légendes et chapôs', null],
                        ];
                        ?>
                        <?php foreach ($derivees as $cle => [$label, $ratio]): ?>
                            <?php if (!empty($palette[$cle])): ?>
                                <div class="jeton">
                                    <span class="jeton__pastille" style="background: <?= e((string) $palette[$cle]) ?>"></span>
                                    <span class="jeton__label"><?= e($label) ?></span>
                                    <code class="tiny"><?= e((string) $palette[$cle]) ?></code>
                                    <?php if ($ratio !== null): ?>
                                        <span class="tiny muted"><?= e(number_format((float) $ratio, 2, ',', ' ')) ?>:1</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </details>

                <p class="small mt">Police : <strong><?= e((string) ($palette['police_nom'] ?? '—')) ?></strong>
                    <?= empty($palette['police_import']) ? '<span class="muted tiny">(non chargée : repli système)</span>' : '<span class="muted tiny">(chargée depuis Google Fonts)</span>' ?>
                </p>
            </div>

            <div class="card" id="roles">
                <div class="card-head">
                    <h2>Logo et favicon</h2>
                </div>
                <p class="muted small">
                    Deux images à part, que la maquette n'emploie qu'à leur place. Vous pouvez les déposer
                    ici, ou <strong>désigner n'importe quelle photo du catalogue</strong> plus bas : la
                    lecture automatique se trompe souvent entre le logo, le favicon et une photo de
                    chantier.
                </p>

                <div class="field-row">
                    <?php foreach (Assets::ROLES as $roleCle => $roleLabel): ?>
                        <?php
                        $src = Assets::src($actifs[$roleCle] ?? null);
                        $source = (string) ($actifs[$roleCle]['source'] ?? '');
                        ?>
                        <div class="field">
                            <label><?= e($roleLabel) ?>
                                <?php if ($src !== null && $source !== ''): ?>
                                    <span class="badge <?= $source === 'site' ? '' : 'ok' ?>"><?= e($source) ?></span>
                                <?php endif; ?>
                            </label>

                            <?php if ($src !== null): ?>
                                <div class="logo-apercu">
                                    <img src="<?= e($servirActif($src)) ?>"
                                         alt="<?= e($roleLabel) ?> de <?= e(Prospect::displayName($p)) ?>">
                                </div>
                            <?php else: ?>
                                <p class="muted small">
                                    Aucun <?= e(mb_strtolower($roleLabel)) ?> récupéré. C'est fréquent :
                                    beaucoup de sites le posent en fond CSS, où aucune lecture automatique
                                    ne va le chercher.
                                </p>
                            <?php endif; ?>

                            <form method="post" action="<?= e(url('prospect_assets')) ?>"
                                  enctype="multipart/form-data" class="row mt">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= e($id) ?>">
                                <input type="hidden" name="action" value="deposer_role">
                                <input type="hidden" name="role" value="<?= e($roleCle) ?>">
                                <label class="btn small<?= $src === null ? ' primary' : ' ghost' ?>" style="margin:0">
                                    <?= $src === null ? 'Déposer' : 'Remplacer' ?>
                                    <input type="file" name="fichier"
                                           accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml"
                                           hidden onchange="this.form.submit()">
                                </label>
                            </form>
                            <?php if ($src !== null): ?>
                                <form method="post" action="<?= e(url('prospect_assets')) ?>" class="mt"
                                      data-confirm="Retirer <?= e(mb_strtolower($roleLabel)) ?> ?">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= e($id) ?>">
                                    <input type="hidden" name="action" value="retirer_role">
                                    <input type="hidden" name="role" value="<?= e($roleCle) ?>">
                                    <button class="btn small ghost" type="submit">Retirer</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <span class="hint muted tiny">
                    PNG, JPEG, WebP, GIF ou SVG, 3 Mo maximum. Une image déposée ou désignée ici survit à
                    une nouvelle analyse.
                </span>
            </div>
        <?php endif; ?>

        <?php
        $photos = $actifs['photos'] ?? [];
        $ecartees = Assets::ecartees($id);
        // La carte s'affiche même vide : c'est là qu'on ajoute une photo quand
        // la collecte n'a rien trouvé, et une carte cachée ne s'utilise pas.
        ?>
        <div class="card" id="actifs">
            <div class="card-head">
                <h2>Actifs récupérés</h2>
                <div class="actions">
                    <span class="badge"><?= count($photos) ?> photo<?= count($photos) > 1 ? 's' : '' ?></span>
                    <span class="badge"><?= ($actifs['mode'] ?? 'copie') === 'liens' ? 'liens distants' : 'copiés ici' ?></span>
                </div>
            </div>
            <p class="muted small">
                Ce sont ces fichiers-là que la maquette affichera, et rien d'autre : le modèle ne reçoit
                que ce catalogue, et une page qui inventerait une photo est refusée avant l'envoi.
                Écartez ce qui ne vous convient pas, ajoutez ce qui manque, <strong>puis relancez la
                génération</strong> — c'est ainsi qu'on pilote ce que montrera la maquette suivante.
            </p>
            <p class="tiny muted">
                L'analyse repère souvent bien plus d'images qu'elle n'en retient : elle s'arrête à
                <strong><?= (int) App\Assets::maxPhotos() ?></strong><?= App\Assets::mode() === 'liens'
                    ? ' — en mode « liens », rien n\'est téléchargé, la limite est donc large'
                    : ' pour ne pas recopier tout le site' ?>.
                Le compte rendu de l'analyse dit ce qu'il est advenu de chacune — retenue, au-delà de la
                limite, ou refusée par le site. Une image laissée de côté se rattrape en collant son
                adresse ci-dessous.
            </p>

            <?php if ($photos === [] && empty($actifs['logo'])): ?>
                <div class="empty">
                    <h3>Aucun actif</h3>
                    <p>La collecte n'a rien rapporté — site bloqué, images en fond CSS, ou analyse pas
                        encore lancée. Ajoutez ci-dessous les photos que vous voulez voir dans la maquette.</p>
                </div>
            <?php else: ?>
                <ul class="liste-actifs">
                    <?php foreach (Assets::ROLES as $cle => $label): ?>
                        <?php $src = Assets::src($actifs[$cle] ?? null); ?>
                        <?php if ($src !== null): ?>
                            <li>
                                <img src="<?= e($servirActif($src)) ?>" alt="<?= e($label) ?>" loading="lazy">
                                <span class="tiny"><?= e($label) ?></span>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php foreach ($photos as $photo): ?>
                        <?php
                        $src = Assets::src($photo);
                        if ($src === null) { continue; }
                        // Identifiant stable : le fichier local, ou l'empreinte
                        // de l'adresse pour une image simplement pointée.
                        $cleActif = Assets::cleDe($photo);
                        ?>
                        <li>
                            <img src="<?= e($servirActif($src)) ?>" alt="<?= e((string) ($photo['alt'] ?? '')) ?>" loading="lazy">
                            <span class="tiny muted">
                                <?= (int) $photo['largeur'] > 0
                                    ? (int) $photo['largeur'] . '×' . (int) $photo['hauteur'] . ' · ' . e((string) $photo['orientation'])
                                    : e((string) $photo['orientation']) ?>
                                <?php if (($photo['source'] ?? '') !== '' && $photo['source'] !== 'site'): ?>
                                    · <?= e((string) $photo['source']) ?>
                                <?php endif; ?>
                            </span>
                            <?php /* Les trois gestes possibles sur une image : la désigner comme logo,
                                     comme favicon, ou l'écarter. Ils n'apparaissent qu'au survol de la
                                     vignette — alignés en permanence, ils feraient lire le catalogue
                                     comme un écran de suppression. */ ?>
                            <div class="actifs__gestes">
                                <?php foreach (Assets::ROLES as $roleCle => $roleLabel): ?>
                                    <form method="post" action="<?= e(url('prospect_assets')) ?>">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="id" value="<?= e($id) ?>">
                                        <input type="hidden" name="action" value="promouvoir">
                                        <input type="hidden" name="role" value="<?= e($roleCle) ?>">
                                        <input type="hidden" name="fichier" value="<?= e($cleActif) ?>">
                                        <button class="btn small ghost" type="submit"
                                                title="Utiliser cette image comme <?= e(mb_strtolower($roleLabel)) ?>">
                                            <?= e($roleLabel) ?>
                                        </button>
                                    </form>
                                <?php endforeach; ?>
                                <form method="post" action="<?= e(url('prospect_assets')) ?>"
                                      data-confirm="Écarter cette photo du catalogue ? Elle ne reviendra pas, même après une nouvelle analyse.">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= e($id) ?>">
                                    <input type="hidden" name="action" value="retirer">
                                    <input type="hidden" name="fichier" value="<?= e($cleActif) ?>">
                                    <button class="btn danger small ghost" type="submit">Écarter</button>
                                </form>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <div class="divider"></div>

            <div class="field-row">
                <form method="post" action="<?= e(url('prospect_assets')) ?>" enctype="multipart/form-data" class="field">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= e($id) ?>">
                    <input type="hidden" name="action" value="ajouter_fichier">
                    <label for="photo_ajout">Déposer une photo</label>
                    <input type="file" name="photo" id="photo_ajout" accept="image/*" required>
                    <span class="hint muted tiny">
                        Copiée dans data/mockups. Elle survit aux analyses suivantes : c'est le recours
                        quand le site du prospect ne laisse rien récupérer.
                    </span>
                    <button class="btn small mt" type="submit">Ajouter cette photo</button>
                </form>

                <form method="post" action="<?= e(url('prospect_assets')) ?>" class="field">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= e($id) ?>">
                    <input type="hidden" name="action" value="ajouter_url">
                    <label for="photo_url">…ou coller l'adresse d'une image</label>
                    <input type="url" name="url" id="photo_url" class="mono"
                           placeholder="https://le-site-du-prospect.fr/photos/chantier.jpg">
                    <span class="hint muted tiny">
                        Rien n'est téléchargé : l'adresse est reprise telle quelle dans la maquette.
                        C'est le mode « liens » que vous avez demandé, image par image.
                    </span>
                    <button class="btn small mt" type="submit">Ajouter cette adresse</button>
                </form>
            </div>

            <?php if ($ecartees !== []): ?>
                <p class="tiny muted">
                    <?= count($ecartees) ?> image<?= count($ecartees) > 1 ? 's' : '' ?> écartée<?= count($ecartees) > 1 ? 's' : '' ?>
                    à la main : elle<?= count($ecartees) > 1 ? 's ne reviendront' : ' ne reviendra' ?> pas lors
                    d'une nouvelle analyse. Pour <?= count($ecartees) > 1 ? 'en récupérer une' : 'la récupérer' ?>,
                    collez son adresse ci-dessus.
                </p>
            <?php endif; ?>
        </div>

        <?php if ($consommation !== []): ?>
            <div class="card">
                <div class="card-head">
                    <h2>Consommation réelle</h2>
                    <?php
                    $totalGeneral = App\Consumption::sum(array_merge(
                        ...array_map(static fn (array $v): array => $v['lignes'], array_values($consommation))
                    ));
                    ?>
                    <span class="badge"><?= e(App\Consumption::money($totalGeneral['usd'])) ?></span>
                </div>
                <p class="muted small">
                    Chaque appel est chiffré au tarif du modèle qui a répondu, au moment où il répond.
                    Deux versions générées avec des modèles différents se comparent donc ligne à ligne.
                </p>

                <?php foreach ($consommation as $version => $bloc): ?>
                    <details class="conso"<?= $version === array_key_first($consommation) ? ' open' : '' ?>>
                        <summary>
                            <span class="conso__version">
                                <?= $version === 'comparaison' ? 'Comparaisons de modèles' : e($version) ?>
                                <?= $version === (string) ($p['mockup']['current'] ?? '')
                                    ? '<span class="badge brand">en ligne</span>' : '' ?>
                            </span>
                            <span class="conso__chiffres tiny">
                                <span class="muted"><?= e(App\Consumption::tokens($bloc['in'])) ?> ↓
                                    <?= e(App\Consumption::tokens($bloc['out'])) ?> ↑</span>
                                <strong><?= e(App\Consumption::money($bloc['usd'])) ?></strong>
                            </span>
                        </summary>
                        <ul class="conso__lignes">
                            <?php foreach ($bloc['lignes'] as $ligne): ?>
                                <li>
                                    <span class="conso__etape"><?= e((string) ($ligne['etape'] ?: '—')) ?></span>
                                    <span class="conso__jetons tiny muted">
                                        <?= e(App\Consumption::tokens((int) $ligne['in'])) ?> ↓
                                        <?= e(App\Consumption::tokens((int) $ligne['out'])) ?> ↑
                                    </span>
                                    <span class="conso__modele tiny muted mono"><?= e((string) $ligne['model']) ?></span>
                                    <span class="conso__cout">
                                        <?= $ligne['usd'] === null
                                            ? '<span class="faint tiny">tarif non relevé</span>'
                                            : e(App\Consumption::money((float) $ligne['usd'])) ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if ($bloc['sans_tarif'] > 0): ?>
                            <p class="tiny muted">
                                <?= (int) $bloc['sans_tarif'] ?> appel(s) sans tarif relevé : ce total est un minimum.
                            </p>
                        <?php endif; ?>
                    </details>
                <?php endforeach; ?>

                <?php if (App\Consumption::eurRate() === null): ?>
                    <p class="tiny muted mt">
                        Les montants sont en dollars, comme les factures des API.
                        <a href="<?= e(url('settings')) ?>#claude">Renseignez votre taux de conversion</a>
                        pour voir aussi les euros.
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($p['enrichment']['sources'])): ?>
            <div class="card">
                <div class="card-head"><h2>Enrichissement</h2></div>
                <ul class="timeline">
                    <?php foreach ($p['enrichment']['sources'] as $source): ?>
                        <li class="small"><?= e((string) $source) ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php if (Config::get('enrichment.mode') === 'site_company' || Config::get('enrichment.pappers_api_key') !== ''): ?>
                    <form method="post" action="<?= e(url('prospect_enrich')) ?>" class="mt">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="id" value="<?= e($id) ?>">
                        <button class="btn small" type="submit">Interroger la base entreprise</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-head"><h2>Historique</h2></div>
            <?php if ($timeline === []): ?>
                <p class="muted small">Aucun événement.</p>
            <?php else: ?>
                <ul class="timeline">
                    <?php foreach ($timeline as $event): ?>
                        <li>
                            <div>
                                <?= e(Events::label((string) $event['type'])) ?>
                                <?php if (!empty($event['meta']['message'])): ?>
                                    <div class="tiny muted"><?= e(Util::truncate((string) $event['meta']['message'], 110)) ?></div>
                                <?php endif; ?>
                            </div>
                            <span class="when"><?= e(ago((int) $event['ts'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="card tight">
            <form method="post" action="<?= e(url('prospect_delete')) ?>"
                  data-confirm="Supprimer définitivement ce prospect et toutes ses maquettes ?">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= e($id) ?>">
                <button class="btn danger small" type="submit">Supprimer ce prospect</button>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>
