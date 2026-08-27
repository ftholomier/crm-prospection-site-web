<?php
/** @var array $config @var array $modes @var array $providers @var array $health @var string $cronUrl */
use App\Config;
use App\Cron;
use App\Csrf;
use App\Sequence;

require __DIR__ . '/../partials/header.php';

$days = [1 => 'Lun', 2 => 'Mar', 3 => 'Mer', 4 => 'Jeu', 5 => 'Ven', 6 => 'Sam', 7 => 'Dim'];
$secretPlaceholder = static fn (string $value): string => $value !== '' ? 'Valeur enregistrée — laissez vide pour la conserver' : '';
?>

<div class="page-head">
    <div>
        <h1>Réglages</h1>
        <div class="sub">Tout est stocké dans <code>data/config.json</code>. Les secrets ne sont jamais réaffichés.</div>
    </div>
</div>

<div class="card tight">
    <div class="row">
        <?php foreach ($health as $check): ?>
            <span class="badge <?= $check['ok'] ? 'ok' : 'warn' ?>" title="<?= e($check['hint']) ?>">
                <?= $check['ok'] ? '✓' : '!' ?> <?= e($check['label']) ?>
            </span>
        <?php endforeach; ?>
    </div>
</div>

<form method="post" action="<?= e(url('settings_save')) ?>" enctype="multipart/form-data">
    <?= Csrf::field() ?>

    <div class="card" id="general">
        <div class="card-head"><h2>Général</h2></div>
        <div class="field-row">
            <div class="field">
                <label for="app_name">Nom de l'application</label>
                <input type="text" name="app_name" id="app_name" value="<?= e((string) $config['app']['name']) ?>">
            </div>
            <div class="field">
                <label for="timezone">Fuseau horaire</label>
                <input type="text" name="timezone" id="timezone" value="<?= e((string) $config['app']['timezone']) ?>">
            </div>
        </div>
        <div class="field">
            <label for="base_url">URL publique de l'application</label>
            <input type="url" name="base_url" id="base_url" value="<?= e((string) $config['app']['base_url']) ?>" placeholder="https://prospection.mondomaine.fr">
            <span class="hint muted tiny">Sert à construire les liens envoyés aux prospects. Sans elle, l'URL est devinée depuis la requête, ce qui peut échouer derrière un proxy.</span>
        </div>
        <label class="check">
            <input type="checkbox" name="pretty_urls" value="1" <?= !empty($config['app']['pretty_urls']) ? 'checked' : '' ?>>
            <span>URLs propres pour les liens prospects (<code>/m/jeton/accueil</code>)
                <span class="hint">Décochez si votre hébergement ne gère pas la réécriture d'URL : les liens basculent alors sur des paramètres.</span></span>
        </label>
        <div class="field">
            <label for="signature">Signature des emails</label>
            <textarea name="signature" id="signature" rows="4" placeholder="Frédéric&#10;06 12 34 56 78"><?= e((string) $config['app']['signature']) ?></textarea>
        </div>
    </div>

    <div class="card" id="claude">
        <div class="card-head">
            <h2>Génération des maquettes</h2>
            <div class="actions"><span class="badge <?= $config['claude']['api_key'] !== '' ? 'ok' : 'warn' ?>">API Claude</span></div>
        </div>
        <div class="field">
            <label for="claude_api_key">Clé API</label>
            <input type="password" name="claude_api_key" id="claude_api_key" autocomplete="off" placeholder="<?= e($secretPlaceholder((string) $config['claude']['api_key'])) ?>">
            <span class="hint muted tiny">À créer sur console.anthropic.com. La clé est stockée dans data/config.json, hors de la racine web.</span>
        </div>
        <?php
        $currentModel = (string) $config['claude']['model'];
        $known = array_column($models, 'id');
        $isCustom = !in_array($currentModel, $known, true);
        ?>
        <div class="field">
            <label for="claude_model">
                Modèle
                <span class="hint">Classés du moins cher au plus cher, sur la base du coût estimé d'une maquette complète.</span>
            </label>
            <select name="claude_model" id="claude_model" onchange="document.getElementById('custom-model').hidden = this.value !== '__custom__';">
                <?php foreach ($models as $model): ?>
                    <option value="<?= e($model['id']) ?>" <?= $currentModel === $model['id'] ? 'selected' : '' ?>>
                        <?= e($model['name']) ?>
                        — <?= $model['cost'] === null ? 'tarif inconnu' : e(App\Models::formatCost($model['cost'])) . ' / maquette' ?>
                        <?php if ($model['input'] !== null): ?>
                            (<?= e(rtrim(rtrim(number_format((float) $model['input'], 2, ',', ' '), '0'), ',')) ?> $
                            / <?= e(rtrim(rtrim(number_format((float) $model['output'], 2, ',', ' '), '0'), ',')) ?> $ par million de tokens)
                        <?php endif; ?>
                        <?= (!$model['live'] && $modelsFetchedAt > 0) ? ' — absent de votre catalogue API' : '' ?>
                    </option>
                <?php endforeach; ?>
                <option value="__custom__" <?= $isCustom ? 'selected' : '' ?>>Autre — saisir un identifiant</option>
            </select>
        </div>

        <div class="field" id="custom-model" <?= $isCustom ? '' : 'hidden' ?>>
            <label for="claude_model_custom">Identifiant du modèle</label>
            <input type="text" name="claude_model_custom" id="claude_model_custom"
                   value="<?= $isCustom ? e($currentModel) : '' ?>" placeholder="claude-opus-5">
            <span class="hint muted tiny">Un modèle absent de la liste est présumé de génération courante : réflexion adaptative et niveaux d'effort activés.</span>
        </div>

        <div class="table-wrap mb">
            <table>
                <thead>
                <tr>
                    <th>Modèle</th>
                    <th class="right">Entrée</th>
                    <th class="right">Sortie</th>
                    <th class="right">Coût / maquette</th>
                    <th>Capacités</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($models as $model): ?>
                    <tr<?= $currentModel === $model['id'] ? ' style="background:var(--brand-soft)"' : '' ?>>
                        <td data-label="Modèle">
                            <strong><?= e($model['name']) ?></strong>
                            <?php if ($currentModel === $model['id']): ?><span class="badge brand">Actif</span><?php endif; ?>
                            <div class="tiny muted mono"><?= e($model['id']) ?></div>
                        </td>
                        <td class="right nowrap" data-label="Entrée"><?= $model['input'] === null ? '<span class="faint">—</span>' : e(number_format((float) $model['input'], 2, ',', ' ')) . ' $' ?></td>
                        <td class="right nowrap" data-label="Sortie"><?= $model['output'] === null ? '<span class="faint">—</span>' : e(number_format((float) $model['output'], 2, ',', ' ')) . ' $' ?></td>
                        <td class="right nowrap strong" data-label="Coût / maquette"><?= e(App\Models::formatCost($model['cost'])) ?></td>
                        <td class="tiny muted" data-label="Capacités">
                            <?= !empty($model['adaptive']) ? 'réflexion adaptative' : 'sans réflexion adaptative' ?><?php
                            ?><?= $model['efforts'] === [] ? ', sans niveau d\'effort' : ', effort ' . e(implode('/', $model['efforts'])) ?><?php
                            ?><?= empty($model['structured']) ? ', sans sortie structurée' : '' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="tiny muted">
            La liste et les capacités proviennent de l'API
            <?= $modelsFetchedAt > 0 ? '(relevé ' . e(ago($modelsFetchedAt)) . ')' : '(pas encore interrogée)' ?>,
            et se rafraîchissent seules une fois par jour.
            Les tarifs ne sont pas exposés par l'API : ils viennent de la grille publique relevée le
            <?= e(date('d/m/Y', strtotime(App\Models::PRICING_DATE))) ?>, à vérifier sur
            <a href="<?= e(App\Models::PRICING_SOURCE) ?>" target="_blank" rel="noopener noreferrer">la page tarifs</a>.
            <?php if (!empty($profile['measured'])): ?>
                Le coût par maquette est calculé sur votre consommation réelle
                (<?= number_format((int) $profile['input'], 0, ',', ' ') ?> tokens en entrée et
                <?= number_format((int) $profile['output'], 0, ',', ' ') ?> en sortie en moyenne,
                sur <?= (int) $profile['samples'] ?> maquette(s)).
            <?php else: ?>
                Tant qu'aucune maquette n'a été générée, le coût est estimé sur un profil de référence
                (<?= number_format((int) $profile['input'], 0, ',', ' ') ?> tokens en entrée,
                <?= number_format((int) $profile['output'], 0, ',', ' ') ?> en sortie) ;
                il s'ajustera ensuite à votre consommation réelle.
            <?php endif; ?>
            <?php if ($spent !== null): ?>
                Dépense cumulée estimée depuis la mise en service : <strong><?= e(App\Models::formatCost($spent)) ?></strong>.
            <?php endif; ?>
        </p>

        <div class="field-row">
            <div class="field">
                <label for="claude_effort">Niveau d'effort</label>
                <select name="claude_effort" id="claude_effort">
                    <?php foreach (['low' => 'Faible — rapide et économique', 'medium' => 'Moyen', 'high' => 'Élevé — recommandé', 'xhigh' => 'Très élevé', 'max' => 'Maximum — le plus soigné'] as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $config['claude']['effort'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="hint muted tiny">Ramené automatiquement au niveau le plus proche si le modèle choisi ne le propose pas.</span>
            </div>
            <div class="field">
                <label for="claude_max_tokens">Tokens maximum par page</label>
                <input type="number" name="claude_max_tokens" id="claude_max_tokens" min="4000" max="64000" step="1000" value="<?= (int) $config['claude']['max_tokens'] ?>">
            </div>
        </div>
        <div class="field">
            <label for="design_prompt">Prompt global de design</label>
            <textarea class="code" name="design_prompt" id="design_prompt" rows="18"><?= e((string) $config['design']['global_prompt']) ?></textarea>
            <span class="hint muted tiny">Ces consignes s'appliquent à toutes les maquettes. Chaque fiche prospect peut y ajouter des consignes spécifiques.</span>
        </div>
        <label class="check">
            <input type="checkbox" name="allow_google_fonts" value="1" <?= !empty($config['design']['allow_google_fonts']) ? 'checked' : '' ?>>
            <span>Autoriser les polices Google Fonts <span class="hint">Améliore nettement le rendu typographique.</span></span>
        </label>
        <label class="check">
            <input type="checkbox" name="use_site_images" value="1" <?= !empty($config['design']['use_site_images']) ? 'checked' : '' ?>>
            <span>Réutiliser les photos du site d'origine <span class="hint">La maquette ressemble davantage à « leur » site. À décocher si les sites ciblés bloquent les images externes.</span></span>
        </label>
    </div>

    <div class="card" id="offer">
        <div class="card-head"><h2>Offre commerciale</h2></div>
        <div class="field-row">
            <div class="field">
                <label for="monthly_price">Tarif mensuel par défaut</label>
                <input type="number" step="0.01" min="0" name="monthly_price" id="monthly_price" value="<?= e((string) $config['offer']['monthly_price']) ?>">
                <span class="hint muted tiny">Modifiable prospect par prospect depuis sa fiche.</span>
            </div>
            <div class="field">
                <label for="currency">Symbole monétaire</label>
                <input type="text" name="currency" id="currency" value="<?= e((string) $config['offer']['currency']) ?>">
            </div>
        </div>
        <div class="field">
            <label for="included">Ce qui est inclus <span class="muted">— une ligne par élément</span></label>
            <textarea name="included" id="included" rows="6"><?= e(implode("\n", (array) $config['offer']['included'])) ?></textarea>
            <span class="hint muted tiny">Cette liste alimente la variable <code>{{inclus_liste}}</code> des emails.</span>
        </div>
    </div>

    <div class="card" id="smtp">
        <div class="card-head"><h2>Envoi des emails (SMTP)</h2></div>
        <div class="field-row three">
            <div class="field">
                <label for="smtp_host">Serveur</label>
                <input type="text" name="smtp_host" id="smtp_host" value="<?= e((string) $config['smtp']['host']) ?>" placeholder="ssl0.ovh.net">
            </div>
            <div class="field">
                <label for="smtp_port">Port</label>
                <input type="number" name="smtp_port" id="smtp_port" value="<?= (int) $config['smtp']['port'] ?>">
            </div>
            <div class="field">
                <label for="smtp_security">Chiffrement</label>
                <select name="smtp_security" id="smtp_security">
                    <option value="tls" <?= $config['smtp']['security'] === 'tls' ? 'selected' : '' ?>>STARTTLS (port 587)</option>
                    <option value="ssl" <?= $config['smtp']['security'] === 'ssl' ? 'selected' : '' ?>>SSL/TLS (port 465)</option>
                    <option value="none" <?= $config['smtp']['security'] === 'none' ? 'selected' : '' ?>>Aucun (port 25)</option>
                </select>
            </div>
        </div>
        <div class="field-row">
            <div class="field">
                <label for="smtp_user">Identifiant</label>
                <input type="text" name="smtp_user" id="smtp_user" value="<?= e((string) $config['smtp']['user']) ?>" autocomplete="off">
            </div>
            <div class="field">
                <label for="smtp_pass">Mot de passe</label>
                <input type="password" name="smtp_pass" id="smtp_pass" autocomplete="new-password" placeholder="<?= e($secretPlaceholder((string) $config['smtp']['pass'])) ?>">
            </div>
        </div>
        <div class="field-row three">
            <div class="field">
                <label for="from_email">Adresse d'expédition</label>
                <input type="email" name="from_email" id="from_email" value="<?= e((string) $config['smtp']['from_email']) ?>">
            </div>
            <div class="field">
                <label for="from_name">Nom d'expéditeur</label>
                <input type="text" name="from_name" id="from_name" value="<?= e((string) $config['smtp']['from_name']) ?>">
            </div>
            <div class="field">
                <label for="reply_to">Adresse de réponse</label>
                <input type="email" name="reply_to" id="reply_to" value="<?= e((string) $config['smtp']['reply_to']) ?>">
            </div>
        </div>
        <label class="check">
            <input type="checkbox" name="verify_peer" value="1" <?= !empty($config['smtp']['verify_peer']) ? 'checked' : '' ?>>
            <span>Vérifier le certificat du serveur <span class="hint">À ne décocher que si votre hébergeur utilise un certificat auto-signé.</span></span>
        </label>
        <p class="tiny muted">Pour la délivrabilité, publiez les enregistrements SPF, DKIM et DMARC du domaine expéditeur : sans eux, la prospection à froid finit en indésirables.</p>
    </div>

    <div class="card" id="sequence">
        <div class="card-head"><h2>Séquence automatique</h2></div>
        <label class="check">
            <input type="checkbox" name="sequence_enabled" value="1" <?= !empty($config['sequence']['enabled']) ? 'checked' : '' ?>>
            <span>Le cron fait avancer les séquences automatiquement</span>
        </label>
        <div class="field-row three">
            <?php foreach ([1, 2, 3] as $step): ?>
                <div class="field">
                    <label for="delay_<?= $step ?>">Délai avant l'email <?= $step ?></label>
                    <input type="number" min="0" max="90" name="delay_<?= $step ?>" id="delay_<?= $step ?>" value="<?= (int) ($config['sequence']['delays_days'][$step - 1] ?? 0) ?>">
                    <span class="hint muted tiny">
                        <?= $step === 1 ? 'Jours après le lancement de la séquence.' : 'Jours après l\'email ' . ($step - 1) . '.' ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="field">
            <label>Jours d'envoi</label>
            <div class="row">
                <?php foreach ($days as $number => $label): ?>
                    <label class="check" style="margin:0">
                        <input type="checkbox" name="send_days[]" value="<?= $number ?>" <?= in_array($number, (array) $config['sequence']['send_days'], true) ? 'checked' : '' ?>>
                        <span><?= e($label) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="field-row three">
            <div class="field">
                <label for="send_from">Début de la fenêtre</label>
                <input type="time" name="send_from" id="send_from" value="<?= e((string) $config['sequence']['send_from']) ?>">
            </div>
            <div class="field">
                <label for="send_to">Fin de la fenêtre</label>
                <input type="time" name="send_to" id="send_to" value="<?= e((string) $config['sequence']['send_to']) ?>">
            </div>
            <div class="field">
                <label for="daily_limit">Plafond quotidien</label>
                <input type="number" min="1" max="500" name="daily_limit" id="daily_limit" value="<?= (int) $config['sequence']['daily_limit'] ?>">
            </div>
        </div>
        <div class="field">
            <label for="min_gap">Espacement minimal entre deux envois (secondes)</label>
            <input type="number" min="0" max="3600" name="min_gap" id="min_gap" value="<?= (int) $config['sequence']['min_gap_seconds'] ?>">
            <span class="hint muted tiny">Un envoi en rafale déclenche les filtres anti-spam. 120 secondes est un bon compromis.</span>
        </div>
        <label class="check">
            <input type="checkbox" name="stop_on_click" value="1" <?= !empty($config['sequence']['stop_on_click']) ? 'checked' : '' ?>>
            <span>Arrêter la séquence dès que le prospect clique sur le lien de la maquette</span>
        </label>
        <label class="check">
            <input type="checkbox" name="stop_on_view" value="1" <?= !empty($config['sequence']['stop_on_view']) ? 'checked' : '' ?>>
            <span>Arrêter aussi à la simple consultation de la maquette</span>
        </label>
    </div>

    <div class="card" id="batch">
        <div class="card-head"><h2>Traitement par lot et capture</h2></div>
        <label class="check">
            <input type="checkbox" name="auto_analyze" value="1" <?= !empty($config['batch']['auto_analyze']) ? 'checked' : '' ?>>
            <span>Analyser automatiquement les prospects importés</span>
        </label>
        <label class="check">
            <input type="checkbox" name="auto_generate" value="1" <?= !empty($config['batch']['auto_generate']) ? 'checked' : '' ?>>
            <span>Générer aussi les maquettes automatiquement
                <span class="hint">Chaque maquette consomme des crédits API. À réserver au cron en ligne de commande : une génération complète dépasse souvent la limite de temps d'un cron par URL.</span></span>
        </label>
        <div class="field-row">
            <div class="field">
                <label for="batch_per_run">Prospects traités par passage du cron</label>
                <input type="number" min="1" max="20" name="batch_per_run" id="batch_per_run" value="<?= (int) $config['batch']['per_run'] ?>">
            </div>
            <div class="field">
                <label for="enrich_mode">Mode d'enrichissement</label>
                <select name="enrich_mode" id="enrich_mode">
                    <?php foreach ($modes as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $config['enrichment']['mode'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="hint muted tiny">L'email vient toujours du site : une base entreprise ne le connaît pas. Elle sert à compléter dirigeant, SIREN et activité.</span>
            </div>
        </div>
        <div class="field">
            <label for="pappers_key">Clé API base entreprise (Pappers)</label>
            <input type="password" name="pappers_key" id="pappers_key" autocomplete="off" placeholder="<?= e($secretPlaceholder((string) $config['enrichment']['pappers_api_key'])) ?>">
        </div>

        <div class="divider"></div>

        <div class="field">
            <label for="user_agent">Identité annoncée lors de la lecture des sites</label>
            <input type="text" name="user_agent" id="user_agent" value="<?= e((string) ($config['scraper']['user_agent'] ?? '')) ?>"
                   placeholder="<?= e(App\Http::AGENTS[0]) ?>">
            <span class="hint muted tiny">Laissez vide pour l'identité de navigateur par défaut. Un agent qui s'annonce comme robot est refusé par une bonne partie des pare-feux, même sur une page d'accueil publique.</span>
        </div>
        <label class="check">
            <input type="checkbox" name="retry_blocked" value="1" <?= !empty($config['scraper']['retry_blocked']) ? 'checked' : '' ?>>
            <span>Réessayer autrement quand un site refuse la lecture
                <span class="hint">Trois variantes sont tentées : autre identité de navigateur, domaine avec ou sans www, connexion non sécurisée.</span></span>
        </label>

        <div class="divider"></div>

        <div class="field-row">
            <div class="field">
                <label for="shot_provider">Service de capture d'écran</label>
                <select name="shot_provider" id="shot_provider">
                    <?php foreach ($providers as $value => $provider): ?>
                        <option value="<?= e($value) ?>" <?= $config['screenshot']['provider'] === $value ? 'selected' : '' ?>><?= e($provider['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="hint muted tiny">Un hébergement mutualisé n'a pas de navigateur : la capture passe par un service externe ou par un import manuel.</span>
            </div>
            <div class="field">
                <label for="shot_key">Clé du service de capture</label>
                <input type="password" name="shot_key" id="shot_key" autocomplete="off" placeholder="<?= e($secretPlaceholder((string) $config['screenshot']['api_key'])) ?>">
            </div>
        </div>
        <div class="field">
            <label for="shot_custom">Modèle d'URL personnalisé</label>
            <input type="text" name="shot_custom" id="shot_custom" value="<?= e((string) $config['screenshot']['custom_template']) ?>" placeholder="https://mon-service/capture?url={enc}&amp;key={key}">
            <span class="hint muted tiny">Substitutions disponibles : <code>{url}</code>, <code>{enc}</code> (encodée) et <code>{key}</code>.</span>
        </div>
        <label class="check">
            <input type="checkbox" name="shot_auto" value="1" <?= !empty($config['screenshot']['auto']) ? 'checked' : '' ?>>
            <span>Capturer automatiquement pendant l'analyse</span>
        </label>
        <label class="check">
            <input type="checkbox" name="shot_to_model" value="1" <?= !empty($config['screenshot']['send_to_model']) ? 'checked' : '' ?>>
            <span>Envoyer la capture au modèle <span class="hint">Il voit alors réellement le site avant de le refondre, au lieu de deviner à partir du code.</span></span>
        </label>
    </div>

    <div class="card" id="about">
        <div class="card-head">
            <h2>Qui suis-je</h2>
            <div class="actions">
                <?php if (empty($config['about']['enabled'])): ?><span class="badge warn">Masquée</span><?php endif; ?>
            </div>
        </div>
        <p class="small muted">
            Cette section s'affiche sur la page de proposition, sous l'offre. Sur un message de
            prospection à froid, c'est la seule preuve qu'il y a quelqu'un derrière la maquette.
        </p>

        <label class="check">
            <input type="checkbox" name="about_enabled" value="1" <?= !empty($config['about']['enabled']) ? 'checked' : '' ?>>
            <span>Afficher cette section aux prospects</span>
        </label>

        <div class="field-row">
            <div class="field">
                <label for="about_name">Nom affiché</label>
                <input type="text" name="about_name" id="about_name" value="<?= e((string) $config['about']['name']) ?>">
            </div>
            <div class="field">
                <label for="about_role">Sous-titre</label>
                <input type="text" name="about_role" id="about_role" value="<?= e((string) $config['about']['role']) ?>">
            </div>
        </div>

        <div class="field">
            <label for="about_title">Titre de la section</label>
            <input type="text" name="about_title" id="about_title" value="<?= e((string) $config['about']['title']) ?>">
        </div>

        <div class="field">
            <label for="about_bio">Présentation <span class="muted">— une ligne vide sépare deux paragraphes</span></label>
            <textarea name="about_bio" id="about_bio" rows="7"><?= e((string) $config['about']['bio']) ?></textarea>
            <span class="hint muted tiny">Écrit à la première personne. Ce texte est lu par vos prospects : relisez-le, il vous engage.</span>
        </div>

        <div class="field">
            <label for="about_points">Points de réassurance <span class="muted">— un par ligne</span></label>
            <textarea name="about_points" id="about_points" rows="4"><?= e(implode("\n", (array) $config['about']['points'])) ?></textarea>
            <span class="hint muted tiny">Trois lignes courtes suffisent. Vérifiez que chacune est exacte : elles sont affichées comme des engagements.</span>
        </div>

        <div class="field-row">
            <div class="field">
                <label for="about_site_url">Adresse de votre site</label>
                <input type="url" name="about_site_url" id="about_site_url" value="<?= e((string) $config['about']['site_url']) ?>" placeholder="https://mondomaine.fr">
            </div>
            <div class="field">
                <label for="about_site_label">Libellé du lien</label>
                <input type="text" name="about_site_label" id="about_site_label" value="<?= e((string) $config['about']['site_label']) ?>" placeholder="mondomaine.fr">
            </div>
        </div>

        <div class="field">
            <label for="portrait">Photo</label>
            <div class="row">
                <?php if ($hasPortrait): ?>
                    <img src="<?= e($portraitUrl) ?>" alt="Portrait actuel"
                         style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:1px solid var(--line)">
                <?php endif; ?>
                <input type="file" name="portrait" id="portrait" accept="image/png,image/jpeg,image/webp" style="width:auto;flex:1;min-width:200px">
            </div>
            <span class="hint muted tiny">Facultative. Réduite automatiquement à 800 pixels. Sans photo, vos initiales sont affichées.</span>
            <?php if ($hasPortrait): ?>
                <label class="check mt">
                    <input type="checkbox" name="remove_portrait" value="1">
                    <span>Retirer la photo actuelle</span>
                </label>
            <?php endif; ?>
        </div>
    </div>

    <div class="card" id="alerts">
        <div class="card-head"><h2>Alertes</h2></div>
        <div class="field">
            <label for="alert_email">Adresse à prévenir</label>
            <input type="email" name="alert_email" id="alert_email" value="<?= e((string) $config['alerts']['email']) ?>">
        </div>
        <label class="check">
            <input type="checkbox" name="alert_interest" value="1" <?= !empty($config['alerts']['on_interest']) ? 'checked' : '' ?>>
            <span>Me prévenir dès qu'un prospect se déclare intéressé</span>
        </label>
        <label class="check">
            <input type="checkbox" name="alert_view" value="1" <?= !empty($config['alerts']['on_view']) ? 'checked' : '' ?>>
            <span>Me prévenir à chaque consultation de maquette <span class="hint">Peut devenir bruyant sur un gros volume.</span></span>
        </label>
    </div>

    <div class="card" id="password">
        <div class="card-head"><h2>Accès au back-office</h2></div>
        <div class="field">
            <label for="login_email">Identifiant de connexion</label>
            <input type="email" name="login_email" id="login_email" value="<?= e(App\Auth::identifier()) ?>" autocomplete="username">
            <span class="hint muted tiny">C'est cette adresse qui vous connecte, et celle qui reçoit les liens de réinitialisation. Elle est indépendante de l'adresse d'expédition des emails de prospection.</span>
        </div>
        <div class="field-row">
            <div class="field">
                <label for="current_password">Mot de passe actuel</label>
                <input type="password" name="current_password" id="current_password" autocomplete="current-password">
            </div>
            <div class="field">
                <label for="new_password">Nouveau mot de passe</label>
                <input type="password" name="new_password" id="new_password" autocomplete="new-password">
                <span class="hint muted tiny">8 caractères minimum. Laissez vide pour ne rien changer.</span>
            </div>
        </div>
        <p class="tiny muted">Changer le mot de passe ferme toutes les sessions ouvertes sur vos autres appareils.</p>
    </div>

    <div class="card">
        <button class="btn primary" type="submit">Enregistrer tous les réglages</button>
    </div>
</form>

<div class="grid cols-2">
    <div class="card">
        <div class="card-head"><h2>Tester la configuration</h2></div>
        <div class="row mb">
            <form method="post" action="<?= e(url('test_claude')) ?>">
                <?= Csrf::field() ?>
                <button class="btn" type="submit">Tester la clé API Claude</button>
            </form>
            <form method="post" action="<?= e(url('models_refresh')) ?>">
                <?= Csrf::field() ?>
                <button class="btn" type="submit">Recharger la liste des modèles</button>
            </form>
        </div>
        <form method="post" action="<?= e(url('test_smtp')) ?>" class="stack">
            <?= Csrf::field() ?>
            <div class="field">
                <label for="test_email">Envoyer un email de test à</label>
                <input type="email" name="test_email" id="test_email" placeholder="Laissez vide pour tester seulement la connexion">
            </div>
            <div><button class="btn" type="submit">Tester le SMTP</button></div>
        </form>
    </div>

    <div class="card" id="cron">
        <div class="card-head"><h2>Tâche planifiée</h2></div>
        <p class="small">Le traitement doit tourner régulièrement — toutes les 15 minutes convient — pour analyser les imports et faire avancer les séquences.</p>
        <h3>En ligne de commande (recommandé)</h3>
        <pre class="console" style="max-height:none">0,15,30,45 * * * * /usr/bin/php <?= e(APP_ROOT) ?>/bin/cron.php</pre>
        <h3 class="mt">Par URL, si votre hébergeur ne propose que cela</h3>
        <div class="row">
            <input type="text" class="mono" readonly value="<?= e($cronUrl) ?>" onclick="this.select()">
            <button class="btn small" type="button" data-copy="<?= e($cronUrl) ?>">Copier</button>
        </div>
        <p class="tiny muted mt">Cette adresse contient une clé secrète : ne la diffusez pas.</p>

        <?php $runs = Cron::lastRuns(3); ?>
        <?php if ($runs !== []): ?>
            <div class="divider"></div>
            <h3>Derniers passages</h3>
            <?php foreach ($runs as $run): ?>
                <details class="small">
                    <summary><?= e(dt((int) $run['ts'])) ?></summary>
                    <pre class="console mt"><?= e(implode("\n", (array) $run['lines'])) ?></pre>
                </details>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>
