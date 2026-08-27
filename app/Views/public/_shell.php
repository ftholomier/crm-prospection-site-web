<?php
/**
 * Enveloppe commune aux pages publiques simples.
 * Attend $pageTitle et $pageBody (HTML déjà échappé).
 */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($pageTitle) ?></title>
<style>
    * { box-sizing:border-box; }
    body { margin:0; min-height:100vh; display:grid; place-items:center; background:#f1f5f9; color:#0f172a;
        font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; padding:24px; }
    .box { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:36px; max-width:520px; width:100%;
        box-shadow:0 6px 26px rgba(15,23,42,.07); }
    h1 { font-size:23px; margin:0 0 12px; letter-spacing:-.01em; }
    p { color:#475569; margin:0 0 16px; }
    label { display:block; font-size:14px; font-weight:600; margin:0 0 6px; }
    input, textarea { width:100%; padding:11px 13px; border:1px solid #cbd5e1; border-radius:9px; font:inherit;
        font-size:15px; margin-bottom:16px; }
    input:focus, textarea:focus { outline:none; border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.13); }
    textarea { min-height:110px; resize:vertical; }
    button, .btn { display:inline-block; background:#2563eb; color:#fff; border:0; border-radius:9px; padding:13px 24px;
        font:inherit; font-size:16px; font-weight:600; cursor:pointer; text-decoration:none; }
    button:hover, .btn:hover { background:#1d4ed8; }
    .ok { background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:14px 16px; border-radius:9px; }
    .muted { color:#64748b; font-size:14px; }
</style>
</head>
<body>
<div class="box"><?= $pageBody ?></div>
</body>
</html>
