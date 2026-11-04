jQuery(function ($) {
	var $rows = $('#ssond-rows');
	if (!$rows.length) return;
	var tmpl = $('#tmpl-ssond-row').html();

	$('#ssond-add').on('click', function () { $rows.append(tmpl); });

	$rows.on('click', '.ssond-remove', function () {
		if ($rows.find('.ssond-row').length <= 2) { alert('Un sondage doit avoir au moins 2 réponses.'); return; }
		$(this).closest('.ssond-row').remove();
	});

	$rows.on('click', '.ssond-unpick', function () {
		var $r = $(this).closest('.ssond-row');
		$r.find('.ssond-image-id').val('0');
		$r.find('.ssond-thumb').css('background-image', '').html('<span class="dashicons dashicons-format-image"></span>');
	});

	$rows.on('click', '.ssond-pick, .ssond-thumb', function () {
		var $r = $(this).closest('.ssond-row');
		var frame = wp.media({ title: 'Choisir une photo', button: { text: 'Utiliser cette photo' }, library: { type: 'image' }, multiple: false });
		frame.on('select', function () {
			var a = frame.state().get('selection').first().toJSON();
			var url = (a.sizes && a.sizes.thumbnail) ? a.sizes.thumbnail.url : a.url;
			$r.find('.ssond-image-id').val(a.id);
			$r.find('.ssond-thumb').css('background-image', 'url(' + url + ')').html('');
		});
		frame.open();
	});
});
