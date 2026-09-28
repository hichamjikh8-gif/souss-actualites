(function () {
	'use strict';

	var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var canTilt = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches && !reduceMotion;

	function dismissKey(el) {
		return 'soussAdDismissed:' + (el.dataset.adId || 'floating');
	}

	function initDismiss(root) {
		var floating = root.querySelectorAll('.souss-ad--floating');
		floating.forEach(function (el) {
			try {
				if (sessionStorage.getItem(dismissKey(el)) === '1') {
					el.classList.add('is-dismissed');
					return;
				}
			} catch (e) {}

			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'souss-ad__dismiss';
			btn.setAttribute('aria-label', 'Fermer');
			btn.textContent = '×';
			btn.addEventListener('click', function (evt) {
				evt.preventDefault();
				evt.stopPropagation();
				el.classList.add('is-dismissed');
				try {
					sessionStorage.setItem(dismissKey(el), '1');
				} catch (e) {}
			});
			el.appendChild(btn);
		});
	}

	function initReveal(root) {
		var inners = root.querySelectorAll('.souss-ad__inner');
		if (!('IntersectionObserver' in window) || reduceMotion) {
			inners.forEach(function (el) {
				el.classList.add('is-visible');
			});
			return;
		}
		var observer = new IntersectionObserver(
			function (entries) {
				entries.forEach(function (entry) {
					if (entry.isIntersecting) {
						entry.target.classList.add('is-visible');
						observer.unobserve(entry.target);
					}
				});
			},
			{ threshold: 0.25 }
		);
		inners.forEach(function (el) {
			observer.observe(el);
		});
	}

	function initTilt(root) {
		if (!canTilt) {
			return;
		}
		var cards = root.querySelectorAll('.souss-ad__inner');
		cards.forEach(function (card) {
			card.addEventListener('mousemove', function (evt) {
				var rect = card.getBoundingClientRect();
				var x = (evt.clientX - rect.left) / rect.width - 0.5;
				var y = (evt.clientY - rect.top) / rect.height - 0.5;
				var rotateY = x * 10;
				var rotateX = y * -10;
				card.style.transform =
					'translateY(0) scale(1.02) rotateX(' + rotateX.toFixed(2) + 'deg) rotateY(' + rotateY.toFixed(2) + 'deg)';
			});
			card.addEventListener('mouseleave', function () {
				card.style.transform = '';
			});
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		initReveal(document);
		initTilt(document);
		initDismiss(document);
	});
})();
