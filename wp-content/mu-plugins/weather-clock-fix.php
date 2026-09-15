<?php
/**
 * Plugin Name:  Correction horloge + meteo d'en-tete (Agadir / Souss)
 * Description:  Remplace les placeholders "--:--:--" et "--°C" du widget
 *               d'en-tete par l'heure reelle (fuseau Africa/Casablanca) et la
 *               meteo reelle d'Agadir via api.open-meteo.com (gratuite, sans
 *               cle). Filet de securite : si l'API meteo echoue ou si des
 *               tirets restent visibles, le widget entier est masque -- jamais
 *               de valeur "--" affichee au visiteur.
 * Author:       Souss Actualites
 * Version:      1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_footer', function () {
	if ( is_admin() ) {
		return;
	}
	?><script>
(function () {
	"use strict";
	var CLOCK_ID  = "souss-clock";
	var WEATHER_ID = "souss-weather";
	var ICON_ID   = "souss-weather-icon";
	/* Conteneur: le widget servi par l'en-tete (theme) utilise
	 * #souss-clock-weather / .souss-clock-weather. On couvre les deux cas. */
	var WIDGET_SELECTOR = "#souss-clock-weather, .souss-clock-weather, [id*="clock-weather"], [class*="clock-weather"]";
	var AGADIR = { lat: 30.4278, lon: -9.5981 };

	function hideWidget() {
		var targets = document.querySelectorAll(WIDGET_SELECTOR);
		if (targets.length) {
			targets.forEach(function (w) { w.style.display = "none"; });
		}
	}

	function setClock() {
		var el = document.getElementById(CLOCK_ID);
		if (!el) { return; }
		try {
			var now  = new Date();
			var parts = new Intl.DateTimeFormat("fr-FR", {
				timeZone: "Africa/Casablanca",
				hour: "2-digit", minute: "2-digit", second: "2-digit",
				hour12: false
			}).formatToParts(now);
			var m = {};
			parts.forEach(function (p) { m[p.type] = p.value; });
			el.textContent = m.hour + ":" + m.minute + ":" + m.second;
		} catch (e) {
			/* Plutot cacher que d'afficher des tirets. */
			hideWidget();
		}
	}

	function weatherIcon(code) {
		if (code === 0)            { return "&#9728;"; }
		if (code === 1 || code === 2) { return "&#9925;"; }
		if (code === 3)            { return "&#9729;"; }
		if (code >= 45 && code < 60) { return "&#127787;"; }
		if (code >= 61 && code < 70) { return "&#127783;"; }
		if (code >= 71 && code < 80) { return "&#127784;"; }
		return "&#9728;";
	}

	function setWeather() {
		var el = document.getElementById(WEATHER_ID);
		if (!el) { return; }
		fetch("https://api.open-meteo.com/v1/forecast?latitude=" + AGADIR.lat +
				"&longitude=" + AGADIR.lon + "&current_weather=true")
			.then(function (r) { if (!r.ok) { throw new Error("http " + r.status); } return r.json(); })
			.then(function (data) {
				var t = data && data.current_weather && data.current_weather.temperature;
				if (typeof t !== "number") { throw new Error("pas de temperature"); }
				el.textContent = "Agadir " + Math.round(t) + " \u00B0C";
				var icon = document.getElementById(ICON_ID);
				if (icon) { icon.textContent = weatherIcon(data.current_weather.weathercode); }
			})
			.catch(function () {
				/* L'API ou le CORS a echoue : on masque, jamais de tirets. */
				hideWidget();
			});
	}

	/* Filet de securite : plus aucun element ne doit afficher de tirets. */
	function sweepDashes() {
		var nodes = document.querySelectorAll("#" + CLOCK_ID + ", #" + WEATHER_ID + ", span, div");
		nodes.forEach(function (el) {
			if (el.childElementCount > 0) { return; }
			var txt = (el.textContent || "").replace(/\s+/g, " ").trim();
			if (/^--:--:--/.test(txt) || /^--\s*(\u00B0C|C)/.test(txt) || /--\s*(\u00B0C|C)/.test(txt)) {
				hideWidget();
			}
		});
	}

	function init() {
		setClock();
		setWeather();
		setInterval(setClock, 1000);
		window.setTimeout(sweepDashes, 4000);
		window.setInterval(sweepDashes, 15000);
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", init);
	} else {
		init();
	}
})();
</script>
<?php
}, 99 );
