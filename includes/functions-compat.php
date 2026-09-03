<?php
/**
 * Kompatibilitäts-API: die Helfer, die swipe-Blocks direkt aufrufen.
 *
 * Wird nur im aktiven Modus geladen. Jede Funktion steht in function_exists(),
 * damit ein Theme mit Restcode nie zum Fatal führt.
 *
 * @package Swipe_Images
 */

if ( ! function_exists( 'swipe_get_webp_url' ) ) {
	/**
	 * @deprecated 1.0.0 URLs liegen bereits im Zielformat vor; gibt die URL unverändert zurück.
	 */
	function swipe_get_webp_url( $image_url, $quality = null ) {
		return $image_url;
	}
}

if ( ! function_exists( 'swipe_get_webp_image' ) ) {
	/** @deprecated 1.0.0 Nutze wp_get_attachment_image_url(). */
	function swipe_get_webp_image( $image_id, $size = 'full', $quality = null ) {
		$url = wp_get_attachment_image_url( (int) $image_id, $size );
		return $url ? $url : '';
	}
}

if ( ! function_exists( 'swipe_get_webp_from_acf' ) ) {
	/** @deprecated 1.0.0 Liest die URL aus dem ACF-Array. */
	function swipe_get_webp_from_acf( $image_array, $size = 'full', $quality = null ) {
		if ( ! is_array( $image_array ) ) {
			return '';
		}
		if ( 'full' !== $size && ! empty( $image_array['sizes'][ $size ] ) ) {
			return $image_array['sizes'][ $size ];
		}
		return $image_array['url'] ?? '';
	}
}

if ( ! function_exists( 'swipe_convert_to_webp' ) ) {
	/**
	 * @deprecated 1.0.0 Konvertiert eine Datei über den WordPress-Editor nach WebP.
	 */
	function swipe_convert_to_webp( $source_path, $destination_path, $quality = null ) {
		if ( ! file_exists( $source_path ) ) {
			return false;
		}
		$editor = wp_get_image_editor( $source_path );
		if ( is_wp_error( $editor ) ) {
			return false;
		}
		if ( null !== $quality ) {
			$editor->set_quality( (int) $quality );
		}
		$saved = $editor->save( $destination_path, 'image/webp' );
		return ! is_wp_error( $saved );
	}
}

if ( ! function_exists( 'swipe_should_convert_to_webp' ) ) {
	/** @deprecated 1.0.0 Es gibt keine Laufzeit-Konvertierung mehr. */
	function swipe_should_convert_to_webp() {
		return false;
	}
}

if ( ! function_exists( 'swipe_responsive_image' ) ) {
/**
 * Generiert ein responsive <img> Tag mit srcset/sizes aus ACF Image Array
 *
 * @param array  $image       ACF Image Array (muss 'ID' enthalten)
 * @param string $size        WordPress Image Size (z.B. 'large', 'full')
 * @param array  $attr        Zusätzliche Attribute (class, loading, fetchpriority, etc.)
 * @param string $sizes       Sizes-Attribut (z.B. '(max-width: 768px) 100vw, 1200px')
 * @return string HTML img Tag
 */
function swipe_responsive_image($image, $size = 'large', $attr = [], $sizes = '100vw')
{
    if (empty($image)) {
        return '';
    }

    // Attachment ID ermitteln
    $attachment_id = 0;
    if (is_array($image) && isset($image['ID'])) {
        $attachment_id = $image['ID'];
    } elseif (is_array($image) && isset($image['id'])) {
        $attachment_id = $image['id'];
    } elseif (is_numeric($image)) {
        $attachment_id = (int) $image;
    }

    if (!$attachment_id) {
        // Fallback: Wenn keine ID, versuche URL-basiertes img
        $url = is_array($image) ? ($image['url'] ?? '') : '';
        $alt = is_array($image) ? ($image['alt'] ?? '') : '';
        if ($url) {
            $class = $attr['class'] ?? 'img-fluid';
            $loading = $attr['loading'] ?? 'lazy';
            return sprintf(
                '<img src="%s" alt="%s" class="%s" loading="%s" decoding="async">',
                esc_url($url),
                esc_attr($alt),
                esc_attr($class),
                esc_attr($loading)
            );
        }
        return '';
    }

    // Default attributes
    $default_attr = [
        'class'    => 'img-fluid',
        'loading'  => 'lazy',
        'decoding' => 'async',
        'sizes'    => $sizes,
    ];

    // Merge mit übergebenen Attributen
    $attr = wp_parse_args($attr, $default_attr);

    // Für LCP-Bilder: loading="eager" und fetchpriority="high"
    if (isset($attr['fetchpriority']) && $attr['fetchpriority'] === 'high') {
        $attr['loading'] = 'eager';
    }

    // wp_get_attachment_image generiert automatisch srcset
    // WebP-Konvertierung erfolgt über die bereits vorhandenen Filter
    return wp_get_attachment_image($attachment_id, $size, false, $attr);
}
}

if ( ! function_exists( 'swipe_get_image_srcset' ) ) {
/**
 * Generiert srcset-Attribut für ein ACF Image Array
 *
 * @param array  $image  ACF Image Array (muss 'ID' enthalten)
 * @param string $size   WordPress Image Size
 * @return string srcset-Wert oder leer
 */
function swipe_get_image_srcset($image, $size = 'large')
{
    $attachment_id = 0;
    if (is_array($image) && isset($image['ID'])) {
        $attachment_id = $image['ID'];
    } elseif (is_array($image) && isset($image['id'])) {
        $attachment_id = $image['id'];
    } elseif (is_numeric($image)) {
        $attachment_id = (int) $image;
    }

    if (!$attachment_id) {
        return '';
    }

    return wp_get_attachment_image_srcset($attachment_id, $size) ?: '';
}
}

if ( ! function_exists( 'swipe_get_image_dimensions' ) ) {
/**
 * Holt Bild-Dimensionen aus ACF Image Array oder Attachment
 *
 * @param array  $image  ACF Image Array
 * @param string $size   WordPress Image Size
 * @return array ['width' => int, 'height' => int]
 */
function swipe_get_image_dimensions($image, $size = 'full')
{
    $dimensions = ['width' => '', 'height' => ''];

    // Versuche aus ACF Array
    if (is_array($image)) {
        if (isset($image['width']) && isset($image['height'])) {
            $dimensions['width'] = $image['width'];
            $dimensions['height'] = $image['height'];
            return $dimensions;
        }

        // Versuche aus sizes Array
        if (isset($image['sizes'][$size . '-width']) && isset($image['sizes'][$size . '-height'])) {
            $dimensions['width'] = $image['sizes'][$size . '-width'];
            $dimensions['height'] = $image['sizes'][$size . '-height'];
            return $dimensions;
        }

        // Fallback auf Attachment Metadata
        $attachment_id = $image['ID'] ?? $image['id'] ?? 0;
        if ($attachment_id) {
            $meta = wp_get_attachment_metadata($attachment_id);
            if ($meta && isset($meta['width']) && isset($meta['height'])) {
                if ($size === 'full') {
                    $dimensions['width'] = $meta['width'];
                    $dimensions['height'] = $meta['height'];
                } elseif (isset($meta['sizes'][$size])) {
                    $dimensions['width'] = $meta['sizes'][$size]['width'];
                    $dimensions['height'] = $meta['sizes'][$size]['height'];
                }
            }
        }
    }

    return $dimensions;
}
}

if ( ! function_exists( 'swipe_get_image_sizes' ) ) {
/**
 * Generiert sizes-Attribut basierend auf Layout-Typ
 *
 * @param string $layout Layout-Typ
 * @return string sizes-Attribut Wert
 */
function swipe_get_image_sizes($layout = 'full')
{
    $sizes_map = [
        'full'        => '(max-width: 768px) 100vw, (max-width: 1200px) 100vw, 1920px',
        'hero'        => '(max-width: 768px) 100vw, (max-width: 1400px) 100vw, 1920px',
        'half'        => '(max-width: 768px) 100vw, (max-width: 992px) 50vw, 600px',
        'third'       => '(max-width: 768px) 100vw, (max-width: 992px) 50vw, (max-width: 1200px) 33vw, 400px',
        'quarter'     => '(max-width: 768px) 50vw, (max-width: 992px) 33vw, 25vw',
        'blog-teaser' => '(max-width: 768px) 100vw, (max-width: 992px) 50vw, 600px',
        'slider'      => '(max-width: 768px) 100vw, (max-width: 1200px) 80vw, 1000px',
        'product'     => '(max-width: 991px) 100vw, 50vw',
    ];

    $sizes_map = apply_filters( 'swipe_images_sizes_presets', $sizes_map );
    return $sizes_map[ $layout ] ?? $sizes_map['full'];
}
}

if ( ! function_exists( 'swipe_preload_responsive_image' ) ) {
/**
 * Gibt einen <link rel="preload" as="image"> aus, der das von
 * swipe_responsive_image() gerenderte Bild spiegelt (WebP + srcset).
 *
 * Das media-Attribut ist Pflicht: Das HTML liegt im Page-Cache und wird
 * geräteunabhängig ausgeliefert — nur der Browser darf entscheiden,
 * welche Variante er lädt.
 *
 * @param array|int   $image      ACF Image Array oder Attachment-ID
 * @param string      $size       WordPress Image Size
 * @param string      $media      Media Query (z. B. '(min-width: 992px)')
 * @param string|null $imagesizes sizes-Wert des gerenderten <img> (aktiviert imagesrcset)
 */
function swipe_preload_responsive_image($image, $size, $media, $imagesizes = null)
{
    $attachment_id = 0;
    if (is_array($image) && isset($image['ID'])) {
        $attachment_id = $image['ID'];
    } elseif (is_array($image) && isset($image['id'])) {
        $attachment_id = $image['id'];
    } elseif (is_numeric($image)) {
        $attachment_id = (int) $image;
    }
    if (!$attachment_id) {
        return;
    }

    $src = wp_get_attachment_image_url($attachment_id, $size);
    if (!$src) {
        return;
    }
    $srcset = wp_get_attachment_image_srcset($attachment_id, $size);

    $html = '<link rel="preload" as="image" href="' . esc_url($src) . '"';
    if ($srcset && $imagesizes) {
        $html .= ' imagesrcset="' . esc_attr($srcset) . '" imagesizes="' . esc_attr($imagesizes) . '"';
    }
    $html .= ' media="' . esc_attr($media) . '" fetchpriority="high">';
    echo $html;
}
}

if ( ! function_exists( 'swipe_aiarc_inherit_alt_text' ) ) {
/**
 * F-7: Alt-Text vom Original auf die aspect-ratio-Crop-Attachments übernehmen.
 *
 * Das Plugin acf-image-aspect-ratio-crop legt pro Zuschnitt ein eigenes Attachment an,
 * kopiert dabei aber den Alt-Text NICHT vom Quellbild – die Crops landen ohne `alt` im
 * Frontend. Der Crop speichert die Original-ID in der Meta
 * `acf_image_aspect_ratio_crop_original_image_id`; sobald diese gesetzt wird, übernehmen
 * wir den Alt-Text des Originals (nur, wenn der Crop noch keinen eigenen hat). WPML-tauglich,
 * da jedes sprachspezifische Original seine eigene ID + seinen eigenen Alt-Text besitzt.
 *
 * @param int    $meta_id    Meta-ID (ungenutzt).
 * @param int    $post_id    ID des Crop-Attachments.
 * @param string $meta_key   Meta-Key.
 * @param mixed  $meta_value Wert (= Original-Attachment-ID).
 *
 * @return void
 */
function swipe_aiarc_inherit_alt_text($meta_id, $post_id, $meta_key, $meta_value)
{
    if ($meta_key !== 'acf_image_aspect_ratio_crop_original_image_id') {
        return;
    }

    $original_id = (int) $meta_value;
    if (!$original_id) {
        return;
    }

    // Nur füllen, wenn der Crop noch keinen eigenen Alt-Text hat.
    $crop_alt = get_post_meta($post_id, '_wp_attachment_image_alt', true);
    if ($crop_alt !== '' && $crop_alt !== null) {
        return;
    }

    $original_alt = get_post_meta($original_id, '_wp_attachment_image_alt', true);
    if ($original_alt !== '' && $original_alt !== null) {
        update_post_meta($post_id, '_wp_attachment_image_alt', $original_alt);
    }
}
add_action('added_post_meta', 'swipe_aiarc_inherit_alt_text', 10, 4);
add_action('updated_post_meta', 'swipe_aiarc_inherit_alt_text', 10, 4);
}
