/**
 * Jookas AI Autopilot – "Suggest keywords" button for news-based topics (v1.6.0).
 * Config: window.O3DAI_NEWS_SUGGEST = { ajax, action, nonce, i18n:{loading,done,error} }
 */
(function () {
	var cfg = window.O3DAI_NEWS_SUGGEST || {};
	var btn = document.getElementById('o3d-suggest-kw');
	if (!btn) { return; }
	var note = document.getElementById('o3d-suggest-kw-note');
	var i18n = cfg.i18n || {};
	var orig = btn.textContent;
	function show(msg) { if (note) { note.textContent = msg; note.style.display = 'block'; } }
	function reset() { btn.disabled = false; btn.textContent = orig; }
	btn.addEventListener('click', function () {
		btn.disabled = true;
		btn.textContent = i18n.loading || '';
		if (note) { note.style.display = 'none'; }
		var xhr = new XMLHttpRequest();
		xhr.open('POST', cfg.ajax, true);
		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
		xhr.timeout = 90000;
		xhr.onload = function () {
			reset();
			try {
				var r = JSON.parse(xhr.responseText);
				if (r && r.success && r.data && r.data.keywords) {
					var ta = document.querySelector('input[name="news_keywords"]');
					if (ta) { ta.value = r.data.keywords; }
					show(i18n.done || '');
				} else {
					show((r && r.data && r.data.message) || i18n.error || 'Error');
				}
			} catch (e) { show(i18n.error || 'Error'); }
		};
		xhr.onerror = function () { reset(); show(i18n.error || 'Error'); };
		xhr.ontimeout = function () { reset(); show(i18n.error || 'Error'); };
		var body = cfg.action ? ('action=' + encodeURIComponent(cfg.action)) : '';
		if (cfg.nonce) { body += (body ? '&' : '') + 'nonce=' + encodeURIComponent(cfg.nonce); }
		xhr.send(body);
	});
})();
