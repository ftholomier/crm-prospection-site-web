<?php
/**
 * Barre insérée en bas de chaque page de maquette.
 * @var array $prospect @var string $interestUrl @var string $introUrl @var string $currentSiteUrl
 */
use App\Config;
use App\Prospect;

$price = price((float) ($prospect['monthly_price'] ?? Config::get('offer.monthly_price', 79)));
?>
<style>
.ps-bar{position:fixed;left:0;right:0;bottom:0;z-index:2147483000;background:#0f172a;color:#e2e8f0;
    font:14px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    display:flex;align-items:center;gap:16px;padding:12px 20px;flex-wrap:wrap;
    box-shadow:0 -4px 20px rgba(15,23,42,.25)}
.ps-bar__label{font-weight:600;color:#fff}
.ps-bar__label span{display:block;font-weight:400;font-size:12.5px;color:#94a3b8}
.ps-bar__links{display:flex;gap:8px;align-items:center;margin-left:auto;flex-wrap:wrap}
.ps-bar a{color:#cbd5e1;text-decoration:none;font-size:13.5px;padding:8px 13px;border-radius:8px;
    border:1px solid rgba(255,255,255,.16)}
.ps-bar a:hover{background:rgba(255,255,255,.09);color:#fff}
.ps-bar a.ps-bar__cta{background:#2563eb;border-color:#2563eb;color:#fff;font-weight:600}
.ps-bar a.ps-bar__cta:hover{background:#1d4ed8}
body{padding-bottom:82px!important}
@media (max-width:700px){.ps-bar{padding:10px 14px;gap:10px}.ps-bar__links{margin-left:0;width:100%}
    .ps-bar a{flex:1;text-align:center}body{padding-bottom:132px!important}}
</style>
<div class="ps-bar" role="complementary" aria-label="Proposition de refonte">
    <div class="ps-bar__label">
        Maquette proposée à <?= e(Prospect::displayName($prospect)) ?>
        <span><?= e($price) ?> par mois, tout compris, sans engagement</span>
    </div>
    <div class="ps-bar__links">
        <?php if ($introUrl !== ''): ?>
            <a href="<?= e($introUrl) ?>">Comparer avec mon site actuel</a>
        <?php else: ?>
            <a href="<?= e($currentSiteUrl) ?>" target="_blank" rel="noopener noreferrer">Voir mon site actuel</a>
        <?php endif; ?>
        <a class="ps-bar__cta" href="<?= e($interestUrl) ?>">Ça m'intéresse</a>
    </div>
</div>
