/**
 * Jookas AI Autopilot – provider/model select (settings + onboarding wizard, v1.6.0; v1.9.6 dynamic local models).
 * Config: window.O3DAI_MODEL_CFG = { provId, modelId, models, current, customLabel }
 * Optional cfg.localAjax = { ajaxUrl, nonce, loadingLabel, providers: { <prov>: { action, urlInput, fallbackUrlInput?, mirrorTo } } }:
 * for such a provider the server-side model list is fetched and appended to the dropdown; picking an option
 * mirrors its value into the named input (dyn.mirrorTo) so it is what actually gets saved/used.
 * When customLabel is empty the "__custom__" option is not offered (wizard mode).
 */
(function () {
	var cfg = window.O3DAI_MODEL_CFG || {};
	if (!cfg.models) { return; }
	var prov = document.getElementById(cfg.provId);
	var mdl  = document.getElementById(cfg.modelId);
	if (!prov || !mdl) { return; }
	var MODELS = cfg.models;
	var LOCAL  = cfg.localAjax || null;
	var CURRENT = cfg.current || '';
	var CUSTOM_LABEL = cfg.customLabel || '';
	var CUSTOM_SUFFIX = cfg.customSuffix || '(custom)'; // v1.6.13 – jazyk prívlastku (bol napevno "(vlastný)")

	function addOption(value, label, selected) {
		var o = document.createElement('option');
		o.value = value;
		o.textContent = label;
		if (selected) { o.selected = true; }
		mdl.appendChild(o);
		return o;
	}

	function addCustom() {
		if (!CUSTOM_LABEL) { return null; } // wizard mode: no custom option
		var c = document.createElement('option');
		c.value = '__custom__';
		c.textContent = CUSTOM_LABEL;
		mdl.appendChild(c);
		return c;
	}

	function fill(keepCurrent) {
		var list = MODELS[prov.value] || {};
		mdl.innerHTML = '';
		var found = false, o, id;
		for (id in list) {
			o = addOption(id, list[id], id === CURRENT);
			if (id === CURRENT) { found = true; }
		}
		var c = addCustom();
		if (keepCurrent && CURRENT && !found) {
			// keep the user's own model that is not in the preset list
			o = document.createElement('option');
			o.value = CURRENT;
			o.textContent = CURRENT + ' ' + CUSTOM_SUFFIX;
			o.selected = true;
			mdl.insertBefore(o, c || mdl.lastChild);
		}
		var dyn = (LOCAL && LOCAL.providers) ? LOCAL.providers[prov.value] : null; // v1.9.6 – lokálni provideri: zoznam modelov zo servera
		if (dyn) { fillServerModels(dyn); }
	}

	function fillServerModels(dyn) {
		var urlEl = document.querySelector('input[name="' + dyn.urlInput + '"]');
		var fbEl  = dyn.fallbackUrlInput ? document.querySelector('input[name="' + dyn.fallbackUrlInput + '"]') : null;
		var url   = (urlEl && urlEl.value) ? urlEl.value : (fbEl ? fbEl.value : '');
		if (!url) { return; } // server URL zatiaľ nenastavený – statické možnosti ostávajú, zoznam sa dá načítať tlačidlom nižšie
		addOption('__loading__', LOCAL.loadingLabel || '…', false).disabled = true;
		fetch(LOCAL.ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=' + encodeURIComponent(dyn.action) + '&nonce=' + encodeURIComponent(LOCAL.nonce || '') + '&url=' + encodeURIComponent(url) })
			.then(function ( r ) { return r.json(); })
			.then(function ( data ) {
				var list = MODELS[prov.value] || {};
				mdl.innerHTML = '';
				var id, o;
				for (id in list) { o = addOption(id, list[id], false); }
				if (data && data.success && data.data && data.data.models) {
					data.data.models.forEach(function ( m ) { addOption(m, m, false); });
				}
				addCustom();
				var mi = null;
				try { mi = document.querySelector(dyn.mirrorTo || ''); } catch (e) {}
				if (mi && mi.value) { // dropdown zobraziť model, ktorý je už nastavený v poli modelu
					for (var i2 = 0; i2 < mdl.options.length; i2++) {
						if (mdl.options[i2].value === mi.value) { mdl.selectedIndex = i2; break; }
					}
				}
			})
			.catch(function () {}); // chyba siete: ponechaj statické možnosti
	}

	fill(true);
	prov.addEventListener('change', function () { CURRENT = ''; fill(false); });
	if (!CUSTOM_LABEL) { return; }
	mdl.addEventListener('change', function () {
		var dyn = (LOCAL && LOCAL.providers) ? LOCAL.providers[prov.value] : null;
		if (dyn && mdl.value !== '__custom__' && mdl.value !== '__loading__') {
			try {
				var mi2 = document.querySelector(dyn.mirrorTo || '');
				if (mi2) {
					if (mi2.tagName === 'SELECT') {
						var hasOpt = false;
						for (var k3 = 0; k3 < mi2.options.length; k3++) { if (mi2.options[k3].value === mdl.value) { hasOpt = true; break; } }
						if (!hasOpt) { var nopt = document.createElement('option'); nopt.value = mdl.value; nopt.textContent = mdl.value; mi2.appendChild(nopt); }
					}
					mi2.value = mdl.value;
				}
			} catch (e) {} // v1.9.6 – vybraný model sa zrkadlí do nastavenia lokálneho providera; v1.9.9 select-aware
		} else if (mdl.value === '__custom__') {
			var v = window.prompt(CUSTOM_LABEL + ':', CURRENT || '');
			if (v) {
				var opt = document.createElement('option');
				opt.value = v;
				opt.textContent = v + ' ' + CUSTOM_SUFFIX;
				opt.selected = true;
				mdl.insertBefore(opt, mdl.querySelector('option[value="__custom__"]'));
				CURRENT = v;
			} else { fill(true); }
		}
	});
})();
