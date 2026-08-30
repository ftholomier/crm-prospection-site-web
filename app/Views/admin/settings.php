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

    <?php $fournisseur = App\Ai::normalize((string) ($config['ai']['provider'] ?? 'claude')); ?>
    <div class="card" id="claude">
        <div class="card-head">
            <h2>Génération des maquettes</h2>
            <div class="actions">
                <span class="badge <?= App\Ai::isConfigured() ? 'ok' : 'warn' ?>"><?= e(App\Ai::label($fournisseur)) ?></span>
            </div>
        </div>

        <div class="field">
            <label for="ai_provider">Fournisseur</label>
            <select name="ai_provider" id="ai_provider" data-fournisseur>
                <?php foreach (App\Ai::FOURNISSEURS as $cle => $nomFournisseur): ?>
                    <option value="<?= e($cle) ?>" <?= $fournisseur === $cle ? 'selected' : '' ?>><?= e($nomFournisseur) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="hint muted tiny">
                DeepSeek et Gemini coûtent nettement moins cher : la page la moins chère se génère
                aujourd'hui chez Gemini, avec <span class="mono">gemini-2.5-flash-lite</span>.
                Une seule fonction n'existe que chez Anthropic et se désactive si vous choisissez un autre
                fournisseur : <strong>la lecture d'un site bloqué par l'IA</strong>, qui repose sur un outil
                exécuté chez eux. La capture du site, elle, est lue par tous les modèles Claude et Gemini,
                mais côté DeepSeek par le seul <span class="mono">deepseek-v4-flash-vision-exp</span>.
                Le reste — brief, pages, retouches — fonctionne à l'identique.
            </span>
        </div>

        <div data-bloc-fournisseur="claude"<?= $fournisseur === 'claude' ? '' : ' hidden' ?>>
        <div class="field">
            <label for="claude_api_key">Clé API Claude</label>
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
                <?php $releve = App\Consumption::sum(App\Consumption::lines()); ?>
                Dépense réelle depuis la mise en service :
                <strong><?= e(App\Consumption::money($spent)) ?></strong>
                sur <?= (int) $releve['appels'] ?> appel(s), chacun chiffré au tarif du modèle qui a répondu.
                <?php if ($releve['sans_tarif'] > 0): ?>
                    <?= (int) $releve['sans_tarif'] ?> appel(s) ne sont pas chiffrés, faute de tarif relevé
                    pour leur modèle : le total affiché est donc un minimum.
                <?php endif; ?>
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
        </div><!-- /bloc claude -->

        <div data-bloc-fournisseur="deepseek"<?= $fournisseur === 'deepseek' ? '' : ' hidden' ?>>
            <div class="field">
                <label for="deepseek_api_key">Clé API DeepSeek</label>
                <input type="password" name="deepseek_api_key" id="deepseek_api_key" autocomplete="off"
                       placeholder="<?= e($secretPlaceholder((string) ($config['deepseek']['api_key'] ?? ''))) ?>">
                <span class="hint muted tiny">
                    À créer sur platform.deepseek.com. Stockée dans data/config.json, hors de la racine web.
                </span>
            </div>
            <div class="field-row">
                <div class="field">
                    <label for="deepseek_model">Modèle</label>
                    <?php $modelesDs = App\DeepSeek::catalog(); ?>
                    <?php if ($modelesDs !== []): ?>
                        <select name="deepseek_model" id="deepseek_model">
                            <?php foreach ($modelesDs as $modele): ?>
                                <option value="<?= e($modele['id']) ?>" <?= ($config['deepseek']['model'] ?? '') === $modele['id'] ? 'selected' : '' ?>>
                                    <?= e($modele['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="hint muted tiny">
                            Liste renvoyée par votre compte DeepSeek<?= App\DeepSeek::fetchedAt() > 0
                                ? ', relevée ' . e(ago(App\DeepSeek::fetchedAt())) : '' ?>.
                        </span>
                    <?php else: ?>
                        <input type="text" name="deepseek_model" id="deepseek_model" class="mono"
                               value="<?= e((string) ($config['deepseek']['model'] ?? 'deepseek-chat')) ?>">
                        <span class="hint muted tiny">
                            « deepseek-chat » pour le modèle courant, « deepseek-reasoner » pour le modèle de
                            raisonnement. La liste s'affichera d'elle-même une fois la clé enregistrée.
                        </span>
                    <?php endif; ?>
                </div>
                <div class="field">
                    <label for="deepseek_max_tokens">Tokens maximum par page</label>
                    <input type="number" name="deepseek_max_tokens" id="deepseek_max_tokens" min="2000" max="64000" step="1000"
                           value="<?= (int) ($config['deepseek']['max_tokens'] ?? 8000) ?>">
                </div>
            </div>
            <?php
            $modeleDs = (string) ($config['deepseek']['model'] ?? '');
            $tarifsDs = ['deepseek-v4-flash', 'deepseek-v4-pro'];
            ?>
            <?php if (App\DeepSeek::isRetired($modeleDs)): ?>
                <p class="flash error small">
                    « <?= e($modeleDs) ?> » a été retiré par DeepSeek le 24 juillet 2026 : ce nom n'est plus
                    routé et l'API répond par une erreur. Les appels sont redirigés vers
                    <span class="mono"><?= e(App\DeepSeek::DEFAUT) ?></span> — les deux anciens noms ne
                    désignaient que ses deux modes. Enregistrez pour figer le nouveau nom.
                </p>
            <?php endif; ?>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Modèle</th>
                        <th class="right nowrap">Heure creuse</th>
                        <th class="right nowrap">Heure pleine</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (App\Models::modelesTarifables() as $modele): ?>
                        <?php
                        $fourchette = App\Models::priceRange($modele);
                        $corrige = App\Models::tarifSaisi($modele) !== null;
                        ?>
                        <tr>
                            <td data-label="Modèle">
                                <span class="mono"><?= e($modele) ?></span>
                                <?php if ($modeleDs === $modele): ?><span class="badge brand">Actif</span><?php endif; ?>
                                <?php if ($corrige): ?><span class="badge ok">votre tarif</span><?php endif; ?>
                            </td>
                            <td class="right nowrap" data-label="Heure creuse">
                                <span class="tarif">
                                    <input type="number" step="0.0001" min="0" name="tarif[<?= e($modele) ?>][0]"
                                           value="<?= e(number_format($fourchette['creuse'][0], 4, '.', '')) ?>"
                                           aria-label="Entrée, heure creuse"> ↓
                                    <input type="number" step="0.0001" min="0" name="tarif[<?= e($modele) ?>][1]"
                                           value="<?= e(number_format($fourchette['creuse'][1], 4, '.', '')) ?>"
                                           aria-label="Sortie, heure creuse"> ↑
                                    <input type="number" step="0.0001" min="0" name="tarif[<?= e($modele) ?>][4]"
                                           value="<?= e(number_format($fourchette['creuse'][2] ?? 0, 4, '.', '')) ?>"
                                           aria-label="Entrée déjà en cache, heure creuse"> ⟳
                                </span>
                            </td>
                            <td class="right nowrap" data-label="Heure pleine">
                                <span class="tarif">
                                    <input type="number" step="0.0001" min="0" name="tarif[<?= e($modele) ?>][2]"
                                           value="<?= e(number_format($fourchette['pleine'][0], 4, '.', '')) ?>"
                                           aria-label="Entrée, heure pleine"> ↓
                                    <input type="number" step="0.0001" min="0" name="tarif[<?= e($modele) ?>][3]"
                                           value="<?= e(number_format($fourchette['pleine'][1], 4, '.', '')) ?>"
                                           aria-label="Sortie, heure pleine"> ↑
                                    <input type="number" step="0.0001" min="0" name="tarif[<?= e($modele) ?>][5]"
                                           value="<?= e(number_format($fourchette['pleine'][2] ?? 0, 4, '.', '')) ?>"
                                           aria-label="Entrée déjà en cache, heure pleine"> ⟳
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="tiny muted">
                DeepSeek facture au double en heure pleine depuis le 16 août 2026 : du lundi au vendredi,
                01:00–04:00 et 06:00–10:00 UTC. Le reste, week-ends compris, est en heure creuse. Chaque
                appel est chiffré au tarif de l'heure où il a lieu, pas de l'heure où on le regarde —
                générer vos maquettes en dehors de ces sept heures divise la facture par deux.
                <br>
                Trois colonnes par tranche : ↓ entrée, ↑ sortie, ⟳ entrée déjà en cache. Cette dernière vaut
                le trentième de l'entrée neuve — DeepSeek met en cache tout seul, et une génération qui
                renvoie le même gabarit à chaque page est précisément le cas où ça compte. La part relue est
                comptée séparément dans le relevé.
                <br>
                Grille relevée sur
                <a href="<?= e(App\Models::DEEPSEEK_PRICING_SOURCE) ?>" target="_blank" rel="noopener noreferrer">la page « Modèles et prix » de DeepSeek</a>
                le <?= e(date('d/m/Y', strtotime(App\Models::DEEPSEEK_PRICING_DATE))) ?>. Elle reste
                modifiable : DeepSeek se réserve le droit de la changer, et c'est votre facture qui fait foi.
                En dollars par million de jetons ; videz une ligne pour revenir aux valeurs livrées.
            </p>
            <p class="tiny muted">
                La liste des modèles se met à jour seule une fois par jour.
                <button class="btn small ghost" type="submit" formaction="<?= e(url('deepseek_refresh')) ?>" formnovalidate>
                    Rafraîchir maintenant
                </button>
            </p>
        </div>

        <div data-bloc-fournisseur="gemini"<?= $fournisseur === 'gemini' ? '' : ' hidden' ?>>
            <div class="field">
                <label for="gemini_api_key">Clé API Gemini</label>
                <input type="password" name="gemini_api_key" id="gemini_api_key" autocomplete="off"
                       placeholder="<?= e($secretPlaceholder((string) ($config['gemini']['api_key'] ?? ''))) ?>">
                <span class="hint muted tiny">
                    À créer sur aistudio.google.com, rubrique « API keys ». Stockée dans data/config.json,
                    hors de la racine web. L'API « Generative Language » doit être activée sur le projet
                    Google associé, sinon la clé est refusée alors qu'elle est valide.
                </span>
            </div>
            <div class="field-row">
                <div class="field">
                    <label for="gemini_model">Modèle</label>
                    <?php
                    $modelesGem = App\Gemini::catalog();
                    $modeleGem = (string) ($config['gemini']['model'] ?? App\Gemini::DEFAUT);
                    $connuGem = in_array($modeleGem, array_column($modelesGem, 'id'), true);
                    ?>
                    <select name="gemini_model" id="gemini_model">
                        <?php foreach ($modelesGem as $modele): ?>
                            <?php $prixGem = App\Models::priceOf((string) $modele['id']); ?>
                            <option value="<?= e($modele['id']) ?>" <?= $modeleGem === $modele['id'] ? 'selected' : '' ?>>
                                <?= e($modele['label']) ?><?= $prixGem === null ? ' — tarif non relevé'
                                    : ' — ' . e(nombre($prixGem[0])) . ' $ / ' . e(nombre($prixGem[1])) . ' $ par million' ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if (!$connuGem && $modeleGem !== ''): ?>
                            <option value="<?= e($modeleGem) ?>" selected><?= e($modeleGem) ?> — hors liste</option>
                        <?php endif; ?>
                    </select>
                    <span class="hint muted tiny">
                        <?php if (App\Gemini::fetchedAt() > 0): ?>
                            Liste renvoyée par votre compte Google, relevée <?= e(ago(App\Gemini::fetchedAt())) ?>.
                        <?php else: ?>
                            Liste de secours : les noms exacts se relèvent sur votre compte dès la clé
                            enregistrée. Les versions préliminaires portent des suffixes qui changent
                            — c'est précisément pourquoi cette liste ne doit pas être écrite en dur.
                        <?php endif; ?>
                    </span>
                </div>
                <div class="field">
                    <label for="gemini_max_tokens">Tokens maximum par page</label>
                    <input type="number" name="gemini_max_tokens" id="gemini_max_tokens" min="2000" max="64000" step="1000"
                           value="<?= (int) ($config['gemini']['max_tokens'] ?? 8000) ?>">
                </div>
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Modèle</th>
                        <th class="right nowrap">Entrée</th>
                        <th class="right nowrap">Sortie</th>
                        <th class="right nowrap">Entrée en cache</th>
                        <th class="right nowrap">Coût / maquette</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (App\Models::modelesGemini() as $modele): ?>
                        <?php $prixGem = App\Models::priceOf($modele); ?>
                        <tr<?= $modeleGem === $modele ? ' style="background:var(--brand-soft)"' : '' ?>>
                            <td data-label="Modèle">
                                <span class="mono"><?= e($modele) ?></span>
                                <?php if ($modeleGem === $modele): ?><span class="badge brand">Actif</span><?php endif; ?>
                            </td>
                            <td class="right nowrap" data-label="Entrée"><?= e(nombre($prixGem[0])) ?> $</td>
                            <td class="right nowrap" data-label="Sortie"><?= e(nombre($prixGem[1])) ?> $</td>
                            <td class="right nowrap" data-label="Entrée en cache"><?= e(nombre($prixGem[2] ?? $prixGem[0])) ?> $</td>
                            <td class="right nowrap strong" data-label="Coût / maquette">
                                <?= e(App\Models::formatCost(App\Models::costFor($prixGem[0], $prixGem[1]))) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="tiny muted">
                En dollars par million de jetons. Grille relevée sur
                <a href="<?= e(App\Models::GEMINI_PRICING_SOURCE) ?>" target="_blank" rel="noopener noreferrer">la page tarifaire officielle de Google</a>
                le <?= e(date('d/m/Y', strtotime(App\Models::GEMINI_PRICING_DATE))) ?>, colonne « Global »,
                tranche « ≤ 200 000 jetons d'entrée » — celle où se situent toutes les requêtes de cette
                application. Contrairement aux deux autres fournisseurs, cette grille a pu être lue
                directement à sa source.
                <br>
                Les Flash 3.6 et 3.7 sont à moitié prix jusqu'au 31 décembre 2026 ; le relevé de
                consommation applique la remise tant qu'elle court, et le tarif plein ensuite.
            </p>
            <p class="tiny muted">
                La liste des modèles se met à jour seule une fois par jour.
                <button class="btn small ghost" type="submit" formaction="<?= e(url('gemini_refresh')) ?>" formnovalidate>
                    Rafraîchir maintenant
                </button>
            </p>
        </div>

        <?php
        $estimation = App\Models::estimateByStep();
        // Un catalogue par fournisseur, relevé une seule fois : deux étapes et
        // trois fournisseurs feraient sinon six lectures pour la même liste.
        $catalogues = [];
        foreach (array_keys(App\Ai::FOURNISSEURS) as $cleFournisseur) {
            $catalogues[$cleFournisseur] = App\Ai::catalog($cleFournisseur);
        }
        ?>
        <div class="divider"></div>
        <h3>Un modèle par étape</h3>
        <p class="small muted">
            Les trois pages ne font que remplir un gabarit dont la structure et le style sont déjà imposés,
            et elles produisent l'essentiel des jetons : un petit modèle y suffit, et le contrôle de
            conformité rattrape ses écarts. Le brief, lui, décide de ce qu'on garde et de ce qu'on refuse
            d'inventer — un modèle faible y écrit un chiffre qu'aucune page du site n'atteste, et rien
            ne peut le voir à votre place.
        </p>

        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Étape</th>
                    <th>Fournisseur</th>
                    <th>Modèle</th>
                    <th class="right nowrap">Jetons / maquette</th>
                    <th class="right nowrap">Coût estimé</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach (App\Ai::ETAPES as $etape => $labelEtape): ?>
                    <?php
                    $reglageEtape = (array) ($config['ai']['steps'][$etape] ?? []);
                    $fEtape = App\Ai::normalize((string) ($reglageEtape['provider'] ?? ''), '');
                    $mEtape = (string) ($reglageEtape['model'] ?? '');
                    $ligne = $estimation['lignes'][$etape];
                    // Le fournisseur réellement en vigueur pour cette étape :
                    // « comme le principal » suit celui du haut de page.
                    $fActif = $fEtape !== '' ? $fEtape : $fournisseur;
                    $libre = $mEtape !== '' && !in_array($mEtape, array_column($catalogues[$fActif], 'id'), true);
                    ?>
                    <tr data-etape-ligne="<?= e($etape) ?>"
                        data-jetons-entree="<?= (int) $ligne['input'] ?>"
                        data-jetons-sortie="<?= (int) $ligne['output'] ?>">
                        <td data-label="Étape">
                            <strong><?= e($labelEtape) ?></strong>
                            <div class="tiny muted">
                                <?= $etape === 'brief'
                                    ? 'Le contenu réel : prestations, textes, ce qu\'on retire faute de matière.'
                                    : 'Le remplissage des trois gabarits.' ?>
                            </div>
                        </td>
                        <td data-label="Fournisseur">
                            <select name="step_<?= e($etape) ?>_provider" data-etape-fournisseur="<?= e($etape) ?>">
                                <option value="" <?= $fEtape === '' ? 'selected' : '' ?>>Comme le principal</option>
                                <?php foreach (App\Ai::FOURNISSEURS as $cle => $nomFournisseur): ?>
                                    <option value="<?= e($cle) ?>" <?= $fEtape === $cle ? 'selected' : '' ?>>
                                        <?= e(App\Ai::label($cle)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td data-label="Modèle">
                            <?php foreach (App\Ai::FOURNISSEURS as $cle => $nomFournisseur): ?>
                                <?php
                                // Un menu par fournisseur : seul celui du
                                // fournisseur retenu est actif, les autres sont
                                // désactivés, donc ni visibles ni envoyés. Un
                                // champ libre commun affichait des modèles
                                // Claude en face de DeepSeek.
                                $actif = $cle === $fActif;
                                $defautCle = App\Ai::defaultModel($cle);
                                $prixDefaut = App\Models::priceOf($defautCle);
                                ?>
                                <select name="step_<?= e($etape) ?>_model" class="mono"
                                        data-etape-modele="<?= e($etape) ?>" data-pour="<?= e($cle) ?>"
                                        <?= $actif ? '' : 'disabled hidden' ?>>
                                    <option value=""
                                            data-entree="<?= e((string) ($prixDefaut[0] ?? '')) ?>"
                                            data-sortie="<?= e((string) ($prixDefaut[1] ?? '')) ?>"
                                            <?= $mEtape === '' ? 'selected' : '' ?>>
                                        Par défaut — <?= e($defautCle) ?>
                                    </option>
                                    <?php foreach ($catalogues[$cle] as $modele): ?>
                                        <?php $prixModele = App\Models::priceOf((string) $modele['id']); ?>
                                        <option value="<?= e($modele['id']) ?>"
                                                data-entree="<?= e((string) ($prixModele[0] ?? '')) ?>"
                                                data-sortie="<?= e((string) ($prixModele[1] ?? '')) ?>"
                                                <?= ($actif && $mEtape === $modele['id']) ? 'selected' : '' ?>>
                                            <?= e($modele['id']) ?><?= $prixModele === null ? ' — tarif non relevé'
                                                : ' — ' . e(nombre($prixModele[0])) . ' / ' . e(nombre($prixModele[1])) . ' $' ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="__libre__" <?= ($actif && $libre) ? 'selected' : '' ?>>
                                        Autre — saisir un identifiant
                                    </option>
                                </select>
                            <?php endforeach; ?>
                            <input type="text" class="mono" name="step_<?= e($etape) ?>_model_libre"
                                   data-etape-libre="<?= e($etape) ?>" spellcheck="false"
                                   placeholder="identifiant exact du modèle"
                                   value="<?= $libre ? e($mEtape) : '' ?>"
                                   <?= $libre ? '' : 'disabled hidden' ?>>
                        </td>
                        <td class="right nowrap tiny muted" data-label="Jetons / maquette">
                            <?= number_format($ligne['input'], 0, ',', ' ') ?> ↓
                            <?= number_format($ligne['output'], 0, ',', ' ') ?> ↑
                            <?= $ligne['measured'] ? '' : '<span class="faint">(estimé)</span>' ?>
                        </td>
                        <td class="right nowrap strong" data-label="Coût estimé" data-cout-etape="<?= e($etape) ?>">
                            <?= $ligne['cost'] === null ? '<span class="faint">tarif non relevé</span>'
                                : e(App\Models::formatCost($ligne['cost'])) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="tiny muted">
            <?php if ($estimation['total'] !== null): ?>
                Total estimé : <strong><?= e(App\Models::formatCost($estimation['total'])) ?></strong> par maquette complète.
            <?php else: ?>
                Le total n'est pas calculable : au moins un modèle retenu n'a pas de tarif relevé ici
                (les grilles DeepSeek ne le sont pas).
            <?php endif; ?>
            Laissez « Comme le principal » et « Par défaut » pour que tout suive le fournisseur du haut
            de page. La liste des modèles suit le fournisseur choisi sur la ligne, et le coût estimé se
            recalcule à chaque changement. La répartition des jetons, elle, s'ajuste à votre consommation
            réelle après quelques maquettes.
        </p>

        <div class="field-row">
            <div class="field">
                <label for="eur_rate">Taux de conversion dollar → euro</label>
                <input type="number" name="eur_rate" id="eur_rate" step="0.0001" min="0" max="10"
                       value="<?= e((string) (($config['billing']['eur_rate'] ?? 0) ?: '')) ?>" placeholder="0,9200">
                <span class="hint muted tiny">
                    Les API facturent en dollars : c'est le seul montant certain, et il reste affiché.
                    Renseignez votre taux — celui de votre relevé bancaire, pas un cours du jour — pour voir
                    aussi les euros. Laissé vide, rien n'est converti : un taux inventé rendrait faux ce
                    qu'on cherche justement à rendre juste.
                </span>
            </div>
            <div class="field">
                <label for="rate_note">Origine du taux</label>
                <input type="text" name="rate_note" id="rate_note"
                       value="<?= e((string) ($config['billing']['rate_note'] ?? '')) ?>"
                       placeholder="Relevé bancaire de janvier">
            </div>
        </div>

        <div class="divider"></div>

        <div class="field">
            <label for="design_prompt">Prompt global — rédaction et contenu</label>
            <textarea class="code" name="design_prompt" id="design_prompt" rows="18"><?= e((string) $config['design']['global_prompt']) ?></textarea>
            <span class="hint muted tiny">
                La partie graphique n'est plus négociable : la mise en page vient des gabarits, les couleurs
                du calcul de palette, et une page qui écrirait son propre style est refusée avant l'envoi.
                Ce prompt commande ce qui reste — le ton, les mots, les sections à garder ou à retirer, ce
                qu'on s'interdit d'inventer. Chaque fiche prospect peut y ajouter ses propres consignes.
            </span>
        </div>
        <label class="check">
            <input type="checkbox" name="allow_google_fonts" value="1" <?= !empty($config['design']['allow_google_fonts']) ? 'checked' : '' ?>>
            <span>Autoriser les polices Google Fonts <span class="hint">Améliore nettement le rendu typographique.</span></span>
        </label>
        <label class="check">
            <input type="checkbox" name="use_site_images" value="1" <?= !empty($config['design']['use_site_images']) ? 'checked' : '' ?>>
            <span>Réutiliser les photos du site d'origine <span class="hint">La maquette montre les vraies photos du prospect : c'est ce qui la fait passer d'un gabarit à une projection.</span></span>
        </label>
        <div class="field">
            <label for="assets_mode">Ces photos, on les copie ou on les pointe ?</label>
            <select name="assets_mode" id="assets_mode">
                <option value="liens" <?= ($config['design']['assets_mode'] ?? 'liens') !== 'copie' ? 'selected' : '' ?>>Pointer les adresses du site, copier si le lien ne tient pas (recommandé)</option>
                <option value="copie" <?= ($config['design']['assets_mode'] ?? 'liens') === 'copie' ? 'selected' : '' ?>>Copier les fichiers, pointer l'adresse si la copie échoue</option>
            </select>
            <span class="hint muted tiny">
                Les deux mécanismes se complètent, et l'un rattrape l'autre : on ne repart jamais sans image.
                Pointer ne stocke rien, mais la maquette dépend du site que vous cherchez à remplacer — un
                site protégé contre le vol d'images, une refonte, ou une simple adresse en HTTP dans une page
                servie en HTTPS, et l'image disparaît. Copier coûte quelques mégaoctets par prospect et règle
                les trois. Dans les deux cas, chaque image reste remplaçable à la main depuis l'éditeur.
            </span>
        </div>
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
            <label for="about_quote">Phrase signature <span class="muted">— facultative</span></label>
            <input type="text" name="about_quote" id="about_quote" value="<?= e((string) ($config['about']['quote'] ?? '')) ?>">
            <span class="hint muted tiny">Affichée en exergue sous votre présentation.</span>
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
            <?php
            // Un bouton par fournisseur réellement sollicité : avec un réglage
            // par étape, « tester la clé » sans dire laquelle ne veut plus rien
            // dire — et c'est justement la clé de l'étape qui manque qui fait
            // échouer une génération à mi-parcours.
            $aTester = App\Ai::providersUsed();
            ?>
            <form method="post" action="<?= e(url('test_claude')) ?>">
                <?= Csrf::field() ?>
                <?php foreach ($aTester as $cleTest): ?>
                    <button class="btn" type="submit" name="provider" value="<?= e($cleTest) ?>">
                        Tester la clé API <?= e(App\Ai::label($cleTest)) ?>
                        <?php if (!App\Ai::isConfiguredFor($cleTest)): ?>
                            <span class="badge warn">absente</span>
                        <?php endif; ?>
                    </button>
                <?php endforeach; ?>
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
