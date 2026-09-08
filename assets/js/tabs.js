/**
 * Jookas AI Autopilot – admin tab switching (v1.6.0).
 * Config: window.O3DAI_TABS = { keep: 'settings' }
 */
(function () {
	var wrap = document.querySelector('.o3d-wrap');
	if (!wrap) { return; }
	wrap.classList.add('o3d-tabs-on');
	var VALID = ['overview', 'topics', 'products', 'settings', 'debug'];
	var KEEP = (window.O3DAI_TABS && window.O3DAI_TABS.keep) || '';
	var tabs = Array.prototype.slice.call(wrap.querySelectorAll('.o3d-tab'));
	var panels = Array.prototype.slice.call(wrap.querySelectorAll('.o3d-tabpanel'));
	function show(name) {
		if (VALID.indexOf(name) === -1) { name = 'overview'; }
		var i;
		for (i = 0; i < tabs.length; i++) {
			tabs[i].classList.toggle('active', tabs[i].getAttribute('data-tab') === name);
		}
		for (i = 0; i < panels.length; i++) {
			panels[i].classList.toggle('active', panels[i].getAttribute('data-panel') === name);
		}
		try { history.replaceState(null, '', location.pathname + location.search + '#' + name); } catch (e) {}
		window.scrollTo(0, 0);
	}
	for (var k = 0; k < tabs.length; k++) {
		tabs[k].addEventListener('click', function () { show(this.getAttribute('data-tab')); });
	}
	var initial = '';
	var h = location.hash ? location.hash.replace(/^#/, '') : '';
	if (VALID.indexOf(h) !== -1) { initial = h; }
	if (!initial) {
		var m = location.search.match(/[?&]o3d_tab=([^&]+)/);
		if (m) {
			var g = decodeURIComponent(m[1]);
			if (VALID.indexOf(g) !== -1) { initial = g; }
		}
	}
	if (!initial && KEEP && VALID.indexOf(KEEP) !== -1) { initial = KEEP; }
	show(initial || 'overview');
})();
