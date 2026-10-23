<?php
/**
 * Plugin Name: Souss Actualites - Ouverture de l'application
 * Description: Affiche l'animation d'ouverture (helicoptere PRESS + caisse en
 *              parachute) quand le site est ouvert depuis l'application Android
 *              ou l'icone installee sur l'ecran d'accueil. Les visiteurs venus
 *              de Google ou d'un navigateur ne la voient jamais.
 * Version:     1.0.0
 * Author:      Souss Actualites
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_head', function () {
	if ( is_admin() || wp_doing_ajax() ) {
		return;
	}
	$src = get_stylesheet_directory_uri() . '/assets/splash/index.html?v=2';
	?>
<script>
(function () {
	try {
		// The Android app (Trusted Web Activity) opens the site with this referrer;
		// an installed home-screen icon runs in standalone mode.
		var fromApp = document.referrer.indexOf('android-app://com.soussactualites.app') === 0;
		var standalone = window.matchMedia && window.matchMedia('(display-mode: standalone)').matches;
		var forced = /[?&]app=1(&|$)/.test(location.search);
		if (!(fromApp || standalone || forced)) { return; }
		try {
			if (sessionStorage.getItem('saSplashSeen')) { return; }
			sessionStorage.setItem('saSplashSeen', '1');
		} catch (e) {}
		var f = document.createElement('iframe');
		f.src = <?php echo wp_json_encode( esc_url_raw( $src ) ); ?>;
		f.title = 'Souss Actualités';
		f.setAttribute('aria-hidden', 'true');
		// The theme sizes iframes; force a full-screen overlay.
		var css = { position: 'fixed', top: '0', left: '0', right: '0', bottom: '0', width: '100vw', height: '100vh', 'max-width': 'none', 'max-height': 'none', 'min-height': '100vh', 'aspect-ratio': 'auto', border: '0', margin: '0', padding: '0', 'z-index': '2147483647', background: '#0c0f14', display: 'block' };
		for (var k in css) { f.style.setProperty(k, css[k], 'important'); }
		var removed = false;
		function done() {
			if (removed) { return; }
			removed = true;
			if (f.parentNode) { f.parentNode.removeChild(f); }
		}
		window.addEventListener('message', function (e) {
			if (e.origin !== location.origin || !e.data) { return; }
			if (e.data.souss === 'splash-reveal') { f.style.setProperty('background', 'transparent', 'important'); }
			if (e.data.souss === 'splash-done') { done(); }
		});
		setTimeout(done, 24000);
		document.documentElement.appendChild(f);
	} catch (e) {}
})();
</script>
	<?php
}, 1 );
