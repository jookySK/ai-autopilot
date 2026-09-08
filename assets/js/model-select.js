/**
 * Jookas AI Autopilot – provider/model select (settings + onboarding wizard, v1.6.0).
 * Config: window.O3DAI_MODEL_CFG = { provId, modelId, models, current, customLabel }
 * When customLabel is empty the "__custom__" option is not offered (wizard mode).
 */
(function () {
	var cfg = window.O3DAI_MODEL_CFG || {};
	if (!cfg.models) { return; }
	var prov = document.getElementById(cfg.provId);
	var mdl = document.getElementById(cfg.modelId);
	if (!prov || !mdl) { return; }
	var MODELS = cfg.models;
	var CURRENT = cfg.current || '';
	var CUSTOM_LABEL = cfg.customLabel || '';
	function fill(keepCurrent) {
		var list = MODELS[prov.value] || {};
		mdl.innerHTML = '';
		var found = false, o, id;
		for (id in list) {
			o = document.createElement('option');
			o.value = id;
			o.textContent = list[id];
			if (id === CURRENT) { o.selected = true; found = true; }
			mdl.appendChild(o);
		}
		if (!CUSTOM_LABEL) { return; } // wizard mode: no custom option
		var c = document.createElement('option');
		c.value = '__custom__';
		c.textContent = CUSTOM_LABEL;
		mdl.appendChild(c);
		if (keepCurrent && CURRENT && !found) {
			// keep the user's own model that is not in the preset list
			var cust = document.createElement('option');
			cust.value = CURRENT;
			cust.textContent = CURRENT + ' (vlastný)';
			cust.selected = true;
			mdl.insertBefore(cust, c);
		}
	}
	fill(true);
	prov.addEventListener('change', function () { CURRENT = ''; fill(false); });
	if (!CUSTOM_LABEL) { return; }
	mdl.addEventListener('change', function () {
		if (mdl.value === '__custom__') {
			var v = window.prompt(CUSTOM_LABEL + ':', CURRENT || '');
			if (v) {
				var opt = document.createElement('option');
				opt.value = v;
				opt.textContent = v + ' (vlastný)';
				opt.selected = true;
				mdl.insertBefore(opt, mdl.querySelector('option[value="__custom__"]'));
				CURRENT = v;
			} else { fill(true); }
		}
	});
})();
