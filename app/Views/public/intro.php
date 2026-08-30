<?php
/**
 * Page de proposition envoyée au prospect.
 *
 * Elle emprunte le socle des maquettes et la couleur relevée sur son propre
 * site : avant même de cliquer, il voit sa charte appliquée. Une page de vente
 * dessinée autrement que la maquette qu'elle présente se contredirait elle-même.
 *
 * @var array $prospect @var ?string $shotUrl @var string $mockupUrl
 * @var string $interestUrl @var array $palette @var string $socleUrl
 */
use App\Config;
use App\Palette;
use App\Portrait;
use App\Prospect;
use App\Router;

$company = Prospect::displayName($prospect);
$price = price((float) ($prospect['monthly_price'] ?? Config::get('offer.monthly_price', 79)));
$included = (array) Config::get('offer.included', []);

$about = (array) Config::get('about', []);
$showAbout = !empty($about['enabled']) && trim((string) ($about['name'] ?? '')) !== '';
$bioParagraphs = array_values(array_filter(array_map(
    'trim',
    preg_split('/\n\s*\n/', (string) ($about['bio'] ?? '')) ?: []
), static fn (string $p): bool => $p !== ''));
$points = array_values(array_filter((array) ($about['points'] ?? [])));

// Le contact direct. Un numéro français se ramène au format international pour
// wa.me ; sans indicatif, WhatsApp ouvre une conversation vide.
$tel = trim((string) ($about['phone'] ?? ''));
$telLien = $tel === '' ? '' : 'tel:' . preg_replace('/[^0-9+]/', '', $tel);
$zone = trim((string) ($about['zone'] ?? ''));

$whatsapp = preg_replace('/\D/', '', (string) ($about['whatsapp'] ?? '')) ?? '';
if ($whatsapp !== '' && str_starts_with($whatsapp, '0')) {
    $whatsapp = '33' . substr($whatsapp, 1);
}
$whatsappLien = $whatsapp === '' ? '' : 'https://wa.me/' . $whatsapp . '?text=' . rawurlencode(
    'Bonjour, je suis ' . $company . '. J\'ai vu la maquette de mon site et je voudrais en parler.'
);

$audit = $prospect['audit'] ?? [];
$findings = array_slice($audit['findings'] ?? [], 0, 5);
$score = isset($audit['score']) ? (int) $audit['score'] : null;

// Même garde que Generator::palette() : une palette d'avant les trois réglages
// est recalculée plutôt que servie avec des jetons manquants.
$palette = is_array($palette ?? null) && isset($palette['marque'], $palette['marque_fonce'])
    ? $palette
    : Palette::forAnalysis($prospect['analysis'] ?? [], (array) ($prospect['palette_manuelle'] ?? []));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($company) ?> — votre site, refait</title>
<?php if (!empty($palette['police_import'])): ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="<?= e((string) $palette['police_import']) ?>">
<?php endif; ?>
<link rel="stylesheet" href="<?= e($socleUrl) ?>">
<style>
<?= Palette::rootBlock($palette, (string) ($palette['police'] ?? ''), '') ?>

/* Le bandeau du socle attend une photo derrière lui ; ici il n'y en a pas.
   Un aplat d'encre, réchauffé dans un angle par la couleur relevée sur le site
   du prospect : sa teinte apparaît dès la première ligne, avant même qu'il ait
   cliqué. Le texte se pose à gauche, là où le voile reste dense. */
.heros--proposition { background: var(--sombre); }
.heros--proposition::before {
    content: ""; position: absolute; inset: 0;
    background: radial-gradient(88% 120% at 90% 0%, var(--marque) 0%, transparent 62%);
    opacity: .42;
}

/* --------------------------------------------------------------------------
   Ce que le socle ne porte pas : le comparatif avant / après.
   Deux volets de même hauteur, sans arrondi, avec la même retenue que le reste
   — un cadre trop dessiné volerait la vedette à ce qu'il encadre.
   -------------------------------------------------------------------------- */
.comparatif { display: grid; grid-template-columns: 1fr 1fr; gap: clamp(1.2rem, 3vw, 2.2rem); }

.volet { margin: 0; border: 1px solid var(--ligne); background: var(--fond); display: flex; flex-direction: column; min-width: 0; }

.volet__label {
    display: flex; align-items: center; justify-content: space-between; gap: 1rem;
    padding: .95rem 1.2rem; border-bottom: 1px solid var(--ligne);
    font-size: .7rem; letter-spacing: .2em; text-transform: uppercase; font-weight: 600;
}
.volet--avant .volet__label { color: var(--texte-doux); }
.volet--apres .volet__label { color: var(--marque-texte); }

/* La hauteur est commune aux deux volets : deux cadres inégaux se lisent comme
   deux choses différentes, pas comme un avant et un après. */
.volet__cadre { position: relative; height: clamp(300px, 40vw, 500px); overflow: hidden; background: var(--fond); }
.volet__cadre img { width: 100%; height: 100%; object-fit: cover; object-position: top center; display: block; }

/* L'aperçu est rendu à sa vraie largeur puis réduit : c'est la mise en page de
   bureau qui impressionne, pas la version téléphone. Le facteur est mesuré au
   chargement pour coller exactement au cadre ; la valeur ci-dessous sert de
   repli quand le script ne tourne pas. */
.volet__cadre iframe { width: 1280px; height: 1900px; border: 0; transform: scale(var(--zoom, .42)); transform-origin: top left; }

.veil {
    position: absolute; inset: 0; display: grid; place-items: center; text-decoration: none;
    background: transparent; transition: background var(--transition);
}
.veil:hover, .veil:focus-visible { background: rgba(20, 17, 15, .38); }
.veil__action {
    opacity: 0; transform: translateY(8px);
    transition: opacity var(--transition), transform var(--transition);
    background: #fff; color: var(--encre);
    font-size: .72rem; letter-spacing: .16em; text-transform: uppercase; font-weight: 600;
    padding: .95rem 1.7rem; box-shadow: var(--ombre-carte);
}
.veil:hover .veil__action, .veil:focus-visible .veil__action { opacity: 1; transform: none; }
@media (hover: none) { .veil__action { opacity: 1; transform: none; } }

/* Sans capture, le volet « aujourd'hui » énonce le diagnostic. Il nomme les
   problèmes au lieu de les montrer, ce qui sert tout aussi bien. */
.diagnostic { height: auto; min-height: clamp(300px, 40vw, 500px); max-height: none; padding: clamp(1.4rem, 3vw, 2.2rem); }
/* Sans capture, les deux volets n'ont plus à faire la même hauteur : le
   diagnostic est un texte, il doit se lire en entier, pas dans une lucarne. */
.comparatif:has(.diagnostic) .volet__cadre { height: auto; }
.diagnostic__score { display: flex; align-items: center; gap: 1rem; padding-bottom: 1.2rem; border-bottom: 1px solid var(--ligne); }
.diagnostic__note { width: 58px; height: 58px; flex: 0 0 58px; display: grid; place-items: center; background: var(--sombre); color: #fff; font-size: 1.35rem; font-weight: 300; }
.diagnostic__niveau { display: block; font-weight: 500; color: var(--encre); }
.diagnostic__domaine { display: block; font-size: .88rem; color: var(--texte-doux); }
.constats { list-style: none; margin: 1.4rem 0 0; padding: 0; display: grid; gap: 1rem; }
.constats li { padding-left: 1.6rem; position: relative; }
.constats li::before { content: ""; position: absolute; left: 0; top: .55em; width: 8px; height: 1px; background: var(--marque); }
.constats strong { display: block; font-weight: 500; color: var(--encre); font-size: .98rem; }
.constats span { display: block; color: var(--texte-doux); font-size: .9rem; }
/* La réponse porte la couleur de marque et un filet : elle se distingue du
   constat sans qu'on ait besoin de la lire pour comprendre que c'en est une. */
.constats__reponse {
    margin-top: .45rem; padding-left: .8rem;
    border-left: 2px solid var(--marque);
    color: var(--texte) !important;
}

/* Le prix et ce qu'il comprend, côte à côte. */
.tarif { display: grid; grid-template-columns: minmax(240px, 1fr) 1.6fr; gap: clamp(2rem, 5vw, 4rem); align-items: start; }
.tarif__montant { font-size: clamp(2.6rem, 5vw, 3.6rem); font-weight: 200; color: var(--encre); line-height: 1; }
.tarif__montant span { display: block; font-size: .74rem; letter-spacing: .18em; text-transform: uppercase; color: var(--texte-doux); margin-top: .9rem; font-weight: 500; }
.tarif__note { color: var(--texte-doux); font-size: .92rem; margin-top: 1.2rem; }
.inclus { list-style: none; margin: 0; padding: 0; display: grid; gap: .9rem; }
.inclus li { padding-left: 1.8rem; position: relative; color: var(--texte); }
.inclus li::before { content: ""; position: absolute; left: 0; top: .45em; width: 10px; height: 5px; border-left: 1px solid var(--marque); border-bottom: 1px solid var(--marque); transform: rotate(-45deg); }

/* L'en-tête du socle a une hauteur fixe, calibrée sur un nom court. Celui du
   prospect ne l'est pas toujours : sur petit écran le sur-titre s'efface et le
   nom se réduit, plutôt que de déborder d'une barre qui ne s'étire pas. */
.entete__interieur { justify-content: space-between; }
.entete__logo { font-size: clamp(.92rem, 3.2vw, 1.15rem); line-height: 1.25; }
.entete__droite .btn { white-space: nowrap; }
@media (max-width: 640px) {
    .entete__logo span { display: none; }
}

/* --------------------------------------------------------------------------
   La proposition, en deux colonnes : la promesse à gauche, la personne à
   droite. C'est une démarche humaine, et cela doit se voir avant d'avoir
   défilé — un « qui suis-je » relégué en bas de page se lit comme une mention
   légale.
   -------------------------------------------------------------------------- */
.proposition { display: grid; grid-template-columns: 1.25fr .75fr; gap: clamp(2rem, 5vw, 4rem); align-items: center; }
.proposition__promesse { color: #fff; }
.proposition__promesse .heros__titre { margin-bottom: 1.2rem; }

.carte-humaine {
    background: rgba(255, 255, 255, .07);
    border: 1px solid rgba(255, 255, 255, .16);
    backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
    padding: clamp(1.4rem, 2.6vw, 2rem);
    display: grid; gap: 1.1rem;
}
.carte-humaine__tete { display: flex; align-items: center; gap: 1.1rem; }
.carte-humaine__portrait { width: 92px; height: 92px; flex: 0 0 92px; object-fit: cover; }
.carte-humaine__nom { color: #fff; font-size: 1.15rem; font-weight: 600; line-height: 1.25; }
.carte-humaine__role { color: rgba(255, 255, 255, .78); font-size: .86rem; margin-top: .3rem; }
.carte-humaine__mot { color: rgba(255, 255, 255, .88); font-size: .95rem; line-height: 1.6; }
/* Le libellé ne se coupe pas en deux : « SUR / PLACE » sur deux lignes se lit
   comme une erreur de mise en page, et c'est la ligne qui dit la proximité. */
.carte-humaine__zone {
    display: flex; align-items: baseline; gap: .55rem;
    color: var(--marque-claire); font-size: .78rem; letter-spacing: .12em; text-transform: uppercase; font-weight: 600;
    white-space: nowrap;
}
.carte-humaine__zone span { color: rgba(255, 255, 255, .82); letter-spacing: 0; text-transform: none; font-weight: 400; font-size: .9rem; white-space: normal; }
.carte-humaine__contacts { display: flex; flex-wrap: wrap; gap: .6rem; }

/* Le bouton WhatsApp porte sa couleur propre : c'est un service que le
   prospect reconnaît, et le reconnaître fait la moitié du clic. */
.btn--whatsapp { background: #25d366; border-color: #25d366; color: #0b3d20; }
.btn--whatsapp:hover { background: #1ebe5b; border-color: #1ebe5b; color: #0b3d20; }

/* Le détail de l'accompagnement, sous le prix. */
.accompagnement { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: clamp(1.4rem, 3vw, 2.2rem); margin-top: clamp(2rem, 4vw, 3rem); }
.accompagnement__bloc { border-top: 1px solid var(--ligne); padding-top: 1.2rem; }
.accompagnement__titre { font-size: 1.05rem; font-weight: 500; color: var(--encre); margin-bottom: .5rem; }
.accompagnement__texte { font-size: .94rem; color: var(--texte-doux); line-height: 1.65; }

.portrait { width: 100%; aspect-ratio: 1 / 1; object-fit: cover; }
.portrait--initiales { display: grid; place-items: center; background: var(--sombre); color: #fff; font-size: clamp(3rem, 7vw, 5rem); font-weight: 200; letter-spacing: .05em; }
.mention { text-align: center; font-size: .86rem; color: var(--texte-doux); padding: 2.4rem 0 3rem; }

@media (max-width: 900px) {
    .comparatif { grid-template-columns: minmax(0, 1fr); }
    .tarif { grid-template-columns: minmax(0, 1fr); }
    .proposition { grid-template-columns: minmax(0, 1fr); }
}
</style>
</head>
<body>

<a class="evitement" href="#comparatif">Aller au comparatif</a>

<header class="entete">
    <div class="entete__interieur">
        <span class="entete__logo">
            <?= e($company) ?>
            <span>Proposition de refonte</span>
        </span>
        <div class="entete__droite">
            <a class="btn btn--plein btn--petit" href="<?= e($mockupUrl) ?>" target="_blank" rel="noopener">Ouvrir la maquette</a>
        </div>
    </div>
    <span class="entete__faisceau" aria-hidden="true"><i class="entete__onde"></i></span>
</header>

<main>

<section class="heros heros--page heros--proposition">
    <div class="conteneur">
        <div class="proposition">
            <div class="proposition__promesse">
                <p class="surtitre surtitre--clair">Préparé pour <?= e($company) ?></p>
                <h1 class="heros__titre">Voici à quoi pourrait ressembler votre site.</h1>
                <p class="heros__texte">
                    Trois pages réelles, écrites à partir de votre activité et de vos prestations.
                    Pas une démonstration générique : votre nom, vos métiers, vos photos.
                </p>
                <p class="heros__texte">
                    Pourquoi cette page ? Parce qu'un devis ne montre rien. Vous voyez d'abord,
                    vous décidez ensuite — et vous ne payez que si vous décidez.
                </p>
                <div class="heros__actions">
                    <a class="btn btn--plein" href="<?= e($mockupUrl) ?>" target="_blank" rel="noopener">Parcourir les trois pages</a>
                    <a class="btn btn--clair" href="<?= e($interestUrl) ?>">Ça m'intéresse</a>
                </div>
            </div>

            <?php if ($showAbout): ?>
                <?php /* La personne, dès la première page vue. Une refonte de site
                          se décide sur la confiance faite à quelqu'un, pas sur une
                          grille de fonctionnalités. */ ?>
                <aside class="carte-humaine">
                    <div class="carte-humaine__tete">
                        <?php if (Portrait::exists()): ?>
                            <img class="portrait carte-humaine__portrait" src="<?= e(Router::publicUrl('portrait')) ?>"
                                 alt="<?= e((string) $about['name']) ?>">
                        <?php else: ?>
                            <span class="portrait portrait--initiales carte-humaine__portrait"><?= e(Portrait::initials((string) $about['name'])) ?></span>
                        <?php endif; ?>
                        <div>
                            <p class="carte-humaine__nom"><?= e((string) $about['name']) ?></p>
                            <?php if (trim((string) ($about['role'] ?? '')) !== ''): ?>
                                <p class="carte-humaine__role"><?= e((string) $about['role']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($bioParagraphs !== []): ?>
                        <p class="carte-humaine__mot"><?= e($bioParagraphs[0]) ?></p>
                    <?php endif; ?>

                    <?php if ($zone !== ''): ?>
                        <p class="carte-humaine__zone">
                            Sur place
                            <span><?= e($zone) ?> — je me déplace, on se rencontre.</span>
                        </p>
                    <?php endif; ?>

                    <?php if ($telLien !== '' || $whatsappLien !== ''): ?>
                        <div class="carte-humaine__contacts">
                            <?php if ($whatsappLien !== ''): ?>
                                <a class="btn btn--petit btn--whatsapp" href="<?= e($whatsappLien) ?>" target="_blank" rel="noopener">WhatsApp</a>
                            <?php endif; ?>
                            <?php if ($telLien !== ''): ?>
                                <a class="btn btn--petit btn--clair" href="<?= e($telLien) ?>"><?= e($tel) ?></a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </aside>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="section" id="comparatif">
    <div class="conteneur">
        <div class="section__tete section__tete--centre reveler">
            <p class="surtitre surtitre--centre">Avant / après</p>
            <h2 class="titre-section">Le même métier, présenté autrement</h2>
            <p class="section__chapo">
                <?php if ($shotUrl !== null): ?>
                    À gauche, votre site tel qu'il est aujourd'hui. À droite, ce qu'il pourrait être.
                <?php else: ?>
                    À gauche, ce que révèle l'analyse de votre site actuel. À droite, ce qu'il pourrait être.
                <?php endif; ?>
            </p>
        </div>

        <div class="comparatif reveler">
            <figure class="volet volet--avant">
                <figcaption class="volet__label">
                    <span>Aujourd'hui</span>
                    <a class="lien-fleche" href="<?= e((string) $prospect['url']) ?>" target="_blank" rel="noopener noreferrer">Voir</a>
                </figcaption>
                <?php if ($shotUrl !== null): ?>
                    <div class="volet__cadre">
                        <img src="<?= e($shotUrl) ?>" alt="Capture du site actuel de <?= e($company) ?>">
                    </div>
                <?php else: ?>
                    <div class="volet__cadre diagnostic">
                        <?php if ($score !== null): ?>
                            <div class="diagnostic__score">
                                <span class="diagnostic__note"><?= $score ?></span>
                                <div>
                                    <span class="diagnostic__niveau"><?= e((string) ($audit['level'] ?? '')) ?></span>
                                    <span class="diagnostic__domaine">Diagnostic de <?= e((string) $prospect['domain']) ?></span>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if ($findings !== []): ?>
                            <?php /* Faute de capture, le volet « aujourd'hui » énonce le
                                     diagnostic. Chaque constat est suivi de ce que la
                                     refonte y change : une liste de reproches sans
                                     réponse braque, une liste de réponses convainc. */ ?>
                            <ul class="constats">
                                <?php foreach ($findings as $finding): ?>
                                    <li>
                                        <strong><?= e((string) $finding['label']) ?></strong>
                                        <span><?= e((string) $finding['detail']) ?></span>
                                        <?php if (trim((string) ($finding['fix'] ?? '')) !== ''): ?>
                                            <span class="constats__reponse"><?= e((string) $finding['fix']) ?></span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p>Votre site actuel est en ligne à l'adresse <?= e((string) $prospect['domain']) ?>.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </figure>

            <figure class="volet volet--apres">
                <figcaption class="volet__label">
                    <span>La proposition</span>
                    <a class="lien-fleche" href="<?= e($mockupUrl) ?>" target="_blank" rel="noopener">Ouvrir</a>
                </figcaption>
                <div class="volet__cadre" data-apercu>
                    <iframe src="<?= e($mockupUrl) ?>" title="Aperçu de la maquette de <?= e($company) ?>"
                            loading="lazy" scrolling="no" tabindex="-1"></iframe>
                    <a class="veil" href="<?= e($mockupUrl) ?>" target="_blank" rel="noopener">
                        <span class="veil__action">Ouvrir dans un nouvel onglet</span>
                    </a>
                </div>
            </figure>
        </div>
    </div>
</section>

<section class="section section--teinte">
    <div class="conteneur">
        <div class="section__tete reveler">
            <p class="surtitre">Ce que vous voyez n'est qu'un échantillon</p>
            <h2 class="titre-section">Si ces trois pages vous plaisent, c'est tout le site qui est refait</h2>
            <p class="section__chapo">
                Accueil, à propos, prestations : de quoi juger sur pièces plutôt que sur promesse.
                Ces trois pages ont été composées de manière semi-automatique à partir de votre site
                actuel — c'est ce qui permet de vous les montrer sans rien vous facturer. Elles ne sont
                pas figées : tout ce que vous y voyez se change.
            </p>
            <p class="section__chapo">
                Si cette direction vous convient, l'intégralité de votre site est reprise ainsi :
                <strong>toutes vos pages</strong>, tous vos contenus, vos mentions légales, votre
                formulaire de contact, la même exigence de lisibilité et de rendu sur téléphone.
                Et ce qui manque aujourd'hui — une page prestation par métier, une galerie de
                réalisations, un formulaire de devis détaillé — se construit à ce moment-là.
            </p>
        </div>

        <ol class="etapes reveler">
            <li class="etape">
                <p class="etape__numero">01</p>
                <h3 class="etape__titre">Vous validez la direction</h3>
                <p class="etape__texte">Couleurs, ton, mise en page. Tout se discute et se modifie avant d'aller plus loin.</p>
            </li>
            <li class="etape">
                <p class="etape__numero">02</p>
                <h3 class="etape__titre">Je refais le site entier</h3>
                <p class="etape__texte">Chaque page existante est reprise et remise en forme. Vous n'avez rien à ressaisir.</p>
            </li>
            <li class="etape">
                <p class="etape__numero">03</p>
                <h3 class="etape__titre">Il reste à jour</h3>
                <p class="etape__texte">Un texte à changer, une photo à remplacer, une prestation à ajouter : vous demandez, je m'en occupe.</p>
            </li>
        </ol>
    </div>
</section>

<section class="section">
    <div class="conteneur">
        <div class="section__tete reveler">
            <p class="surtitre">Le tarif</p>
            <h2 class="titre-section">Tout compris, sans facture de création</h2>
            <p class="section__chapo">
                Un seul montant mensuel. Pas de frais de création, pas de supplément à chaque
                modification, pas de facture surprise à la première mise à jour.
            </p>
        </div>
        <div class="tarif reveler">
            <div>
                <p class="tarif__montant"><?= e($price) ?><span>par mois</span></p>
                <p class="tarif__note">Rien à régler d'avance. Aucune durée minimum.</p>
            </div>
            <?php if ($included !== []): ?>
                <ul class="inclus">
                    <?php foreach ($included as $item): ?>
                        <li><?= e((string) $item) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="accompagnement reveler">
            <div class="accompagnement__bloc">
                <h3 class="accompagnement__titre">Les mises à jour, c'est moi</h3>
                <p class="accompagnement__texte">
                    Un texte à corriger, une photo à remplacer, une prestation à ajouter, vos horaires
                    d'été : vous le demandez, je le fais. Vous n'avez aucun outil à apprendre et rien
                    à administrer. La sécurité, les sauvegardes et l'hébergement sont compris.
                </p>
            </div>
            <div class="accompagnement__bloc">
                <h3 class="accompagnement__titre">Un expert au bout de WhatsApp</h3>
                <p class="accompagnement__texte">
                    <?php if ($whatsappLien !== ''): ?>
                        Vous m'écrivez directement, en temps réel. Pas de ticket, pas de standard :
                    <?php else: ?>
                        Vous m'écrivez directement, en temps réel :
                    <?php endif; ?>
                    une question sur Google, sur une campagne, sur ce que l'intelligence artificielle
                    change à votre métier — vous avez un interlocuteur en webmarketing, en IA et en
                    digital, pas seulement un site web.
                </p>
                <?php if ($whatsappLien !== ''): ?>
                    <p class="mt">
                        <a class="btn btn--petit btn--whatsapp" href="<?= e($whatsappLien) ?>" target="_blank" rel="noopener">
                            Ouvrir WhatsApp
                        </a>
                    </p>
                <?php endif; ?>
            </div>
            <div class="accompagnement__bloc">
                <h3 class="accompagnement__titre">Et au-delà du site</h3>
                <p class="accompagnement__texte">
                    Tout est possible ensuite : un espace client, une prise de rendez-vous en ligne,
                    un devis automatisé, un outil interne à votre métier. Ce sont des développements
                    sur mesure, chiffrés à part — mais vous savez à qui les demander, et cette
                    personne connaît déjà votre entreprise.
                </p>
            </div>
        </div>
    </div>
</section>

<section class="citation">
    <div class="conteneur conteneur--etroit">
        <p class="citation__texte">Vous arrêtez quand vous voulez, sans justification et sans frais.</p>
        <p class="citation__auteur">C'est à moi de vous donner envie de rester</p>
    </div>
</section>

<section class="bande-cta">
    <div class="conteneur">
        <h2 class="titre-section">On en parle ?</h2>
        <p>
            Dites-moi simplement si la direction vous plaît. On ajuste ce qui doit l'être,
            et vous décidez ensuite — pas avant.
            <?php if ($zone !== ''): ?>
                Je suis en <?= e($zone) ?> : on peut aussi se voir.
            <?php endif; ?>
        </p>
        <div class="bande-cta__actions">
            <?php if ($whatsappLien !== ''): ?>
                <a class="btn btn--whatsapp" href="<?= e($whatsappLien) ?>" target="_blank" rel="noopener">
                    M'écrire sur WhatsApp
                </a>
            <?php endif; ?>
            <?php if ($telLien !== ''): ?>
                <a class="btn btn--plein" href="<?= e($telLien) ?>">Appeler le <?= e($tel) ?></a>
            <?php endif; ?>
            <a class="btn btn--clair" href="<?= e($interestUrl) ?>">Discutons de mon site complet</a>
            <a class="btn btn--clair" href="<?= e($mockupUrl) ?>" target="_blank" rel="noopener">Revoir les trois pages</a>
        </div>
    </div>
</section>

<p class="mention">
    Cette page est privée, elle ne vous engage à rien, et personne d'autre n'y a accès.
    Les trois pages ont été composées de manière semi-automatique à partir de votre site actuel,
    puis relues — tout s'y modifie.
</p>

</main>

<script src="<?= e(str_replace('socle.css', 'socle.js', $socleUrl)) ?>"></script>
<script>
/* L'aperçu est rendu en 1280 px de large puis réduit pour tenir dans son cadre.
   Le facteur exact se mesure : une valeur figée laisserait un bord vide sur
   certaines largeurs et déborderait sur d'autres. */
(function () {
    var cadres = document.querySelectorAll('[data-apercu]');
    if (!cadres.length) { return; }
    function ajuster() {
        cadres.forEach(function (cadre) {
            var largeur = cadre.clientWidth;
            if (largeur > 0) {
                cadre.style.setProperty('--zoom', (largeur / 1280).toFixed(4));
            }
        });
    }
    ajuster();
    window.addEventListener('resize', ajuster, { passive: true });
})();
</script>
</body>
</html>
