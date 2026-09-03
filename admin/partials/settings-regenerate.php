<?php
/**
 * Bestand regenerieren, AJAX-Batches.
 *
 * @var array $counts
 */
?>
<p><button type="button" class="button button-secondary" id="swipe-images-regen" data-pending="<?php echo (int) $counts['pending']; ?>" <?php disabled( 0 === (int) $counts['pending'] ); ?>>
	Bestand regenerieren (<?php echo (int) $counts['pending']; ?> ausstehend)</button></p>
<div class="swipe-images-bar" id="swipe-images-bar" hidden><span></span></div>
<p id="swipe-images-log" class="description"></p>
<p class="description">Alte Dateien bleiben liegen. Aufräumen per WP-CLI: <code>wp swipe-images regenerate --delete-old</code> und <code>wp swipe-images cleanup</code>.</p>
