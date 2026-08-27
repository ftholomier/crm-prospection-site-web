<?php
/** @var array $prospect @var string $shotUrl @var string $mockupUrl @var string $interestUrl */
use App\Config;
use App\Prospect;

$company = Prospect::displayName($prospect);
$price = price((float) ($prospect['monthly_price'] ?? Config::get('offer.monthly_price', 79)));
$included = (array) Config::get('offer.included', []);

// Sans capture, le volet « aujourd'hui » présente le diagnostic du site :
// il est toujours disponible, et il nomme les problèmes plutôt que de les
// montrer, ce qui sert tout aussi bien l'argumentaire.
$about = (array) Config::get('about', []);
$showAbout = !empty($about['enabled']) && trim((string) ($about['name'] ?? '')) !== '';
$bioParagraphs = array_values(array_filter(array_map(
    'trim',
    preg_split('/\n\s*\n/', (string) ($about['bio'] ?? '')) ?: []
), static fn (string $p): bool => $p !== ''));

$audit = $prospect['audit'] ?? [];
$findings = array_slice($audit['findings'] ?? [], 0, 5);
$score = isset($audit['score']) ? (int) $audit['score'] : null;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($company) ?> — avant / après</title>
<style>
    :root { --ink:#0f172a; --muted:#64748b; --line:#e2e8f0; --brand:#2563eb; }
    * { box-sizing: border-box; }
    body { margin:0; background:#f1f5f9; color:var(--ink);
        font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
    .wrap { max-width:1180px; margin:0 auto; padding:44px 22px 70px; }
    header { text-align:center; margin-bottom:38px; }
    .eyebrow { display:inline-block; background:#dbeafe; color:#1d4ed8; font-size:13px; font-weight:600;
        padding:6px 14px; border-radius:999px; letter-spacing:.02em; }
    h1 { font-size:clamp(28px,4.4vw,44px); line-height:1.15; letter-spacing:-.02em; margin:18px 0 12px; }
    .lede { color:var(--muted); max-width:640px; margin:0 auto; font-size:17px; }
    .compare { display:grid; grid-template-columns:1fr 1fr; gap:22px; align-items:start; }
    .pane { background:#fff; border:1px solid var(--line); border-radius:14px; overflow:hidden;
        box-shadow:0 4px 20px rgba(15,23,42,.06); }
    .pane .tag { padding:13px 18px; font-size:13px; font-weight:700; text-transform:uppercase;
        letter-spacing:.06em; border-bottom:1px solid var(--line); }
    .pane.before .tag { background:#fef2f2; color:#b91c1c; }
    .pane.after .tag { background:#ecfdf5; color:#047857; }
    .viewport { position:relative; height:460px; overflow:hidden; background:#fff; }
    .viewport img { width:100%; display:block; }
    .viewport iframe { width:1280px; height:1610px; border:0; transform:scale(.36); transform-origin:top left; }
    .veil { position:absolute; inset:0; }
    .viewport.diagnostic { height:auto; min-height:460px; padding:26px; overflow-y:auto; background:#fff; }
    .score-line { display:flex; align-items:center; gap:14px; padding-bottom:18px; border-bottom:1px solid var(--line); }
    .score-dial { width:52px; height:52px; flex:0 0 52px; border-radius:50%; background:#dc2626; color:#fff;
        display:grid; place-items:center; font-weight:700; font-size:18px; }
    .score-line strong { display:block; font-size:16px; }
    .score-line span { color:var(--muted); font-size:14px; }
    .findings { list-style:none; margin:18px 0 0; padding:0; }
    .findings li { padding:0 0 14px 26px; position:relative; }
    .findings li::before { content:"✕"; position:absolute; left:0; top:1px; color:#dc2626; font-weight:700; }
    .findings strong { display:block; font-size:15px; }
    .findings span { color:var(--muted); font-size:13.5px; line-height:1.5; }
    .empty-note { color:var(--muted); }
    .see-current { display:inline-block; margin-top:6px; color:var(--brand); font-size:14px; }
    .cta { text-align:center; margin-top:40px; }
    .btn { display:inline-block; background:var(--brand); color:#fff; text-decoration:none; font-weight:600;
        font-size:17px; padding:16px 34px; border-radius:10px; box-shadow:0 6px 18px rgba(37,99,235,.28); }
    .btn:hover { background:#1d4ed8; }
    .btn.plain { background:#fff; color:var(--ink); border:1px solid var(--line); box-shadow:none; margin-left:10px; }
    .offer { margin-top:52px; background:#fff; border:1px solid var(--line); border-radius:14px; padding:30px; }
    .offer h2 { margin:0 0 6px; font-size:21px; }
    .price { font-size:34px; font-weight:700; letter-spacing:-.02em; }
    .price small { font-size:15px; font-weight:400; color:var(--muted); }
    .offer ul { list-style:none; margin:18px 0 0; padding:0; display:grid;
        grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:10px; }
    .offer li { padding-left:26px; position:relative; color:#334155; }
    .offer li::before { content:"✓"; position:absolute; left:0; color:#059669; font-weight:700; }
    .offer-lede { font-size:17px; color:#334155; max-width:760px; margin:10px 0 0; }
    .steps { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:20px; margin:30px 0; }
    .step { position:relative; padding-left:44px; }
    .step .num { position:absolute; left:0; top:0; width:30px; height:30px; border-radius:50%;
        background:var(--brand); color:#fff; display:grid; place-items:center; font-weight:700; font-size:14px; }
    .step strong { display:block; margin-bottom:4px; }
    .step span:not(.num) { color:var(--muted); font-size:14.5px; line-height:1.55; }
    .price-block { display:grid; grid-template-columns:minmax(220px,1fr) 2fr; gap:24px;
        align-items:start; padding:24px; background:#f8fafc; border-radius:12px; }
    .price-note { color:var(--muted); font-size:14px; margin:6px 0 0; }
    .closing { margin-top:26px; padding-top:24px; border-top:1px solid var(--line); }
    .closing p { max-width:680px; }
    .about { margin-top:26px; background:#fff; border:1px solid var(--line); border-radius:14px; padding:30px; }
    .about-head { display:flex; align-items:center; gap:18px; margin-bottom:20px; }
    .avatar { width:76px; height:76px; flex:0 0 76px; border-radius:50%; object-fit:cover;
        border:2px solid #fff; box-shadow:0 2px 10px rgba(15,23,42,.14); }
    .avatar.initials { display:grid; place-items:center; background:var(--brand); color:#fff;
        font-weight:700; font-size:26px; letter-spacing:.02em; }
    .about-label { display:block; font-size:12px; font-weight:700; text-transform:uppercase;
        letter-spacing:.07em; color:var(--brand); }
    .about h2 { margin:4px 0 2px; font-size:22px; }
    .about-role { margin:0; color:var(--muted); font-size:15px; }
    .about-bio { max-width:760px; color:#334155; }
    .about-points { list-style:none; margin:20px 0 0; padding:0; display:grid;
        grid-template-columns:repeat(auto-fit,minmax(230px,1fr)); gap:10px; }
    .about-points li { padding-left:26px; position:relative; color:#334155; font-size:15px; }
    .about-points li::before { content:"✓"; position:absolute; left:0; color:#059669; font-weight:700; }
    .about-quote { margin:20px 0 0; padding:14px 20px; border-left:3px solid var(--brand);
        background:#f8fafc; border-radius:0 8px 8px 0; font-size:16px; font-style:italic; color:#1e293b; }
    .about-site { margin:20px 0 0; font-size:15px; }
    .note { text-align:center; color:var(--muted); font-size:14px; margin-top:30px; }
    @media (max-width:820px) {
        .about-head { flex-direction:column; align-items:flex-start; gap:12px; }
        .price-block { grid-template-columns:1fr; }
        .compare { grid-template-columns:1fr; }
        .viewport { height:300px; }
        .viewport iframe { transform:scale(.28); }
        .btn.plain { margin:10px 0 0; display:block; }
    }
</style>
</head>
<body>
<div class="wrap">
    <header>
        <span class="eyebrow">Proposition préparée pour <?= e($company) ?></span>
        <h1>Voici à quoi pourrait ressembler votre site.</h1>
        <p class="lede">
            <?php if ($shotUrl !== null): ?>
                À gauche, votre site tel qu'il est aujourd'hui. À droite, trois pages réelles,
                conçues à partir de votre activité et de vos prestations — un échantillon de ce que
                deviendrait <strong>l'ensemble</strong> de votre site.
            <?php else: ?>
                À gauche, ce que révèle l'analyse de votre site actuel. À droite, trois pages réelles,
                conçues à partir de votre activité et de vos prestations — un échantillon de ce que
                deviendrait <strong>l'ensemble</strong> de votre site.
            <?php endif; ?>
        </p>
    </header>

    <div class="compare">
        <div class="pane before">
            <div class="tag">Aujourd'hui</div>
            <?php if ($shotUrl !== null): ?>
                <div class="viewport">
                    <img src="<?= e($shotUrl) ?>" alt="Capture du site actuel de <?= e($company) ?>">
                </div>
            <?php else: ?>
                <div class="viewport diagnostic">
                    <?php if ($score !== null): ?>
                        <div class="score-line">
                            <span class="score-dial"><?= $score ?></span>
                            <div>
                                <strong><?= e((string) ($audit['level'] ?? '')) ?></strong>
                                <span>Diagnostic de <?= e($prospect['domain']) ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($findings !== []): ?>
                        <ul class="findings">
                            <?php foreach ($findings as $finding): ?>
                                <li>
                                    <strong><?= e((string) $finding['label']) ?></strong>
                                    <span><?= e((string) $finding['detail']) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="empty-note">Votre site actuel est en ligne à l'adresse <?= e($prospect['domain']) ?>.</p>
                    <?php endif; ?>
                    <a class="see-current" href="<?= e((string) $prospect['url']) ?>" target="_blank" rel="noopener noreferrer">
                        Voir mon site actuel
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <div class="pane after">
            <div class="tag">La proposition</div>
            <div class="viewport">
                <iframe src="<?= e($mockupUrl) ?>" title="Aperçu de la nouvelle maquette" loading="lazy" scrolling="no" tabindex="-1"></iframe>
                <span class="veil"></span>
            </div>
        </div>
    </div>

    <div class="cta">
        <a class="btn" href="<?= e($mockupUrl) ?>">Parcourir les trois pages</a>
        <a class="btn plain" href="<?= e($interestUrl) ?>">Ça m'intéresse</a>
    </div>

    <div class="offer">
        <h2>Ces trois pages ne sont qu'un échantillon</h2>
        <p class="offer-lede">
            Accueil, à propos, prestations : de quoi juger sur pièces plutôt que sur promesse.
            Si cette direction vous convient, c'est <strong>l'intégralité de votre site</strong> qui est
            refaite ainsi — toutes vos pages, tous vos contenus repris, la même exigence de mise en page,
            de lisibilité et de rendu sur téléphone.
        </p>

        <div class="steps">
            <div class="step">
                <span class="num">1</span>
                <strong>Vous validez la direction</strong>
                <span>Couleurs, ton, mise en page. Tout se discute et se modifie avant d'aller plus loin.</span>
            </div>
            <div class="step">
                <span class="num">2</span>
                <strong>Je refais le site entier</strong>
                <span>Chaque page existante est reprise et remise en forme. Vous n'avez rien à ressaisir.</span>
            </div>
            <div class="step">
                <span class="num">3</span>
                <strong>Il reste à jour</strong>
                <span>Un texte à changer, une photo à remplacer, une prestation à ajouter : vous demandez, je m'en occupe.</span>
            </div>
        </div>

        <div class="price-block">
            <div>
                <div class="price"><?= e($price) ?> <small>par mois, tout compris</small></div>
                <p class="price-note">Pas de facture de création à régler d'avance. Pas de durée minimum.</p>
            </div>
            <?php if ($included !== []): ?>
                <ul>
                    <?php foreach ($included as $item): ?>
                        <li><?= e($item) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="closing">
            <p><strong>Vous arrêtez quand vous voulez.</strong> Sans justification, sans préavis, sans frais.
               C'est à moi de vous donner envie de rester, pas à un contrat de vous retenir.</p>
            <a class="btn" href="<?= e($interestUrl) ?>">Discutons de mon site complet</a>
        </div>
    </div>

    <?php if ($showAbout): ?>
        <section class="about">
            <div class="about-head">
                <?php if (App\Portrait::exists()): ?>
                    <img class="avatar" src="<?= e(App\Router::publicUrl('portrait')) ?>" alt="<?= e((string) $about['name']) ?>">
                <?php else: ?>
                    <span class="avatar initials"><?= e(App\Portrait::initials((string) $about['name'])) ?></span>
                <?php endif; ?>
                <div>
                    <span class="about-label"><?= e((string) ($about['title'] ?? 'Qui suis-je')) ?></span>
                    <h2><?= e((string) $about['name']) ?></h2>
                    <?php if (trim((string) ($about['role'] ?? '')) !== ''): ?>
                        <p class="about-role"><?= e((string) $about['role']) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <?php foreach ($bioParagraphs as $paragraph): ?>
                <p class="about-bio"><?= nl2br(e($paragraph)) ?></p>
            <?php endforeach; ?>

            <?php if (trim((string) ($about['quote'] ?? '')) !== ''): ?>
                <blockquote class="about-quote"><?= e((string) $about['quote']) ?></blockquote>
            <?php endif; ?>

            <?php $points = array_values(array_filter((array) ($about['points'] ?? []))); ?>
            <?php if ($points !== []): ?>
                <ul class="about-points">
                    <?php foreach ($points as $point): ?>
                        <li><?= e((string) $point) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if (trim((string) ($about['site_url'] ?? '')) !== ''): ?>
                <p class="about-site">
                    <a href="<?= e((string) $about['site_url']) ?>" target="_blank" rel="noopener noreferrer">
                        <?= e(trim((string) ($about['site_label'] ?? '')) !== '' ? (string) $about['site_label'] : (string) $about['site_url']) ?>
                    </a>
                </p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <p class="note">Cette page est privée, elle ne vous engage à rien, et personne d'autre n'y a accès.</p>
</div>
</body>
</html>
