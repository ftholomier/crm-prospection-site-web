<?php
/** Pastille de score de vétusté. Attend $score (int|null). */
$value = $score === null ? null : (int) $score;
$class = $value === null ? 's-none' : ($value >= 70 ? 's-high' : ($value >= 45 ? 's-mid' : 's-low'));
?>
<span class="score" title="Score de vétusté du site actuel">
    <span class="dial <?= e($class) ?>"><?= $value === null ? '—' : (int) $value ?></span>
</span>
