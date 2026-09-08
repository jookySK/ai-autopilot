/**
 * Jookas AI Autopilot – background job progress polling (v1.6.0).
 * Config: window.O3DAI_PROGRESS = { keys, i18n:{progress,done_refresh,done_bg,show}, ajax, nonce, fg }
 */
(function () {
	var cfg = window.O3DAI_PROGRESS || {};
	if (!cfg.keys || !cfg.keys.length) { return; }
	var LBL_PROG = (cfg.i18n && cfg.i18n.progress) || '',
	    LBL_DONE = (cfg.i18n && cfg.i18n.done_refresh) || '',
	    LBL_DONE_BG = (cfg.i18n && cfg.i18n.done_bg) || '',
	    LBL_SHOW = (cfg.i18n && cfg.i18n.show) || '';
	var box = document.getElementById('o3dai-progress'),
	    bar = document.getElementById('o3dai-progress-bar'),
	    lbl = document.getElementById('o3dai-progress-label');
	if (!box || !bar || !lbl) { return; }
	var keys = cfg.keys,
	    ajax = cfg.ajax,
	    nonce = cfg.nonce,
	    total = keys.length, tries = 0, pct = 8;
	var fg = !!cfg.fg;
	var overlay = document.getElementById('o3dai-progress-overlay');
	if (fg && overlay) { overlay.style.display = 'block'; }
	box.style.display = 'block';
	if (total > 1) { lbl.textContent = LBL_PROG.replace('{d}', 0).replace('{t}', total); }
	function tick() {
		tries++;
		pct = Math.min(pct + (pct < 70 ? 7 : 2), 92);
		bar.style.width = pct + '%';
		var q = keys.map(function (k) { return 'keys[]=' + encodeURIComponent(k); }).join('&');
		fetch(ajax + '?action=o3dai_progress&nonce=' + nonce + '&' + q, { credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!res || !res.success) { return; }
				var st = res.data, done = 0;
				keys.forEach(function (k) { if (st[k] === 'done' || st[k] === 'error') { done++; } });
				if (total > 1) { lbl.textContent = LBL_PROG.replace('{d}', done).replace('{t}', total); }
				if (done >= total) {
					bar.style.width = '100%';
					var url = location.href.replace(/[?&]o3dai_watch=[^&]*/, '').replace(/[?&]o3dai_filled=[^&]*/,'');
					if (fg) {
						lbl.textContent = LBL_DONE;
						setTimeout(function () { location.href = url; }, 700);
					} else {
						// do not force-refresh in background mode – offer a link to the result
						lbl.innerHTML = LBL_DONE_BG + ' <a href="' + url + '" style="color:#2BC6B4;font-weight:700;text-decoration:underline">' + LBL_SHOW + '</a>';
						if (overlay) { overlay.style.display = 'none'; }
						setTimeout(function () { box.style.display = 'none'; }, 12000);
					}
					return;
				}
				if (tries < 120) { setTimeout(tick, 2000); } // max ~4 min
			})
			.catch(function () { if (tries < 120) { setTimeout(tick, 2000); } });
	}
	setTimeout(tick, 1500);
})();
