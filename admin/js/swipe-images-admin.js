/* global jQuery, swipeImages */
jQuery(function ($) {
	// Regler und Zahlenfeld gekoppelt.
	$('.swipe-images-fields input[type="range"]').each(function () {
		var $range = $(this), $num = $('#' + $range.data('target'));
		$range.on('input', function () { $num.val($range.val()); });
		$num.on('input', function () { $range.val($num.val()); });
	});

	// Vorschau über die Mediathek.
	var frame;
	$('#swipe-images-pick').on('click', function (e) {
		e.preventDefault();
		if (!frame) {
			frame = wp.media({ title: 'Bild für die Vorschau', multiple: false, library: { type: ['image/jpeg', 'image/png', 'image/webp', 'image/avif'] } });
			frame.on('select', function () {
				var att = frame.state().get('selection').first().toJSON();
				var isAvif = $('.swipe-images-fields input[name$="[format]"]:checked').val() === 'avif';
				var q = parseInt($('#swipe-images-' + (isAvif ? 'qa' : 'qw') + '-n').val(), 10);
				var $box = $('#swipe-images-preview').html('<p>Rechne…</p>');
				$.post(swipeImages.ajaxUrl, { action: 'swipe_images_preview', nonce: swipeImages.previewNonce, attachment_id: att.id, quality: q }, function (r) {
					if (!r.success) { $box.html('<p class="swipe-images-warn">' + r.data + '</p>'); return; }
					$box.empty();
					r.data.forEach(function (v) {
						$box.append('<figure><img src="' + v.url + '" alt=""><figcaption>Qualität ' + v.quality + ' · ' + v.size + '</figcaption></figure>');
					});
				}).fail(function () { $box.html('<p class="swipe-images-warn">Vorschau fehlgeschlagen.</p>'); });
			});
		}
		frame.open();
	});

	// Bestand regenerieren in Batches à fünf Bilder.
	$('#swipe-images-regen').on('click', function () {
		var $btn = $(this).prop('disabled', true);
		var total = parseInt($btn.data('pending'), 10) || 0, done = 0, errors = 0;
		var $bar = $('#swipe-images-bar').prop('hidden', false), $fill = $bar.find('span'), $log = $('#swipe-images-log');
		function step() {
			$.post(swipeImages.ajaxUrl, { action: 'swipe_images_regenerate', nonce: swipeImages.nonce }, function (r) {
				if (!r.success) { $log.html('<span class="swipe-images-warn">' + r.data + '</span>'); $btn.prop('disabled', false); return; }
				done += r.data.done; errors += r.data.errors;
				var pct = total ? Math.min(100, Math.round((done + errors) / total * 100)) : 100;
				$fill.css('width', pct + '%').text(pct + '%');
				$('#swipe-images-pending').text(r.data.pending);
				$log.text(done + ' regeneriert' + (errors ? ', ' + errors + ' Fehler (siehe Status nach dem Neuladen)' : ''));
				if (r.data.has_more) { step(); } else { $fill.css('width', '100%').text('100%'); $btn.prop('disabled', false); }
			}).fail(function () { $log.html('<span class="swipe-images-warn">Netzwerkfehler, bitte erneut starten.</span>'); $btn.prop('disabled', false); });
		}
		step();
	});
});
