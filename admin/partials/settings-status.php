<?php
/**
 * Statuskasten.
 *
 * @var array $settings
 * @var array $caps
 * @var array $counts
 * @var array $foreign
 * @var array $failed
 *
 * @package Swipe_Images
 */
$ja = static fn( $b ) => $b ? 'ja' : 'nein';
?>
<div class="swipe-images-status">
	<p><strong>Modus:</strong> <?php echo Swipe_Images::is_blocked() ? '<span class="swipe-images-warn">blockiert (Theme trägt eigenen Bildcode)</span>' : 'aktiv'; ?></p>
	<p><strong>Editor kann:</strong> WebP <?php echo esc_html( $ja( $caps['editor']['webp'] ) ); ?>, AVIF <?php echo esc_html( $ja( $caps['editor']['avif'] ) ); ?>
		<span class="description">(GD WebP <?php echo esc_html( $ja( $caps['gd']['webp'] ) ); ?>/AVIF <?php echo esc_html( $ja( $caps['gd']['avif'] ) ); ?> · Imagick WebP <?php echo esc_html( $ja( $caps['imagick']['webp'] ) ); ?>/AVIF <?php echo esc_html( $ja( $caps['imagick']['avif'] ) ); ?>)</span></p>
	<?php if ( 'avif' === $settings['format'] && empty( $caps['editor']['avif'] ) ) : ?>
		<p class="swipe-images-warn">AVIF gewählt, der Server schreibt WebP.</p>
	<?php endif; ?>
	<p><strong>Bilder:</strong> <?php echo (int) $counts['total']; ?> gesamt, <?php echo (int) $counts['converted']; ?> im Zielformat, <span id="swipe-images-pending"><?php echo (int) $counts['pending']; ?></span> ausstehend</p>
	<?php foreach ( $foreign as $hook => $list ) : ?>
		<p class="swipe-images-warn"><strong>Fremder Filter auf <code><?php echo esc_html( $hook ); ?></code>:</strong> <?php echo esc_html( implode( ', ', $list ) ); ?>. Das Plugin gewinnt mit Priorität 999.</p>
	<?php endforeach; ?>
	<?php if ( $failed ) : ?>
		<p class="swipe-images-warn"><strong><?php echo count( $failed ); ?> Bilder mit Fehlern:</strong>
		<?php foreach ( array_slice( $failed, 0, 10, true ) as $id => $msg ) : ?>
			<br>ID <?php echo (int) $id; ?>: <?php echo esc_html( $msg ); ?>
		<?php endforeach; ?></p>
	<?php endif; ?>
</div>
