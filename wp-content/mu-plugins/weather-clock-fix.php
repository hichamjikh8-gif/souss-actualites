<?php
/**
 * Plugin Name:  Masquage complet horloge + meteo d'en-tete
 * Description:  Le directeur souhaite ne PLUS afficher ni l'heure ni la
 *               temperature dans l'en-tete (aucune page). Ce plugin masque
 *               donc systematiquement le bloc horloge/meteo servi par
 *               l'en-tete, quelle que soit la page. Aucune API meteore n'est
 *               appelee, aucun icone, aucune valeur n'est affichee : le bloc
 *               est simplement retire de l'affichage. Filet de securite : tout
 *               element residuel contenant des tirets "--" est egalement
 *               masque, afin qu'aucun visiteur ne voie jamais "--:--:--" ou
 *               "--C" en production.
 * Author:       Souss Actualites
 * Version:      2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* Masque le widget horloge/meteo en CSS (deja cache avant tout rendu paint). */
add_action( 'wp_head', function () {
	if ( is_admin() ) {
		return;
	}
	$css = '
#souss-clock-weather,
.souss-clock-weather,
[class*="clock-weather"],
[id*="clock-weather"],
#souss-clock, .souss-clock,
#souss-weather, .souss-weather,
#souss-weather-icon, .souss-weather-icon,
[class*="souss-clock"], [class*="souss-weather"] {
	display: none !important;
	visibility: hidden !important;
}
';
	echo '<style id="sa-hide-clock-weather-css">' . $css . '</style>' . "\n";
} );

/* Filet de securite cote visiteur : masque toute cellule residuelle avec des
 * tirets "--" (horloge ou meteo) qui aurait pu echapper au CSS (widget image,
 * conteneur exotique, injection en base). Aucune API appelee. */
add_action( 'wp_footer', function () {
	if ( is_admin() ) {
		return;
	}
	?><script>
(function () {
	"use strict";
	function sweep() {
		var all = document.querySelectorAll("*");
		Array.prototype.forEach.call(all, function (el) {
			if (el.childElementCount > 0) { return; }
			var txt = (el.textContent || "").replace(/\s+/g, " ").trim();
			if (/^--\s*:/.test(txt) || /^--\s*(\u00B0C|C)/.test(txt) || /--\s*(\u00B0C|C)/.test(txt)) {
				var w = el.closest("[class*="+"clock-weather"+"]") || el.closest("[id*="+"souss-clock"+"]") ||
				        el.closest("[class*="+"souss-clock"+"]") || el.parentElement;
				if (w) { w.style.display = "none"; }
			}
		});
	}
	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", function () {
			window.setTimeout(sweep, 2000);
			window.setInterval(sweep, 10000);
		});
	} else {
		window.setTimeout(sweep, 2000);
		window.setInterval(sweep, 10000);
	}
})();
</script>
<?php
} );
