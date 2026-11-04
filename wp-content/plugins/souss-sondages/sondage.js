(function () {
	'use strict';
	var API = (window.SSOND && window.SSOND.api) || '/wp-json/souss-sondages/v1/';
	var LS = 'ssond_votes';

	function store(id, key) {
		try {
			var d = JSON.parse(localStorage.getItem(LS) || '{}');
			if (key) { d[id] = key; localStorage.setItem(LS, JSON.stringify(d)); }
			return d[id] || null;
		} catch (e) { return null; }
	}

	function fmt(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }

	function show(el, s) {
		var total = s.total || 0, max = 0, opts = el.querySelectorAll('.ssond__opt');
		Object.keys(s.counts || {}).forEach(function (k) { if (s.counts[k] > max) max = s.counts[k]; });
		el.classList.add('is-results');
		opts.forEach(function (li) {
			var btn = li.querySelector('.ssond__btn'), k = btn.getAttribute('data-key');
			var c = (s.counts && s.counts[k]) || 0, p = total ? Math.round(c * 100 / total) : 0;
			li.classList.toggle('is-mine', s.voted === k);
			li.classList.toggle('is-lead', total > 0 && c === max);
			btn.setAttribute('aria-label', btn.textContent.trim() + ' : ' + p + ' %');
			btn.setAttribute('aria-disabled', 'true');
			var pct = li.querySelector('.ssond__pct'), fill = li.querySelector('.ssond__fill');
			requestAnimationFrame(function () {
				setTimeout(function () { fill.style.width = p + '%'; }, 60);
			});
			var start = null, from = parseInt(pct.getAttribute('data-v') || '0', 10);
			pct.setAttribute('data-v', p);
			(function tick(t) {
				if (!start) start = t;
				var r = Math.min(1, (t - start) / 1000), e = 1 - Math.pow(1 - r, 3);
				pct.textContent = Math.round(from + (p - from) * e) + ' %';
				if (r < 1) requestAnimationFrame(tick);
			})(performance.now());
		});
		var tot = el.querySelector('.ssond__total');
		tot.innerHTML = '<b>' + fmt(total) + '</b> vote' + (total > 1 ? 's' : '') + (s.voted ? ' · Merci pour votre vote' : (s.closed ? ' · Sondage clôturé' : ''));
	}

	function toast(el, msg) {
		var t = el.querySelector('.ssond__toast');
		if (!t) { t = document.createElement('div'); t.className = 'ssond__toast'; el.appendChild(t); }
		t.textContent = msg; t.classList.add('is-on');
		clearTimeout(t._h); t._h = setTimeout(function () { t.classList.remove('is-on'); }, 2200);
	}

	function init(el) {
		var id = el.getAttribute('data-poll');
		var closed = el.getAttribute('data-closed') === '1';
		var before = el.getAttribute('data-before') === '1';
		var local = store(id);

		fetch(API + 'poll/' + id, { credentials: 'same-origin', cache: 'no-store' })
			.then(function (r) { return r.json(); })
			.then(function (s) {
				if (!s || !s.counts) return;
				if (!s.voted && local) s.voted = local;
				if (s.voted || s.closed || closed || before) show(el, s);
				if (before && !s.voted && !s.closed) el.classList.add('is-preview');
			}).catch(function () {});

		el.querySelectorAll('.ssond__btn').forEach(function (btn) {
			btn.addEventListener('click', function () {
				if (el.classList.contains('is-results') && !el.classList.contains('is-preview')) return;
				var key = btn.getAttribute('data-key');
				el.classList.add('is-loading');
				fetch(API + 'poll/' + id + '/vote', {
					method: 'POST', credentials: 'same-origin', cache: 'no-store',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({ option: key })
				}).then(function (r) { return r.json(); }).then(function (s) {
					el.classList.remove('is-loading', 'is-preview');
					if (s && s.counts) {
						s.voted = s.voted || key; store(id, s.voted); show(el, s);
						if (s.voted !== key) toast(el, 'Vous avez déjà voté');
					} else { toast(el, (s && s.message) || 'Vote impossible, réessayez'); }
				}).catch(function () { el.classList.remove('is-loading'); toast(el, 'Connexion impossible, réessayez'); });
			});
		});

		var link = el.querySelector('.ssond__sh--link');
		if (link) link.addEventListener('click', function () {
			var u = link.getAttribute('data-url');
			var done = function () { link.classList.add('is-ok'); toast(el, 'Lien copié'); setTimeout(function () { link.classList.remove('is-ok'); }, 1500); };
			if (navigator.share) { navigator.share({ title: document.title, url: u }).catch(function () {}); return; }
			if (navigator.clipboard) navigator.clipboard.writeText(u).then(done, done); else done();
		});
	}

	function boot() { document.querySelectorAll('.ssond[data-poll]').forEach(init); }
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
