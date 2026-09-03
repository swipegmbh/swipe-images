<?php
/**
 * Felder: Aktiv, Format, PNG, zwei Regler, Breite.
 *
 * @var array $settings
 * @var array $caps
 *
 * @package Swipe_Images
 */
$o    = Swipe_Images_Settings::OPTION;
$bw   = Swipe_Images_Settings::quality_bounds( 'webp' );
$ba   = Swipe_Images_Settings::quality_bounds( 'avif' );
$avif = ! empty( $caps['editor']['avif'] );
?>
<fieldset class="swipe-images-fields">
	<input type="hidden" name="<?php echo esc_attr( $o ); ?>[enabled]" value="0">
	<label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[enabled]" value="1" <?php checked( $settings['enabled'] ); ?>> Aktiv</label><br>

	<p><strong>Format</strong></p>
	<?php if ( ! $avif ) : ?>
		<?php // Deaktivierte Felder sendet der Browser nicht mit; ohne Fallback fiele sanitize() auf die Defaults zurück. ?>
		<input type="hidden" name="<?php echo esc_attr( $o ); ?>[format]" value="<?php echo esc_attr( $settings['format'] ); ?>">
		<input type="hidden" name="<?php echo esc_attr( $o ); ?>[quality_avif]" value="<?php echo (int) $settings['quality_avif']; ?>">
	<?php endif; ?>
	<label><input type="radio" name="<?php echo esc_attr( $o ); ?>[format]" value="webp" <?php checked( 'webp', $settings['format'] ); ?>> WebP</label>&nbsp;&nbsp;
	<label><input type="radio" name="<?php echo esc_attr( $o ); ?>[format]" value="avif" <?php checked( 'avif', $settings['format'] ); ?> <?php disabled( ! $avif ); ?>> AVIF</label>
	<?php if ( ! $avif ) : ?>
		<span class="description">Der Bild-Editor dieses Servers kann kein AVIF schreiben.</span>
	<?php endif; ?><br>

	<input type="hidden" name="<?php echo esc_attr( $o ); ?>[convert_png]" value="0">
	<label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[convert_png]" value="1" <?php checked( $settings['convert_png'] ); ?>> PNG mitkonvertieren</label>

	<p><label for="swipe-images-qw"><strong>Qualität WebP</strong></label><br>
	<input type="range" id="swipe-images-qw" min="<?php echo (int) $bw['min']; ?>" max="<?php echo (int) $bw['max']; ?>" value="<?php echo (int) $settings['quality_webp']; ?>" data-target="swipe-images-qw-n">
	<input type="number" id="swipe-images-qw-n" name="<?php echo esc_attr( $o ); ?>[quality_webp]" min="<?php echo (int) $bw['min']; ?>" max="<?php echo (int) $bw['max']; ?>" value="<?php echo (int) $settings['quality_webp']; ?>" class="small-text"></p>

	<p><label for="swipe-images-qa"><strong>Qualität AVIF</strong></label><br>
	<input type="range" id="swipe-images-qa" min="<?php echo (int) $ba['min']; ?>" max="<?php echo (int) $ba['max']; ?>" value="<?php echo (int) $settings['quality_avif']; ?>" data-target="swipe-images-qa-n" <?php disabled( ! $avif ); ?>>
	<input type="number" id="swipe-images-qa-n" name="<?php echo esc_attr( $o ); ?>[quality_avif]" min="<?php echo (int) $ba['min']; ?>" max="<?php echo (int) $ba['max']; ?>" value="<?php echo (int) $settings['quality_avif']; ?>" class="small-text" <?php disabled( ! $avif ); ?>></p>

	<p><label for="swipe-images-bt"><strong>Maximale Bildbreite beim Upload</strong></label><br>
	<input type="number" id="swipe-images-bt" name="<?php echo esc_attr( $o ); ?>[big_image_threshold]" min="0" step="1" value="<?php echo (int) $settings['big_image_threshold']; ?>" class="small-text"> px, 0 = aus</p>

	<input type="hidden" name="<?php echo esc_attr( $o ); ?>[max_srcset_width]" value="<?php echo (int) $settings['max_srcset_width']; ?>">
</fieldset>
