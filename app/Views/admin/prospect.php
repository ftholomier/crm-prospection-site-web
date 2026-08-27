<?php
/**
 * @var array $p @var array $versions @var string $currentVersion @var array $timeline
 * @var array $sends @var array $schedule @var string $mockupUrl @var bool $hasShot @var string $shotUrl
 */
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
                        <a href="#saisie-manuelle">dans le champ juste en dessous</a> : le résultat est identique.</p>
                </div>
            </div>
        <?php endif; ?>

        <div class="card" id="saisie-manuelle">
            <div class="card-head">
                <h2>Le site bloque l'analyse ?</h2>
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
                prestations — et en rapporte le contenu. Consomme des crédits API, environ le tiers d'une maquette.
            </p>
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
            <form method="post" action="<?= e(url('prospect_manual')) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= e($id) ?>">
                <div class="field">
                    <label for="html_accueil">Page d'accueil <span class="muted">— indispensable</span></label>
                    <textarea class="code" name="html_accueil" id="html_accueil" rows="6"
                              placeholder="&lt;!DOCTYPE html&gt;&#10;&lt;html lang=&quot;fr&quot;&gt;…"></textarea>
                </div>
                <?php foreach ([
                    'contact' => ['Page contact', 'Porte le plus souvent l\'email et le téléphone.'],
                    'legal' => ['Mentions légales', 'Porte la raison sociale exacte et le SIREN.'],
                    'services' => ['Page prestations', 'Alimente la page Prestations de la maquette.'],
                ] as $role => [$titre, $aide]): ?>
                    <div class="field">
                        <label for="html_<?= e($role) ?>"><?= e($titre) ?> <span class="muted">— facultatif</span></label>
                        <textarea class="code" name="html_<?= e($role) ?>" id="html_<?= e($role) ?>" rows="3"></textarea>
                        <span class="hint muted tiny"><?= e($aide) ?></span>
                    </div>
                <?php endforeach; ?>
                <button class="btn <?= $hasAnalysis ? '' : 'primary' ?>" type="submit">
                    <?= $hasManualSource ? 'Remplacer les pages collées' : 'Analyser ces pages' ?>
                </button>
                <p class="tiny muted mt">Les feuilles de style externes restent hors de portée : les couleurs sont déduites du code collé.</p>
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
