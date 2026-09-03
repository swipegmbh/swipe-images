/* global jQuery, swipeImages */
jQuery(function ($) {
	// Regler und Zahlenfeld gekoppelt.
	$('.swipe-images-fields input[type="range"]').each(function () {
		var $range = $(this), $num = $('#' + $range.data('target'));
		$range.on('input', function () { $num.val($range.val()); });
		$num.on('input', function () { $range.val($num.val()); });
	});

	// --- TASK8 ---
});
