/*!
 * AST Motion — motore di animazione a classi (GSAP + ScrollTrigger + SplitText + Lenis).
 * Ruoli: reveal, stagger, split-text, parallax, pin, hscroll, counter, rule.
 */
(function () {
	'use strict';

	var cfg = window.AST_MOTION || {};
	var html = document.documentElement;

	function release() {
		html.classList.remove('ast-motion');
	}

	if (!window.gsap || !window.ScrollTrigger || !cfg.enabled) {
		release();
		return;
	}
	if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
		release();
		return;
	}

	var gsap = window.gsap;
	var ScrollTrigger = window.ScrollTrigger;
	gsap.registerPlugin(ScrollTrigger);
	var hasSplit = !!window.SplitText;
	if (hasSplit) {
		gsap.registerPlugin(window.SplitText);
	}

	var EASE = cfg.ease || 'power3.out';
	var DIST = typeof cfg.distance === 'number' ? cfg.distance : 48;
	var DUR = cfg.duration || 1;

	function sel(role) {
		return (cfg.selectors && cfg.selectors[role]) || ('.ast-' + role + ', [data-ast~="' + role + '"]');
	}

	function all(role) {
		return Array.prototype.slice.call(document.querySelectorAll(sel(role)));
	}

	function num(el, attr, def) {
		var v = parseFloat(el.getAttribute('data-ast-' + attr));
		return isNaN(v) ? def : v;
	}

	/* ---------- Lenis ---------- */

	var lenis = null;
	var coarse = window.matchMedia('(pointer: coarse)').matches;
	if (cfg.lenis && window.Lenis && (!coarse || cfg.lenisTouch)) {
		lenis = new window.Lenis({
			duration: cfg.lenisDuration || 1.15,
			easing: function (t) {
				return Math.min(1, 1.001 - Math.pow(2, -10 * t));
			},
			autoRaf: false
		});
		lenis.on('scroll', ScrollTrigger.update);
		gsap.ticker.add(function (time) {
			lenis.raf(time * 1000);
		});
		gsap.ticker.lagSmoothing(0);

		document.addEventListener('click', function (e) {
			var a = e.target && e.target.closest ? e.target.closest('a[href*="#"]') : null;
			if (!a || a.target === '_blank') {
				return;
			}
			var url;
			try {
				url = new URL(a.href, window.location.href);
			} catch (err) {
				return;
			}
			if (url.origin !== window.location.origin || url.pathname !== window.location.pathname || !url.hash || url.hash === '#') {
				return;
			}
			var target = null;
			try {
				target = document.querySelector(decodeURIComponent(url.hash));
			} catch (err2) {
				target = null;
			}
			if (!target) {
				return;
			}
			e.preventDefault();
			lenis.scrollTo(target, { offset: -(cfg.anchorOffset || 0) });
			if (window.history && window.history.pushState) {
				window.history.pushState(null, '', url.hash);
			}
		});
	}

	/* ---------- ruoli ---------- */

	function reveal() {
		all('reveal').forEach(function (el) {
			gsap.fromTo(el,
				{ autoAlpha: 0, y: num(el, 'y', DIST) },
				{
					autoAlpha: 1,
					y: 0,
					duration: num(el, 'duration', DUR),
					delay: num(el, 'delay', 0),
					ease: EASE,
					scrollTrigger: { trigger: el, start: 'top 88%', once: true }
				});
		});
	}

	function stagger() {
		all('stagger').forEach(function (el) {
			var kids = Array.prototype.slice.call(el.children);
			if (!kids.length) {
				return;
			}
			gsap.fromTo(kids,
				{ autoAlpha: 0, y: num(el, 'y', DIST * 0.6) },
				{
					autoAlpha: 1,
					y: 0,
					duration: num(el, 'duration', DUR * 0.9),
					delay: num(el, 'delay', 0),
					ease: EASE,
					stagger: num(el, 'stagger', 0.09),
					scrollTrigger: { trigger: el, start: 'top 85%', once: true }
				});
		});
	}

	function splitText() {
		all('split-text').forEach(function (el) {
			if (!hasSplit) {
				gsap.set(el, { autoAlpha: 1 });
				return;
			}
			gsap.set(el, { autoAlpha: 0 });
			window.SplitText.create(el, {
				type: 'lines',
				mask: 'lines',
				linesClass: 'ast-line',
				autoSplit: true,
				onSplit: function (self) {
					gsap.set(el, { autoAlpha: 1 });
					return gsap.from(self.lines, {
						yPercent: 110,
						duration: num(el, 'duration', 1.1),
						delay: num(el, 'delay', 0),
						ease: 'expo.out',
						stagger: num(el, 'stagger', 0.09),
						scrollTrigger: { trigger: el, start: 'top 88%', once: true }
					});
				}
			});
		});
	}

	function parallax() {
		all('parallax').forEach(function (el) {
			var s = num(el, 'speed', 10);
			gsap.fromTo(el, { yPercent: -s }, {
				yPercent: s,
				ease: 'none',
				scrollTrigger: { trigger: el.parentElement || el, start: 'top bottom', end: 'bottom top', scrub: true }
			});
		});
	}

	function rule() {
		all('rule').forEach(function (el) {
			gsap.fromTo(el, { scaleX: 0, transformOrigin: 'left center' }, {
				scaleX: 1,
				duration: num(el, 'duration', 1.2),
				delay: num(el, 'delay', 0),
				ease: 'expo.out',
				scrollTrigger: { trigger: el, start: 'top 92%', once: true }
			});
		});
	}

	function numberNode(root) {
		var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
		var n;
		while ((n = walker.nextNode())) {
			if (/\d/.test(n.nodeValue)) {
				return n;
			}
		}
		return null;
	}

	function counter() {
		all('counter').forEach(function (el) {
			var node = numberNode(el);
			if (!node) {
				return;
			}
			var raw = node.nodeValue;
			var m = raw.match(/\d[\d.,]*/);
			if (!m) {
				return;
			}
			var str = m[0].replace(/[.,]$/, '');
			var dec = str.indexOf(',') > -1 ? str.split(',')[1].length : 0;
			var grouped = /\d\.\d{3}/.test(str);
			var value = parseFloat(str.replace(/\./g, '').replace(',', '.'));
			if (isNaN(value)) {
				return;
			}
			var fmt = new Intl.NumberFormat('it-IT', {
				minimumFractionDigits: dec,
				maximumFractionDigits: dec,
				useGrouping: grouped
			});
			var obj = { v: 0 };
			node.nodeValue = raw.replace(str, fmt.format(0));
			gsap.to(obj, {
				v: value,
				duration: num(el, 'duration', 2),
				ease: 'power2.out',
				scrollTrigger: { trigger: el, start: 'top 90%', once: true },
				onUpdate: function () {
					node.nodeValue = raw.replace(str, fmt.format(obj.v));
				}
			});
		});
	}

	function desktopOnly() {
		var mm = gsap.matchMedia();
		mm.add('(min-width: 1025px)', function () {
			var off = cfg.pinOffset || 0;
			all('pin').forEach(function (el) {
				var parent = el.parentElement;
				if (!parent) {
					return;
				}
				ScrollTrigger.create({
					trigger: parent,
					start: 'top top+=' + off,
					end: function () {
						return 'bottom top+=' + (off + el.offsetHeight);
					},
					pin: el,
					pinSpacing: false,
					invalidateOnRefresh: true
				});
			});
			all('hscroll').forEach(function (el) {
				var track = el.firstElementChild;
				if (!track) {
					return;
				}
				var dist = function () {
					return Math.max(0, track.scrollWidth - el.clientWidth);
				};
				gsap.to(track, {
					x: function () {
						return -dist();
					},
					ease: 'none',
					scrollTrigger: {
						trigger: el,
						start: 'top top',
						end: function () {
							return '+=' + dist();
						},
						pin: true,
						scrub: 1,
						invalidateOnRefresh: true
					}
				});
			});
		});
	}

	function run() {
		try {
			reveal();
			stagger();
			splitText();
			parallax();
			rule();
			counter();
			desktopOnly();
		} catch (e) {
			if (window.console) {
				window.console.error('[AST Motion]', e);
			}
		}
		html.classList.add('ast-motion-on');
		release();
		window.addEventListener('load', function () {
			ScrollTrigger.refresh();
		});
	}

	window.AST = {
		lenis: lenis,
		gsap: gsap,
		ScrollTrigger: ScrollTrigger,
		refresh: function () {
			ScrollTrigger.refresh();
		}
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', run);
	} else {
		run();
	}
})();
