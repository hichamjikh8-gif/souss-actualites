( function () {
	'use strict';

	function onReady( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	function setupImagePicker( picker ) {
		var role      = picker.getAttribute( 'data-role' );
		var input     = picker.querySelector( '.sgtd-image-input' );
		var preview   = picker.querySelector( '.sgtd-image-preview' );
		var chooseBtn = picker.querySelector( '.sgtd-choose-image' );
		var removeBtn = picker.querySelector( '.sgtd-remove-image' );
		var frame     = null;

		chooseBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();

			if ( frame ) {
				frame.open();
				return;
			}

			frame = wp.media( {
				title: role === 'a' ? sgtdAdminData.i18n.chooseA : sgtdAdminData.i18n.chooseB,
				multiple: false,
				library: { type: 'image' },
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				input.value = attachment.id;
				var imgUrl = ( attachment.sizes && attachment.sizes.medium ) ? attachment.sizes.medium.url : attachment.url;
				preview.innerHTML = '<img src="' + imgUrl + '" />';
				removeBtn.style.display = '';
			} );

			frame.open();
		} );

		removeBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			input.value = '';
			preview.innerHTML = '';
			removeBtn.style.display = 'none';
		} );
	}

	function setupDifferencesEditor( wrap ) {
		var imageBox   = wrap.querySelector( '.sgtd-editor-image' );
		var img        = wrap.querySelector( '.sgtd-editor-img' );
		var layer      = wrap.querySelector( '.sgtd-marker-layer' );
		var counter    = wrap.querySelector( '.sgtd-count' );
		var feedback   = wrap.querySelector( '.sgtd-feedback' );
		var textarea   = document.getElementById( 'sgtd_differences_data' );
		var minDistance = parseFloat( sgtdAdminData.minDistance ) || 3;

		var points = [];
		try {
			var saved = JSON.parse( textarea.value || '[]' );
			if ( Array.isArray( saved ) ) {
				points = saved;
			}
		} catch ( err ) {
			points = [];
		}

		function sync() {
			textarea.value = JSON.stringify( points );
			counter.textContent = points.length;
		}

		function renderMarkers() {
			layer.innerHTML = '';
			points.forEach( function ( point, index ) {
				var marker = document.createElement( 'div' );
				marker.className = 'sgtd-marker';
				marker.style.left = point.x + '%';
				marker.style.top = point.y + '%';
				marker.textContent = index + 1;
				marker.setAttribute( 'title', 'Retirer ce repère' );
				marker.addEventListener( 'click', function ( e ) {
					e.stopPropagation();
					points.splice( index, 1 );
					sync();
					renderMarkers();
				} );
				layer.appendChild( marker );
			} );
		}

		function tooClose( x, y ) {
			return points.some( function ( p ) {
				var d = Math.sqrt( Math.pow( x - p.x, 2 ) + Math.pow( y - p.y, 2 ) );
				return d < minDistance;
			} );
		}

		img.addEventListener( 'click', function ( e ) {
			var rect = img.getBoundingClientRect();
			var x = ( ( e.clientX - rect.left ) / rect.width ) * 100;
			var y = ( ( e.clientY - rect.top ) / rect.height ) * 100;
			x = Math.max( 0, Math.min( 100, x ) );
			y = Math.max( 0, Math.min( 100, y ) );

			if ( tooClose( x, y ) ) {
				feedback.textContent = sgtdAdminData.i18n.tooClose;
				return;
			}

			feedback.textContent = '';
			points.push( { x: Math.round( x * 100 ) / 100, y: Math.round( y * 100 ) / 100 } );
			sync();
			renderMarkers();
		} );

		sync();
		renderMarkers();
	}

	onReady( function () {
		document.querySelectorAll( '.sgtd-image-picker' ).forEach( setupImagePicker );

		var differencesWrap = document.querySelector( '.sgtd-editor-wrap' );
		if ( differencesWrap ) {
			setupDifferencesEditor( differencesWrap );
		}
	} );
} )();
