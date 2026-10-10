( function () {
	'use strict';

	var HIT_TOLERANCE_RATIO = 0.045; // % du plus petit côté de l'image affichée.

	function onReady( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	function initGame( container ) {
		var dataEl = container.querySelector( '.sgtd-game-data' );
		var data;
		try {
			data = JSON.parse( dataEl.textContent );
		} catch ( err ) {
			return;
		}

		var differences = data.differences || [];
		var total = data.total || differences.length;
		var found = new Set();

		var wraps      = container.querySelectorAll( '.sgtd-image-wrap' );
		var foundLabel = container.querySelector( '.sgtd-found' );
		var feedback   = container.querySelector( '.sgtd-feedback' );
		var victory    = container.querySelector( '.sgtd-victory' );
		var replayBtn  = container.querySelector( '.sgtd-replay' );
		var hintBox    = container.querySelector( '.sgtd-hint-checkbox' );

		function placeMarker( x, y, className ) {
			wraps.forEach( function ( wrap ) {
				var layer = wrap.querySelector( '.sgtd-marker-layer' );
				var marker = document.createElement( 'div' );
				marker.className = className;
				marker.style.left = x + '%';
				marker.style.top = y + '%';
				layer.appendChild( marker );
			} );
		}

		function clearMarkers( className ) {
			container.querySelectorAll( '.' + className ).forEach( function ( el ) {
				el.remove();
			} );
		}

		function updateHint() {
			clearMarkers( 'sgtd-hint-marker' );
			if ( ! hintBox.checked ) {
				return;
			}
			var remaining = differences
				.map( function ( d, i ) { return i; } )
				.filter( function ( i ) { return ! found.has( i ); } );
			if ( ! remaining.length ) {
				return;
			}
			var pick = differences[ remaining[ 0 ] ];
			placeMarker( pick.x, pick.y, 'sgtd-hint-marker' );
		}

		function handleHit( clientX, clientY, imgEl ) {
			var rect = imgEl.getBoundingClientRect();
			if ( clientX < rect.left || clientX > rect.right || clientY < rect.top || clientY > rect.bottom ) {
				return;
			}

			var xPercent = ( ( clientX - rect.left ) / rect.width ) * 100;
			var yPercent = ( ( clientY - rect.top ) / rect.height ) * 100;
			var toleranceRatio = HIT_TOLERANCE_RATIO * Math.min( rect.width, rect.height );

			var bestIndex = -1;
			var bestDistance = Infinity;

			differences.forEach( function ( diff, index ) {
				var dx = ( ( diff.x - xPercent ) / 100 ) * rect.width;
				var dy = ( ( diff.y - yPercent ) / 100 ) * rect.height;
				var distance = Math.sqrt( dx * dx + dy * dy );
				if ( distance < bestDistance ) {
					bestDistance = distance;
					bestIndex = index;
				}
			} );

			if ( bestIndex === -1 || bestDistance > toleranceRatio ) {
				feedback.textContent = sgtdGameI18n.incorrect;
				feedback.className = 'sgtd-feedback sgtd-feedback-wrong';
				return;
			}

			if ( found.has( bestIndex ) ) {
				feedback.textContent = sgtdGameI18n.alreadyFound;
				feedback.className = 'sgtd-feedback';
				return;
			}

			found.add( bestIndex );
			var diff = differences[ bestIndex ];
			placeMarker( diff.x, diff.y, 'sgtd-found-marker' );
			foundLabel.textContent = found.size;
			feedback.textContent = sgtdGameI18n.correct;
			feedback.className = 'sgtd-feedback sgtd-feedback-correct';
			updateHint();

			if ( found.size >= total ) {
				victory.hidden = false;
			}
		}

		wraps.forEach( function ( wrap ) {
			var img = wrap.querySelector( '.sgtd-img' );
			img.addEventListener( 'click', function ( e ) {
				handleHit( e.clientX, e.clientY, img );
			} );
		} );

		hintBox.addEventListener( 'change', updateHint );

		replayBtn.addEventListener( 'click', function () {
			found.clear();
			clearMarkers( 'sgtd-found-marker' );
			clearMarkers( 'sgtd-hint-marker' );
			foundLabel.textContent = '0';
			feedback.textContent = '';
			feedback.className = 'sgtd-feedback';
			victory.hidden = true;
			hintBox.checked = false;
		} );
	}

	onReady( function () {
		document.querySelectorAll( '.sgtd-game' ).forEach( initGame );
	} );
} )();
