<?php
/** @var array $prospect @var string $shotUrl @var string $mockupUrl @var string $interestUrl */
use App\Config;
use App\Prospect;

$company = Prospect::displayName($prospect);
$price = price((float) ($prospect['monthly_price'] ?? Config::get('offer.monthly_price', 79)));
$included = (array) Config::get('offer.included', []);
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
    .note { text-align:center; color:var(--muted); font-size:14px; margin-top:30px; }
    @media (max-width:820px) {
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
        <p class="lede">À gauche, votre site tel qu'il est aujourd'hui. À droite, une maquette réelle, entièrement conçue à partir de votre activité, de vos prestations et de votre univers.</p>
    </header>

    <div class="compare">
        <div class="pane before">
            <div class="tag">Aujourd'hui</div>
            <div class="viewport">
                <img src="<?= e($shotUrl) ?>" alt="Capture du site actuel de <?= e($company) ?>">
            </div>
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
        <a class="btn" href="<?= e($mockupUrl) ?>">Parcourir la maquette complète</a>
        <a class="btn plain" href="<?= e($interestUrl) ?>">Ça m'intéresse</a>
    </div>

    <div class="offer">
        <h2>Si elle vous plaît, je la mets en ligne</h2>
        <div class="price"><?= e($price) ?> <small>par mois, tout compris</small></div>
        <?php if ($included !== []): ?>
            <ul>
                <?php foreach ($included as $item): ?>
                    <li><?= e($item) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <p class="note">Cette page est privée et ne vous engage à rien.</p>
</div>
</body>
</html>
