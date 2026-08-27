<?php
/** Pastille de statut. Attend $status. */
use App\Prospect;
?>
<span class="badge status-<?= e($status) ?>"><?= e(Prospect::label($status)) ?></span>
