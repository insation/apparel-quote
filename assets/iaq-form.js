/* Apparel Quote & Mockup (SanMar) - front end. No build step, no dependencies. */
(function () {
	'use strict';

	var CFG = window.IAQ_CFG || {};
	var root = document.getElementById('iaq-app');
	if (!root) { return; }

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	function h(tag, attrs) {
		var el = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				var v = attrs[k];
				if (v === null || v === undefined || v === false) { return; }
				if (k === 'class') { el.className = v; }
				else if (k === 'text') { el.textContent = v; }
				else if (k === 'html') { el.innerHTML = v; }
				else if (k.slice(0, 2) === 'on' && typeof v === 'function') { el.addEventListener(k.slice(2), v); }
				else if (k === 'style' && typeof v === 'object') { Object.assign(el.style, v); }
				else if (v === true) { el.setAttribute(k, ''); }
				else { el.setAttribute(k, v); }
			});
		}
		for (var i = 2; i < arguments.length; i++) { add(el, arguments[i]); }
		return el;
	}
	function add(el, c) {
		if (c === null || c === undefined || c === false) { return; }
		if (Array.isArray(c)) { c.forEach(function (x) { add(el, x); }); return; }
		el.appendChild(typeof c === 'object' ? c : document.createTextNode(String(c)));
	}
	function clear(el) { while (el.firstChild) { el.removeChild(el.firstChild); } return el; }
	function api(path, opts) {
		return fetch(CFG.rest + path, Object.assign({ credentials: 'same-origin' }, opts || {})).catch(function () {
			throw new Error('Could not reach the website. Please check your internet connection and try again.');
		}).then(function (r) {
			return r.json().catch(function () { return null; }).then(function (j) {
				if (!r.ok) {
					var msg = j && j.message;
					var id = j && j.data && j.data.error_id;
					if (!msg) {
						if (r.status === 413) { msg = 'Your files are too large to send. Please use smaller files.'; }
						else if (r.status === 403) { msg = 'The request was blocked by the website (403). Please reload the page and try again.'; }
						else if (r.status >= 500) { msg = 'The website had a problem (error ' + r.status + '). Please try again in a minute.'; }
						else { msg = 'The request failed (error ' + r.status + ').'; }
					}
					if (id && msg.indexOf(id) === -1) { msg += ' (Error ID: ' + id + ')'; }
					throw new Error(msg);
				}
				return j || {};
			});
		});
	}
	function imgUrl(p) {
		if (!p) { return ''; }
		return /^https?:/.test(p) ? p : S.catalog.img + p;
	}
	function proxyUrl(p, w) {
		if (!p) { return ''; }
		if (/^https?:/.test(p)) { return p; }
		return CFG.rest + 'img?p=' + encodeURIComponent(p) + (w ? '&w=' + w : '');
	}
	function thumbUrl(p, w) { return /^https?:/.test(p || '') ? p : proxyUrl(p, w || 300); }

	// Most-used categories first.
	var CAT_ORDER = ['T-Shirts', 'Polos/Knits', 'Sweatshirts/Fleece', 'Caps', 'Outerwear', 'Activewear', 'Woven Shirts', "Women's", 'Youth', 'Tall', 'Bags', 'Workwear', 'Bottoms', 'Infant & Toddler', 'Accessories', 'Personal Protection'];
	function catRank(c) { var i = CAT_ORDER.indexOf(c); return i < 0 ? 50 : i; }
	function sum(obj) { var n = 0; Object.keys(obj || {}).forEach(function (k) { n += (+obj[k] || 0); }); return n; }
	function scrollTop() {
		var r = root.getBoundingClientRect();
		if (r.top < 0) { window.scrollTo({ top: window.pageYOffset + r.top - 90, behavior: 'smooth' }); }
	}
	var imgCache = {};
	function loadImage(src) {
		if (imgCache[src]) { return imgCache[src]; }
		imgCache[src] = new Promise(function (resolve, reject) {
			var im = new Image();
			if (/^https?:/.test(src) && src.indexOf(location.origin) !== 0) { im.crossOrigin = 'anonymous'; }
			im.onload = function () { resolve(im); };
			im.onerror = function () { delete imgCache[src]; reject(new Error('Image failed to load')); };
			im.src = src;
		});
		return imgCache[src];
	}

	/* ------------------------------------------------------------------ */
	/* Decoration placements                                               */
	/* Boxes are fractions of the product photo: x, y, w, h.                */
	/* ------------------------------------------------------------------ */

	// Calibrated on SanMar's 1200x1800 flat product photos.
	var PLACEMENTS = {
		// rel = position relative to the garment found in the photo:
		//   x: centre offset from the body's centre (in body widths, + = viewer's right),
		//   w: width in body widths, y: top below the collar (in garment heights), h: height in garment heights.
		apparel: [
			{ k: 'Full front', v: 'front', box: [0.34, 0.19, 0.29, 0.25], rel: { x: 0, w: 0.62, y: 0.16, h: 0.40 } },
			{ k: 'Left chest', v: 'front', box: [0.55, 0.18, 0.11, 0.07], rel: { x: 0.22, w: 0.20, y: 0.20, h: 0.10 } },
			{ k: 'Full back', v: 'back', box: [0.34, 0.17, 0.29, 0.25], rel: { x: 0, w: 0.62, y: 0.13, h: 0.42 } },
			{ k: 'Upper back', v: 'back', box: [0.37, 0.13, 0.23, 0.05], rel: { x: 0, w: 0.50, y: 0.07, h: 0.08 } },
			{ k: 'Sleeve', v: null }
		],
		caps: [
			{ k: 'Front', v: 'front', box: [0.36, 0.15, 0.28, 0.14] },
			{ k: 'Back', v: 'back', box: [0.42, 0.42, 0.16, 0.10] },
			{ k: 'Side', v: null }
		],
		bags: [
			{ k: 'Front', v: 'front', box: [0.30, 0.30, 0.40, 0.25] },
			{ k: 'Back', v: 'back', box: [0.30, 0.30, 0.40, 0.25] }
		],
		bottoms: [
			{ k: 'Left leg / hip', v: 'front', box: [0.56, 0.20, 0.14, 0.08] },
			{ k: 'Back pocket', v: 'back', box: [0.30, 0.20, 0.14, 0.08] }
		],
		generic: [
			{ k: 'Front', v: 'front', box: [0.30, 0.28, 0.40, 0.25] },
			{ k: 'Back', v: 'back', box: [0.30, 0.28, 0.40, 0.25] }
		]
	};
	function groupFor(cat) {
		if (cat === 'Caps') { return 'caps'; }
		if (cat === 'Bags') { return 'bags'; }
		if (cat === 'Bottoms') { return 'bottoms'; }
		if (cat === 'Accessories' || cat === 'Personal Protection') { return 'generic'; }
		return 'apparel';
	}
	function placementsFor(cat) { return PLACEMENTS[groupFor(cat)]; }
	function placementDef(cat, k) {
		var list = placementsFor(cat);
		for (var i = 0; i < list.length; i++) { if (list[i].k === k) { return list[i]; } }
		return null;
	}
	function defaultPlacement(cat) { return placementsFor(cat)[0].k; }

	var METHODS = [
		{ v: 'embroidery', t: 'Embroidery' },
		{ v: 'dtf', t: 'DTF / heat transfer' },
		{ v: 'dtg', t: 'DTG / direct-to-garment' },
		{ v: 'unsure', t: 'Not sure - recommend for me' }
	];

	/* ------------------------------------------------------------------ */
	/* State                                                               */
	/* ------------------------------------------------------------------ */

	// Links from the catalog page: ?style=PC54 opens that product, ?cat=T-Shirts opens a category.
	try {
		var qs = new URLSearchParams(window.location.search);
		if (!CFG.startStyle && qs.get('style')) { CFG.startStyle = qs.get('style'); }
		if (!CFG.startCat && qs.get('cat')) { CFG.startCat = qs.get('cat'); }
	} catch (e) {}

	var S = {
		catalog: null,
		screen: 'browse',
		browse: { cat: CFG.startCat || '', q: '', shown: 24 },
		cur: null,          // item being configured
		items: [],
		logo: null,         // { file, previewable, img, processed, removeWhite, name }
		extras: [],
		contact: { name: '', company: '', email: '', phone: '', needed_by: '', notes: '' },
		startedAt: Math.floor(Date.now() / 1000)
	};

	/* ------------------------------------------------------------------ */
	/* Logo handling                                                       */
	/* ------------------------------------------------------------------ */

	var PREVIEW_EXT = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];
	var ALL_EXT = PREVIEW_EXT.concat(['pdf', 'ai', 'eps', 'psd']);

	function extOf(name) { var m = /\.([a-z0-9]+)$/i.exec(name || ''); return m ? m[1].toLowerCase() : ''; }

	function setLogo(file, done) {
		var ext = extOf(file.name);
		if (ALL_EXT.indexOf(ext) < 0) { alert('Please upload a PNG, JPG, SVG, PDF, AI, EPS or PSD file.'); return; }
		if (file.size > (CFG.maxMB || 15) * 1048576) { alert('That file is too large. The limit is ' + (CFG.maxMB || 15) + ' MB.'); return; }
		var prev = S.logo && S.logo.preview && !S.logo.previewable ? S.logo.preview : null;
		S.logo = { file: file, name: file.name, previewable: PREVIEW_EXT.indexOf(ext) >= 0, img: null, processed: null, removeWhite: false };
		if (!S.logo.previewable) {
			S.logo.preview = prev;
			done();
			return;
		}
		readPreview(file, S.logo, done);
	}
	function setPreviewImage(file, done) {
		var ext = extOf(file.name);
		if (PREVIEW_EXT.indexOf(ext) < 0) { alert('For the preview please use a PNG or JPG.'); return; }
		S.logo.preview = { file: file };
		readPreview(file, S.logo, done);
	}
	function readPreview(file, target, done) {
		var url = URL.createObjectURL(file);
		var im = new Image();
		im.onload = function () {
			target.img = im;
			target.removeWhite = looksLikeWhiteBackground(im);
			processLogo();
			done();
		};
		im.onerror = function () { target.img = null; alert('We could not read that image. Try a PNG or JPG.'); done(); };
		im.src = url;
	}
	function rasterize(im) {
		var w = im.naturalWidth || im.width || 800, hgt = im.naturalHeight || im.height || 800;
		var scale = Math.min(1, 1600 / Math.max(w, hgt));
		if (!im.naturalWidth) { scale = 800 / Math.max(w, hgt); }
		var c = document.createElement('canvas');
		c.width = Math.max(1, Math.round(w * scale));
		c.height = Math.max(1, Math.round(hgt * scale));
		c.getContext('2d').drawImage(im, 0, 0, c.width, c.height);
		return c;
	}
	function looksLikeWhiteBackground(im) {
		try {
			var c = rasterize(im), ctx = c.getContext('2d');
			var d = ctx.getImageData(0, 0, c.width, c.height).data;
			var alpha = false;
			for (var i = 3; i < d.length; i += 4 * 7) { if (d[i] < 250) { alpha = true; break; } }
			if (alpha) { return false; }
			var pts = [[1, 1], [c.width - 2, 1], [1, c.height - 2], [c.width - 2, c.height - 2]];
			return pts.every(function (p) {
				var o = (p[1] * c.width + p[0]) * 4;
				return d[o] > 235 && d[o + 1] > 235 && d[o + 2] > 235;
			});
		} catch (e) { return false; }
	}
	function processLogo() {
		var L = S.logo;
		if (!L || !L.img) { return; }
		var c = rasterize(L.img), ctx = c.getContext('2d');
		var id, d;
		try { id = ctx.getImageData(0, 0, c.width, c.height); d = id.data; } catch (e) { L.processed = c; return; }
		if (L.removeWhite) {
			for (var i = 0; i < d.length; i += 4) {
				var m = Math.min(d[i], d[i + 1], d[i + 2]);
				if (m > 240) { d[i + 3] = 0; }
				else if (m > 215) { d[i + 3] = Math.round(d[i + 3] * (240 - m) / 25); }
			}
			ctx.putImageData(id, 0, 0);
		}
		// Trim empty margins so the logo fills its print area.
		var minX = c.width, minY = c.height, maxX = -1, maxY = -1;
		for (var y = 0; y < c.height; y++) {
			for (var x = 0; x < c.width; x++) {
				if (d[(y * c.width + x) * 4 + 3] > 12) {
					if (x < minX) { minX = x; } if (x > maxX) { maxX = x; }
					if (y < minY) { minY = y; } if (y > maxY) { maxY = y; }
				}
			}
		}
		if (maxX < 0) { L.processed = c; return; }
		var t = document.createElement('canvas');
		t.width = maxX - minX + 1; t.height = maxY - minY + 1;
		t.getContext('2d').drawImage(c, minX, minY, t.width, t.height, 0, 0, t.width, t.height);
		L.processed = t;
	}
	function logoCanvas() { return S.logo && S.logo.processed ? S.logo.processed : null; }

	/* ------------------------------------------------------------------ */
	/* Mockup drawing                                                      */
	/* Logo transform per placement: { cx, cy, w } as fractions of photo.   */
	/* ------------------------------------------------------------------ */

	/**
	 * Find the garment in a product photo: top/bottom of the garment and the
	 * left/right edges of the body (measured low on the garment, below the sleeves).
	 * Returns fractions of the photo, or null if it cannot tell.
	 */
	var geoCache = typeof WeakMap === 'function' ? new WeakMap() : null;
	function garmentGeo(img) {
		if (!img) { return null; }
		if (geoCache && geoCache.has(img)) { return geoCache.get(img); }
		var geo = null;
		try {
			var W = 120, H = Math.max(1, Math.round(W * img.naturalHeight / img.naturalWidth));
			var c = document.createElement('canvas'); c.width = W; c.height = H;
			var x = c.getContext('2d'); x.drawImage(img, 0, 0, W, H);
			var d = x.getImageData(0, 0, W, H).data;
			var bg = [d[0], d[1], d[2]];
			var isFg = function (i) { return Math.abs(d[i] - bg[0]) + Math.abs(d[i + 1] - bg[1]) + Math.abs(d[i + 2] - bg[2]) > 12; };
			var rows = [];
			for (var yy = 0; yy < H; yy++) {
				var l = -1, r = -1;
				for (var xx = 0; xx < W; xx++) { if (isFg((yy * W + xx) * 4)) { if (l < 0) { l = xx; } r = xx; } }
				rows.push(r - l >= 3 ? [l, r] : null);
			}
			var top = -1, bot = -1;
			for (var a = 0; a < H; a++) { if (rows[a]) { if (top < 0) { top = a; } bot = a; } }
			if (top >= 0 && bot - top > H * 0.15) {
				var ws = [];
				for (var b = Math.round(top + (bot - top) * 0.70); b <= Math.round(top + (bot - top) * 0.88); b++) {
					if (rows[b]) { ws.push(rows[b]); }
				}
				if (ws.length) {
					ws.sort(function (p, q) { return (p[1] - p[0]) - (q[1] - q[0]); });
					var m = ws[Math.floor(ws.length / 2)];
					var gcx = (m[0] + m[1] + 1) / 2 / W;
					// Hanging sleeves can widen the measured body; a body is rarely wider than ~half its length.
					var gbw = Math.min((m[1] + 1 - m[0]) / W, 0.52 * (bot + 1 - top) / W);
					geo = { top: top / H, bot: (bot + 1) / H, l: gcx - gbw / 2, r: gcx + gbw / 2 };
					if (geo.r - geo.l < 0.15) { geo = null; }
				}
			}
		} catch (e) { geo = null; }
		if (geoCache) { geoCache.set(img, geo); }
		return geo;
	}
	function boxFor(def, photo, item) {
		var g = def.rel ? garmentGeo(photo) : null;
		if (!g) { return def.box; }
		var bw = g.r - g.l, gh = g.bot - g.top, cx = (g.l + g.r) / 2 + def.rel.x * bw;
		var w = def.rel.w * bw, h = def.rel.h * gh;
		// Hoods add height above the shoulders: move print areas down below the hood.
		var shift = item && /hood/i.test(item.title || '') ? (def.v === 'back' ? 0.16 : 0.10) : 0;
		return [cx - w / 2, g.top + (def.rel.y + shift) * gh, w, h];
	}

	function defaultTransform(def, aspect, box) {
		// aspect = photo width / height
		var b = box || def.box, lg = logoCanvas();
		var bw = b[2], bh = b[3];
		var w = bw;
		if (lg) {
			var ar = lg.width / lg.height;
			w = Math.min(bw, bh * ar / (aspect || 1));
		}
		// Sit the logo at the top of the print area (where printers place it), centred across.
		var lhFrac = lg ? w * (aspect || 1) * lg.height / lg.width : bh;
		return { cx: b[0] + bw / 2, cy: b[1] + Math.min(bh, lhFrac) / 2, w: w };
	}
	function ensureTransforms(item) { item.tf = item.tf || {}; }
	function viewPath(item, view) {
		return view === 'back' ? (item.fb || item.mb || '') : (item.ff || item.mf || '');
	}
	function viewsUsed(item) {
		var v = {};
		item.placements.forEach(function (k) {
			var def = placementDef(item.cat, k);
			if (def && def.v) { v[def.v] = true; }
		});
		return ['front', 'back'].filter(function (x) { return v[x] && viewPath(item, x); });
	}

	/**
	 * Draw product photo + logos for one view onto ctx (size W x H).
	 */
	function drawMock(ctx, W, H, photo, item, view, opts) {
		opts = opts || {};
		ctx.clearRect(0, 0, W, H);
		ctx.fillStyle = '#fff';
		ctx.fillRect(0, 0, W, H);
		if (photo) { ctx.drawImage(photo, 0, 0, W, H); }
		var lg = logoCanvas();
		item.placements.forEach(function (k) {
			var def = placementDef(item.cat, k);
			if (!def || def.v !== view) { return; }
			var box = boxFor(def, photo, item);
			if (photo && !item.tf[k]) {
				item.tf[k] = lg ? defaultTransform(def, W / H, box)
					// No logo yet: an adjustable print area the logo will be fitted into later.
					: { cx: box[0] + box[2] / 2, cy: box[1] + box[3] / 2, w: box[2], h: box[3], a: W / H, area: true };
			}
			var t = item.tf[k];
			var active = opts.active === k;
			if (!t) { return; }
			if (!lg || t.area) {
				if (!opts.guides) { return; }
				var ax = (t.cx - t.w / 2) * W, ay = (t.cy - t.h / 2) * H, aw = t.w * W, ah = t.h * H;
				ctx.save();
				ctx.fillStyle = 'rgba(235,73,38,.06)';
				ctx.fillRect(ax, ay, aw, ah);
				ctx.setLineDash([6, 5]);
				ctx.strokeStyle = 'rgba(235,73,38,.9)';
				ctx.lineWidth = 1.5;
				ctx.strokeRect(ax, ay, aw, ah);
				ctx.setLineDash([]);
				if (active) { drawHandles(ctx, W, ax, ay, aw, ah); }
				ctx.restore();
				return;
			}
			var lw = t.w * W;
			var lh = lw * lg.height / lg.width;
			var x = t.cx * W - lw / 2, y = t.cy * H - lh / 2;
			ctx.save();
			ctx.globalAlpha = 0.96;
			ctx.drawImage(lg, x, y, lw, lh);
			ctx.restore();
			if (opts.guides && active) {
				ctx.save();
				ctx.strokeStyle = 'rgba(40,41,42,.7)';
				ctx.lineWidth = 1;
				ctx.setLineDash([4, 3]);
				ctx.strokeRect(x, y, lw, lh);
				ctx.setLineDash([]);
				drawHandles(ctx, W, x, y, lw, lh);
				ctx.restore();
			}
		});
	}

	function drawHandles(ctx, W, x, y, w, h) {
		var hs = Math.max(10, Math.round(W / 50));
		[[x, y], [x + w, y], [x, y + h], [x + w, y + h]].forEach(function (c) {
			ctx.fillStyle = '#fff';
			ctx.strokeStyle = '#EB4926';
			ctx.lineWidth = 2;
			ctx.fillRect(c[0] - hs / 2, c[1] - hs / 2, hs, hs);
			ctx.strokeRect(c[0] - hs / 2, c[1] - hs / 2, hs, hs);
		});
	}

	/**
	 * Turn print areas chosen before the logo was uploaded into logo positions:
	 * the logo is fitted inside the area, at its top, centred across.
	 */
	function fitLogoToAreas(item) {
		var lg = logoCanvas();
		item.tf = item.tf || {};
		Object.keys(item.tf).forEach(function (k) {
			var t = item.tf[k];
			if (!t || !t.area || !lg) { return; }
			var a = t.a || 1;
			var w = Math.min(t.w, t.h * (lg.width / lg.height) / a);
			var lh = w * a * lg.height / lg.width;
			item.tf[k] = { cx: t.cx, cy: t.cy - t.h / 2 + lh / 2, w: w };
		});
	}

	/**
	 * Full-size mockup as a Blob (JPEG), or null if it cannot be made.
	 */
	function renderMockBlob(item, view) {
		var p = viewPath(item, view);
		if (!p) { return Promise.resolve(null); }
		return loadImage(proxyUrl(p)).then(function (photo) {
			var maxW = 1200;
			var W = Math.min(maxW, photo.naturalWidth || maxW);
			var H = Math.round(W * (photo.naturalHeight / photo.naturalWidth));
			var c = document.createElement('canvas');
			c.width = W; c.height = H;
			drawMock(c.getContext('2d'), W, H, photo, item, view, {});
			return new Promise(function (resolve) {
				try { c.toBlob(function (b) { resolve(b); }, 'image/jpeg', 0.9); } catch (e) { resolve(null); }
			});
		}).catch(function () { return null; });
	}

	/* ------------------------------------------------------------------ */
	/* Screens                                                             */
	/* ------------------------------------------------------------------ */

	function render() {
		clear(root);
		root.removeAttribute('data-loading');
		if (S.screen === 'browse') { renderBrowse(); }
		else if (S.screen === 'product') { renderProduct(); }
		else if (S.screen === 'review') { renderReview(); }
		else if (S.screen === 'done') { renderDone(); }
	}

	function cartBar() {
		if (!S.items.length) { return null; }
		var pcs = S.items.reduce(function (n, it) { return n + it.total; }, 0);
		return h('div', { class: 'iaq-cartbar' },
			h('span', null, 'Your request: ', h('strong', null, S.items.length + (S.items.length === 1 ? ' product' : ' products')), ' · ' + pcs + ' pcs'),
			h('button', { type: 'button', class: 'iaq-btn iaq-btn-primary', onclick: function () { S.screen = 'review'; render(); scrollTop(); } }, 'Review & send request →')
		);
	}

	/* ---- Browse ---- */

	function filteredStyles() {
		var q = S.browse.q.trim().toLowerCase();
		var words = q ? q.split(/\s+/) : [];
		var list = S.catalog.styles.filter(function (s) {
			if (S.browse.cat && s.c !== S.browse.cat) { return false; }
			if (!words.length) { return true; }
			var hay = (s.s + ' ' + s.t + ' ' + s.b + ' ' + s.sc + ' ' + s.c).toLowerCase();
			return words.every(function (w) { return hay.indexOf(w) >= 0; });
		});
		if (words.length === 1) {
			// Exact style number first, then styles starting with it.
			var w0 = words[0];
			var rank = function (s) { var c = s.s.toLowerCase(); return c === w0 ? 0 : (c.indexOf(w0) === 0 ? 1 : 2); };
			list = list.map(function (s, i) { return [rank(s), i, s]; })
				.sort(function (a, b) { return a[0] - b[0] || a[1] - b[1]; })
				.map(function (x) { return x[2]; });
		}
		return list;
	}

	function renderBrowse() {
		var cats = S.catalog.categories;
		var catNames = Object.keys(cats);
		var gridWrap = h('div');
		var countEl = h('div', { class: 'iaq-count' });

		function paint() {
			var list = filteredStyles();
			countEl.textContent = list.length + (list.length === 1 ? ' product' : ' products');
			clear(gridWrap);
			if (!list.length) {
				gridWrap.appendChild(h('p', { class: 'iaq-empty' }, 'No products match. Try another word or style number.'));
				return;
			}
			var grid = h('div', { class: 'iaq-grid' });
			list.slice(0, S.browse.shown).forEach(function (s) {
				grid.appendChild(h('button', {
					type: 'button', class: 'iaq-card', onclick: function () { openStyle(s.s); }
				},
				h('span', { class: 'iaq-card-img' }, h('img', { src: thumbUrl(s.i, 300), alt: '', loading: 'lazy', decoding: 'async' })),
				s.n ? h('span', { class: 'iaq-badge' }, 'New') : null,
				h('span', { class: 'iaq-card-brand' }, s.b + ' · ' + s.s),
				h('span', { class: 'iaq-card-title' }, s.t),
				h('span', { class: 'iaq-card-meta' }, s.k + (s.k === 1 ? ' color' : ' colors'))
				));
			});
			gridWrap.appendChild(grid);
			if (list.length > S.browse.shown) {
				gridWrap.appendChild(h('div', { class: 'iaq-more' },
					h('button', { type: 'button', class: 'iaq-btn', onclick: function () { S.browse.shown += 24; paint(); } }, 'Show more (' + (list.length - S.browse.shown) + ' left)')));
			}
		}

		var search = h('input', {
			type: 'search', class: 'iaq-search', placeholder: 'Search by name, brand or style # (e.g. PC54, hoodie, Nike polo)',
			value: S.browse.q, 'aria-label': 'Search products',
			oninput: function (e) { S.browse.q = e.target.value; S.browse.shown = 24; paint(); }
		});

		var pills = h('div', { class: 'iaq-pills', role: 'tablist' });
		function pill(name, label) {
			return h('button', {
				type: 'button', class: 'iaq-pill' + (S.browse.cat === name ? ' is-on' : ''), role: 'tab',
				'aria-selected': S.browse.cat === name ? 'true' : 'false',
				onclick: function () { S.browse.cat = name; S.browse.shown = 24; render(); }
			}, label);
		}
		pills.appendChild(pill('', 'All'));
		catNames.forEach(function (c) { pills.appendChild(pill(c, c)); });

		add(root, [
			CFG.intro ? h('p', { class: 'iaq-intro' }, CFG.intro) : null,
			cartBar(),
			h('div', { class: 'iaq-steps' }, step(1, 'Choose a product', true), step(2, 'Color, sizes & logo'), step(3, 'Send request')),
			search, pills, countEl, gridWrap
		]);
		paint();
	}

	function step(n, label, on) {
		return h('span', { class: 'iaq-step' + (on ? ' is-on' : '') }, h('b', null, n), label);
	}

	function openStyle(code, editIndex) {
		root.setAttribute('data-loading', '1');
		api('style/' + encodeURIComponent(code)).then(function (st) {
			if (typeof editIndex === 'number') {
				var it = JSON.parse(JSON.stringify(S.items[editIndex]));
				it.st = st; it.editIndex = editIndex;
				S.cur = it;
			} else {
				var c0 = st.col[0];
				S.cur = {
					st: st, style: st.s, title: st.t, brand: st.b, cat: st.c,
					color: c0.n, ff: c0.ff, fb: c0.fb, mf: c0.mf, mb: c0.mb, sizesAvail: c0.z || st.sz,
					method: 'unsure', placements: [defaultPlacement(st.c)], sizes: {}, total: 0,
					logo_colors: '', notes: '', tf: {}, view: 'front', active: defaultPlacement(st.c)
				};
			}
			if (!S.browse.cat && CFG.startStyle && st.s === CFG.startStyle) { S.browse.cat = st.c; }
			S.screen = 'product';
			render();
			scrollTop();
		}).catch(function (e) {
			root.removeAttribute('data-loading');
			alert(e.message || 'Could not load that product.');
		});
	}

	/* ---- Product ---- */

	function renderProduct() {
		var it = S.cur, st = it.st;
		ensureTransforms(it);

		/* Canvas */
		var canvas = h('canvas', { class: 'iaq-canvas', width: 600, height: 600, 'aria-label': 'Mockup preview' });
		var ctx = canvas.getContext('2d');
		var photo = null;
		var stageNote = h('div', { class: 'iaq-stage-note' });
		var sizeRange = h('input', { type: 'range', min: '4', max: '70', step: '1', 'aria-label': 'Logo size' });
		var sizeRow = h('label', { class: 'iaq-size-row', style: { display: 'none' } }, 'Logo size', sizeRange);

		function activeOnView() {
			var d = placementDef(it.cat, it.active);
			if (d && d.v === it.view && it.placements.indexOf(it.active) >= 0) { return it.active; }
			for (var i = 0; i < it.placements.length; i++) {
				var dd = placementDef(it.cat, it.placements[i]);
				if (dd && dd.v === it.view) { return it.placements[i]; }
			}
			return null;
		}
		function redraw() {
			var act = activeOnView();
			drawMock(ctx, canvas.width, canvas.height, photo, it, it.view, { guides: true, active: act });
			var lg = logoCanvas();
			sizeRow.style.display = act && it.tf[act] ? '' : 'none';
			if (act && it.tf[act]) { sizeRange.value = Math.round(it.tf[act].w * 100); }
			if (!lg) {
				stageNote.textContent = S.logo && !S.logo.previewable
					? 'We can\'t preview ' + extOf(S.logo.name).toUpperCase() + ' files here. Add a PNG or JPG version below to see the mockup. Your original file will still be sent.'
					: 'Drag the dashed box to where you want your logo, and drag a corner to resize it. Upload your logo below to see it.';
			} else if (!act) {
				stageNote.textContent = 'No logo on the ' + it.view + '. Pick a ' + it.view + ' placement on the right to add one.';
			} else {
				stageNote.textContent = 'Drag the logo to move it. Drag a corner (or pinch / scroll) to resize.';
			}
		}
		var loadSeq = 0;
		function loadView() {
			var p = viewPath(it, it.view);
			var req = ++loadSeq; // Ignore photos that arrive after the customer switched color or side.
			photo = null;
			if (!p) { redraw(); return; }
			canvas.classList.add('is-loading');
			loadImage(proxyUrl(p, 800)).then(function (im) {
				if (req !== loadSeq) { return; }
				photo = im;
				var W = 600, H = Math.round(W * im.naturalHeight / im.naturalWidth);
				canvas.width = W; canvas.height = H;
				canvas.classList.remove('is-loading');
				redraw();
			}).catch(function () {
				// Fall back to the direct image (shown, but cannot be exported).
				if (req !== loadSeq) { return; }
				loadImage(imgUrl(p)).then(function (im) { if (req !== loadSeq) { return; } photo = im; canvas.classList.remove('is-loading'); redraw(); })
					.catch(function () { if (req !== loadSeq) { return; } canvas.classList.remove('is-loading'); redraw(); });
			});
		}

		sizeRange.addEventListener('input', function () {
			var act = activeOnView();
			if (!act) { return; }
			setW(it.tf[act], (+sizeRange.value) / 100);
			redraw();
		});

		// Move: drag the logo. Resize: drag a corner handle, mouse wheel, or pinch with two fingers.
		var drag = null;
		var pointers = {};
		var pinch = null;
		function pos(e) {
			var r = canvas.getBoundingClientRect();
			return { x: (e.clientX - r.left) / r.width, y: (e.clientY - r.top) / r.height, rw: r.width, rh: r.height };
		}
		function logoRect(k) {
			var lg = logoCanvas(), t = it.tf[k];
			if (!t) { return null; }
			var hh = (!lg || t.area) ? t.h : t.w * (lg.height / lg.width) * (canvas.width / canvas.height);
			return { x0: t.cx - t.w / 2, x1: t.cx + t.w / 2, y0: t.cy - hh / 2, y1: t.cy + hh / 2 };
		}
		function visibleKeys() {
			return it.placements.filter(function (k) {
				var d = placementDef(it.cat, k);
				return d && d.v === it.view && it.tf[k];
			});
		}
		// Returns { k, mode: 'move' | 'resize' } for the logo under the pointer.
		function hit(p) {
			var keys = visibleKeys();
			// Corner handles of the active logo first (generous target for fingers).
			var tol = 16 / Math.max(1, p.rw);
			var tolY = 16 / Math.max(1, p.rh);
			var order = keys.filter(function (k) { return k === it.active; }).concat(keys.filter(function (k) { return k !== it.active; }));
			for (var i = 0; i < order.length; i++) {
				var r = logoRect(order[i]);
				if (!r) { continue; }
				var corners = [[r.x0, r.y0], [r.x1, r.y0], [r.x0, r.y1], [r.x1, r.y1]];
				for (var c = 0; c < 4; c++) {
					if (Math.abs(p.x - corners[c][0]) <= tol && Math.abs(p.y - corners[c][1]) <= tolY) {
						return { k: order[i], mode: 'resize', corner: c };
					}
				}
			}
			for (var j = order.length - 1; j >= 0; j--) {
				var rr = logoRect(order[j]);
				if (rr && p.x >= rr.x0 - 0.01 && p.x <= rr.x1 + 0.01 && p.y >= rr.y0 - 0.01 && p.y <= rr.y1 + 0.01) {
					return { k: order[j], mode: 'move' };
				}
			}
			return null;
		}
		function clampW(w) { return Math.max(0.03, Math.min(0.9, w)); }
		// Set the width; print areas (no logo yet) keep their proportions.
		function setW(t, w) {
			var nw = clampW(w);
			if (t.area && t.w) { t.h = Math.max(0.02, Math.min(0.95, t.h * nw / t.w)); }
			t.w = nw;
		}
		function pxDist(p, t) {
			var dx = (p.x - t.cx) * p.rw, dy = (p.y - t.cy) * p.rh;
			return Math.sqrt(dx * dx + dy * dy);
		}
		canvas.addEventListener('pointerdown', function (e) {
			var p = pos(e);
			pointers[e.pointerId] = p;
			var ids = Object.keys(pointers);
			if (ids.length === 2) {
				// Two fingers: pinch-resize the active logo.
				var k2 = it.active && it.tf[it.active] && visibleKeys().indexOf(it.active) >= 0 ? it.active : visibleKeys()[0];
				if (k2) {
					var a = pointers[ids[0]], b = pointers[ids[1]];
					pinch = { k: k2, d: Math.hypot((a.x - b.x) * a.rw, (a.y - b.y) * a.rh) || 1, w: it.tf[k2].w };
					drag = null;
				}
				canvas.setPointerCapture(e.pointerId);
				e.preventDefault();
				return;
			}
			var h0 = hit(p);
			if (!h0) { return; }
			it.active = h0.k;
			var t = it.tf[h0.k];
			if (h0.mode === 'resize') {
				drag = { k: h0.k, mode: 'resize', d0: pxDist(p, t) || 1, w0: t.w };
			} else {
				drag = { k: h0.k, mode: 'move', dx: p.x - t.cx, dy: p.y - t.cy };
			}
			canvas.setPointerCapture(e.pointerId);
			e.preventDefault();
			redraw();
		});
		canvas.addEventListener('pointermove', function (e) {
			var p = pos(e);
			if (pointers[e.pointerId]) { pointers[e.pointerId] = p; }
			if (pinch) {
				var ids = Object.keys(pointers);
				if (ids.length >= 2) {
					var a = pointers[ids[0]], b = pointers[ids[1]];
					var d = Math.hypot((a.x - b.x) * a.rw, (a.y - b.y) * a.rh);
					setW(it.tf[pinch.k], pinch.w * d / pinch.d);
					redraw();
				}
				return;
			}
			if (!drag) {
				var hv = hit(p);
				if (!hv) { canvas.style.cursor = 'default'; }
				else if (hv.mode === 'move') { canvas.style.cursor = 'move'; }
				else { canvas.style.cursor = (hv.corner === 0 || hv.corner === 3) ? 'nwse-resize' : 'nesw-resize'; }
				return;
			}
			var t = it.tf[drag.k];
			if (drag.mode === 'resize') {
				setW(t, drag.w0 * pxDist(p, t) / drag.d0);
			} else {
				t.cx = Math.max(0.03, Math.min(0.97, p.x - drag.dx));
				t.cy = Math.max(0.03, Math.min(0.97, p.y - drag.dy));
			}
			redraw();
		});
		function endDrag(e) {
			delete pointers[e.pointerId];
			if (Object.keys(pointers).length < 2) { pinch = null; }
			drag = null;
		}
		canvas.addEventListener('pointerup', endDrag);
		canvas.addEventListener('pointercancel', endDrag);
		canvas.addEventListener('wheel', function (e) {
			var p = pos(e), hv = hit(p);
			if (!hv) { return; }
			e.preventDefault();
			it.active = hv.k;
			var t = it.tf[hv.k];
			setW(t, t.w * (e.deltaY < 0 ? 1.06 : 1 / 1.06));
			redraw();
		}, { passive: false });

		var viewBtns = h('div', { class: 'iaq-seg' });
		function paintViews() {
			clear(viewBtns);
			['front', 'back'].forEach(function (v) {
				var has = !!viewPath(it, v);
				viewBtns.appendChild(h('button', {
					type: 'button', class: 'iaq-seg-btn' + (it.view === v ? ' is-on' : ''), disabled: !has,
					onclick: function () { it.view = v; paintViews(); loadView(); }
				}, v === 'front' ? 'Front' : 'Back'));
			});
		}
		paintViews();

		/* Logo upload */
		var logoBox = h('div', { class: 'iaq-logo' });
		function paintLogo() {
			clear(logoBox);
			var input = h('input', {
				type: 'file', accept: '.png,.jpg,.jpeg,.gif,.webp,.svg,.pdf,.ai,.eps,.psd', class: 'iaq-file',
				onchange: function (e) { if (e.target.files[0]) { setLogo(e.target.files[0], function () { resetTransforms(); paintLogo(); redraw(); }); } }
			});
			if (!S.logo) {
				logoBox.appendChild(h('label', { class: 'iaq-drop' },
					h('strong', null, 'Upload your logo'),
					h('span', null, 'PNG with a transparent background works best. JPG, SVG, PDF, AI and EPS are also accepted.'),
					input));
				bindDrop(logoBox, function (f) { setLogo(f, function () { resetTransforms(); paintLogo(); redraw(); }); });
				return;
			}
			logoBox.appendChild(h('div', { class: 'iaq-logo-row' },
				logoCanvas() ? h('span', { class: 'iaq-logo-thumb' }, thumbOf(logoCanvas())) : null,
				h('span', { class: 'iaq-logo-name' }, S.logo.name),
				h('label', { class: 'iaq-btn iaq-btn-sm' }, 'Change', input)
			));
			if (S.logo.img) {
				logoBox.appendChild(h('label', { class: 'iaq-check' },
					h('input', { type: 'checkbox', checked: S.logo.removeWhite, onchange: function (e) { S.logo.removeWhite = e.target.checked; processLogo(); paintLogo(); redraw(); } }),
					'Remove white background'));
			}
			if (!S.logo.previewable) {
				var pin = h('input', {
					type: 'file', accept: '.png,.jpg,.jpeg,.gif,.webp,.svg', class: 'iaq-file',
					onchange: function (e) { if (e.target.files[0]) { setPreviewImage(e.target.files[0], function () { resetTransforms(); paintLogo(); redraw(); }); } }
				});
				logoBox.appendChild(h('label', { class: 'iaq-btn iaq-btn-sm iaq-mt' }, S.logo.img ? 'Change preview image' : 'Add a PNG/JPG for the preview', pin));
			}
		}
		function resetTransforms() {
			// Keep where the customer put things: fit the logo into each print area.
			S.items.forEach(function (x) { ensureTransforms(x); fitLogoToAreas(x); });
			ensureTransforms(it); fitLogoToAreas(it);
		}
		paintLogo();

		/* Right column: options */
		var colorName = h('span', { class: 'iaq-color-name' }, it.color);
		var swatches = h('div', { class: 'iaq-swatches' });
		st.col.forEach(function (c) {
			swatches.appendChild(h('button', {
				type: 'button', class: 'iaq-sw' + (c.n === it.color ? ' is-on' : ''), title: c.n, 'aria-label': c.n,
				onclick: function () {
					it.color = c.n; it.ff = c.ff; it.fb = c.fb; it.mf = c.mf; it.mb = c.mb;
					it.sizesAvail = c.z || st.sz;
					Object.keys(it.sizes).forEach(function (z) { if (it.sizesAvail.indexOf(z) < 0) { delete it.sizes[z]; } });
					colorName.textContent = c.n;
					Array.prototype.forEach.call(swatches.children, function (b) { b.classList.toggle('is-on', b.title === c.n); });
					paintSizes(); paintViews(); loadView();
				}
			}, h('img', { src: thumbUrl(c.ff || c.mf, 150), alt: '', loading: 'lazy', decoding: 'async' })));
		});

		var methodBox = h('div', { class: 'iaq-radios' });
		METHODS.forEach(function (m) {
			methodBox.appendChild(h('label', { class: 'iaq-radio' },
				h('input', { type: 'radio', name: 'iaq-method', value: m.v, checked: it.method === m.v, onchange: function () { it.method = m.v; paintColors(); } }),
				m.t));
		});

		var colorsWrap = h('div');
		function paintColors() {
			clear(colorsWrap);
			return; // Color-count question was only needed for screen printing (not offered).
			var sel = h('select', { class: 'iaq-input', onchange: function (e) { it.logo_colors = e.target.value; } },
				['', '1', '2', '3', '4', '5+', 'Full color / photo', 'Not sure'].map(function (o) {
					return h('option', { value: o, selected: it.logo_colors === o }, o || 'Choose…');
				}));
			add(colorsWrap, h('label', { class: 'iaq-field' }, h('span', null, 'How many colors are in your logo?'), sel));
		}
		paintColors();

		var placeBox = h('div', { class: 'iaq-checks' });
		placementsFor(it.cat).forEach(function (pd) {
			placeBox.appendChild(h('label', { class: 'iaq-check' },
				h('input', {
					type: 'checkbox', checked: it.placements.indexOf(pd.k) >= 0,
					onchange: function (e) {
						if (e.target.checked) {
							if (it.placements.indexOf(pd.k) < 0) { it.placements.push(pd.k); }
							ensureTransforms(it);
							if (pd.v) {
								it.active = pd.k;
								if (it.view !== pd.v && viewPath(it, pd.v)) { it.view = pd.v; paintViews(); loadView(); return; }
							}
						} else {
							it.placements = it.placements.filter(function (x) { return x !== pd.k; });
						}
						redraw();
					}
				}),
				pd.k, pd.v ? null : h('small', null, ' (noted on the order, not shown in preview)')));
		});

		var sizesBox = h('div', { class: 'iaq-sizes' });
		var totalEl = h('div', { class: 'iaq-total' });
		function paintSizes() {
			clear(sizesBox);
			it.sizesAvail.forEach(function (z) {
				sizesBox.appendChild(h('label', { class: 'iaq-sz' },
					h('span', null, z),
					h('input', {
						type: 'number', min: '0', step: '1', inputmode: 'numeric', value: it.sizes[z] || '', placeholder: '0',
						oninput: function (e) {
							var n = Math.max(0, parseInt(e.target.value, 10) || 0);
							if (n) { it.sizes[z] = n; } else { delete it.sizes[z]; }
							paintTotal();
						}
					})));
			});
			paintTotal();
		}
		function paintTotal() {
			it.total = sum(it.sizes);
			totalEl.textContent = 'Total: ' + it.total + (it.total === 1 ? ' piece' : ' pieces');
		}
		paintSizes();

		var notes = h('textarea', { class: 'iaq-input', rows: '2', placeholder: 'Anything else about this item? (names/numbers, ink colors, etc.)', oninput: function (e) { it.notes = e.target.value; } });
		notes.value = it.notes || '';

		var errEl = h('div', { class: 'iaq-error', role: 'alert' });

		function saveItem() {
			errEl.textContent = '';
			if (!it.total) { errEl.textContent = 'Please enter how many you need in at least one size.'; sizesBox.querySelector('input') && sizesBox.querySelector('input').focus(); return; }
			if (!it.placements.length) { errEl.textContent = 'Please choose where the logo goes.'; return; }
			var copy = {
				style: it.style, title: it.title, brand: it.brand, cat: it.cat, color: it.color,
				ff: it.ff, fb: it.fb, mf: it.mf, mb: it.mb, sizesAvail: it.sizesAvail,
				method: it.method, placements: it.placements.slice(), sizes: Object.assign({}, it.sizes), total: it.total,
				logo_colors: it.logo_colors, notes: it.notes, tf: JSON.parse(JSON.stringify(it.tf)), view: 'front', active: it.active
			};
			if (typeof it.editIndex === 'number') { S.items[it.editIndex] = copy; } else { S.items.push(copy); }
			S.cur = null;
			S.screen = 'review';
			render();
			scrollTop();
		}

		var desc = st.d ? h('details', { class: 'iaq-desc' }, h('summary', null, 'Product details'), h('p', null, st.d),
			st.dec ? h('p', null, h('a', { href: st.dec, target: '_blank', rel: 'noopener' }, 'Decoration spec sheet')) : null) : null;

		add(root, [
			cartBar(),
			h('div', { class: 'iaq-steps' }, step(1, 'Choose a product'), step(2, 'Color, sizes & logo', true), step(3, 'Send request')),
			h('button', { type: 'button', class: 'iaq-link', onclick: function () { S.cur = null; S.screen = S.items.length && typeof it.editIndex === 'number' ? 'review' : 'browse'; render(); } }, '← Back'),
			h('div', { class: 'iaq-product' },
				h('div', { class: 'iaq-stage' },
					viewBtns,
					h('div', { class: 'iaq-canvas-wrap' }, canvas),
					stageNote,
					sizeRow,
					logoBox,
					h('p', { class: 'iaq-fine' }, 'The preview is approximate. We will confirm the final size and placement with you before anything is printed.')
				),
				h('div', { class: 'iaq-opts' },
					h('div', { class: 'iaq-brand' }, st.b + ' · Style ' + st.s),
					h('h3', { class: 'iaq-title' }, st.t),
					desc,
					h('div', { class: 'iaq-group' }, h('div', { class: 'iaq-label' }, 'Color: ', colorName), swatches),
					h('div', { class: 'iaq-group' }, h('div', { class: 'iaq-label' }, 'Decoration'), methodBox, colorsWrap),
					h('div', { class: 'iaq-group' }, h('div', { class: 'iaq-label' }, 'Logo placement'), placeBox),
					h('div', { class: 'iaq-group' }, h('div', { class: 'iaq-label' }, 'Quantity per size'), sizesBox, totalEl),
					h('div', { class: 'iaq-group' }, notes),
					errEl,
					h('div', { class: 'iaq-actions' },
						h('button', { type: 'button', class: 'iaq-btn iaq-btn-primary', onclick: saveItem }, typeof it.editIndex === 'number' ? 'Save changes' : 'Add to my request'))
				)
			)
		]);
		loadView();
	}

	function thumbOf(c) {
		var t = document.createElement('canvas');
		var s = Math.min(1, 64 / Math.max(c.width, c.height));
		t.width = Math.max(1, Math.round(c.width * s)); t.height = Math.max(1, Math.round(c.height * s));
		t.getContext('2d').drawImage(c, 0, 0, t.width, t.height);
		return t;
	}
	function bindDrop(el, cb) {
		['dragenter', 'dragover'].forEach(function (ev) { el.addEventListener(ev, function (e) { e.preventDefault(); el.classList.add('is-drag'); }); });
		['dragleave', 'drop'].forEach(function (ev) { el.addEventListener(ev, function (e) { e.preventDefault(); el.classList.remove('is-drag'); }); });
		el.addEventListener('drop', function (e) { var f = e.dataTransfer && e.dataTransfer.files[0]; if (f) { cb(f); } });
	}

	/* ---- Review & send ---- */

	function itemThumb(item) {
		var view = viewsUsed(item)[0] || 'front';
		var c = h('canvas', { class: 'iaq-item-canvas', width: 160, height: 160 });
		loadImage(proxyUrl(viewPath(item, view), 800)).then(function (im) {
			c.height = Math.round(160 * im.naturalHeight / im.naturalWidth);
			drawMock(c.getContext('2d'), c.width, c.height, im, item, view, {});
		}).catch(function () {
			loadImage(imgUrl(viewPath(item, view))).then(function (im) { drawMock(c.getContext('2d'), c.width, c.height, im, item, view, {}); }).catch(function () {});
		});
		return c;
	}

	function renderReview() {
		var list = h('div', { class: 'iaq-items' });
		if (!S.items.length) {
			list.appendChild(h('p', { class: 'iaq-empty' }, 'Your request is empty.'));
		}
		S.items.forEach(function (it, i) {
			ensureTransforms(it);
			var sizes = Object.keys(it.sizes).map(function (z) { return z + ': ' + it.sizes[z]; }).join(' · ');
			list.appendChild(h('div', { class: 'iaq-item' },
				itemThumb(it),
				h('div', { class: 'iaq-item-body' },
					h('div', { class: 'iaq-item-title' }, it.brand + ' ' + it.style + ' - ' + it.title),
					h('div', null, 'Color: ', h('strong', null, it.color)),
					h('div', null, 'Sizes: ', sizes, ' (', h('strong', null, it.total + ' pcs'), ')'),
					h('div', null, 'Decoration: ' + methodText(it.method) + (it.placements.length ? ' - ' + it.placements.join(', ') : '')),
					h('div', { class: 'iaq-item-actions' },
						h('button', { type: 'button', class: 'iaq-link', onclick: function () { openStyle(it.style, i); } }, 'Edit'),
						h('button', { type: 'button', class: 'iaq-link iaq-danger', onclick: function () { if (confirmRemove()) { S.items.splice(i, 1); render(); } } }, 'Remove'))
				)
			));
		});

		var C = S.contact;
		function field(key, label, attrs) {
			var input = h(attrs && attrs.textarea ? 'textarea' : 'input', Object.assign({
				class: 'iaq-input', name: key, oninput: function (e) { C[key] = e.target.value; }
			}, attrs || {}));
			input.removeAttribute('textarea');
			input.value = C[key] || '';
			return h('label', { class: 'iaq-field' + (attrs && attrs.wide ? ' iaq-wide' : '') }, h('span', null, label), input);
		}

		var extrasBox = h('div', { class: 'iaq-extras' });
		function paintExtras() {
			clear(extrasBox);
			S.extras.forEach(function (f, i) {
				extrasBox.appendChild(h('div', { class: 'iaq-extra' }, f.name, ' ',
					h('button', { type: 'button', class: 'iaq-link iaq-danger', onclick: function () { S.extras.splice(i, 1); paintExtras(); } }, 'remove')));
			});
			if (S.extras.length < 3) {
				extrasBox.appendChild(h('label', { class: 'iaq-btn iaq-btn-sm' }, '+ Add another file (back design, names list…)',
					h('input', {
						type: 'file', class: 'iaq-file', accept: '.png,.jpg,.jpeg,.gif,.webp,.svg,.pdf,.ai,.eps,.psd',
						onchange: function (e) {
							var f = e.target.files[0];
							if (!f) { return; }
							if (ALL_EXT.indexOf(extOf(f.name)) < 0) { alert('That file type is not accepted.'); return; }
							if (f.size > (CFG.maxMB || 15) * 1048576) { alert('That file is too large.'); return; }
							S.extras.push(f); paintExtras();
						}
					})));
			}
		}
		paintExtras();

		var honeypot = h('div', { class: 'iaq-hp', 'aria-hidden': 'true' }, h('label', null, 'Website', h('input', { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off' })));
		var errEl = h('div', { class: 'iaq-error', role: 'alert' });
		var sendBtn = h('button', { type: 'submit', class: 'iaq-btn iaq-btn-primary iaq-btn-lg' }, 'Send my request');

		var form = h('form', {
			class: 'iaq-contact', novalidate: true,
			onsubmit: function (e) {
				e.preventDefault();
				submit(form, sendBtn, errEl, honeypot.querySelector('input').value);
			}
		},
			h('h3', null, 'Your details'),
			h('div', { class: 'iaq-fields' },
				field('name', 'Name *', { required: true, autocomplete: 'name' }),
				field('company', 'Company / team / school', { autocomplete: 'organization' }),
				field('email', 'Email *', { type: 'email', required: true, autocomplete: 'email' }),
				field('phone', 'Phone', { type: 'tel', autocomplete: 'tel' }),
				field('needed_by', 'When do you need it?', { type: 'date' }),
				field('notes', 'Anything else we should know?', { textarea: true, rows: '3', wide: true })
			),
			h('div', { class: 'iaq-label' }, 'Logo'),
			S.logo ? h('p', null, S.logo.name, S.logo.preview && S.logo.preview.file ? ' (+ preview ' + S.logo.preview.file.name + ')' : '')
				: h('p', { class: 'iaq-fine' }, 'No logo uploaded. You can still send the request and email us your artwork later.'),
			extrasBox,
			honeypot,
			errEl,
			h('div', { class: 'iaq-actions' }, sendBtn,
				h('span', { class: 'iaq-fine' }, 'No payment now. We will email you a quote.'))
		);

		add(root, [
			h('div', { class: 'iaq-steps' }, step(1, 'Choose a product'), step(2, 'Color, sizes & logo'), step(3, 'Send request', true)),
			h('div', { class: 'iaq-review-head' },
				h('h3', null, 'Your request'),
				h('button', { type: 'button', class: 'iaq-btn', onclick: function () { S.screen = 'browse'; render(); scrollTop(); } }, '+ Add another product')),
			list,
			S.items.length ? form : null
		]);
	}

	function confirmRemove() { return window.confirm ? window.confirm('Remove this product from your request?') : true; }
	function methodText(v) { for (var i = 0; i < METHODS.length; i++) { if (METHODS[i].v === v) { return METHODS[i].t; } } return v; }

	function submit(form, btn, errEl, hp) {
		var C = S.contact;
		errEl.textContent = '';
		if (!C.name.trim()) { errEl.textContent = 'Please enter your name.'; form.querySelector('[name=name]').focus(); return; }
		if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(C.email.trim())) { errEl.textContent = 'Please enter a valid email address.'; form.querySelector('[name=email]').focus(); return; }
		btn.disabled = true;
		btn.textContent = 'Preparing mockups…';

		var jobs = [];
		S.items.forEach(function (it, i) {
			ensureTransforms(it);
			if (!logoCanvas()) { return; }
			viewsUsed(it).forEach(function (v) {
				jobs.push(renderMockBlob(it, v).then(function (b) { return b ? { key: 'mockup_' + i + '_' + v, blob: b, name: 'mockup-' + (i + 1) + '-' + it.style + '-' + v + '.jpg' } : null; }));
			});
		});

		Promise.all(jobs).then(function (mocks) {
			btn.textContent = 'Sending…';
			return api('token').then(function (tk) {
				var fd = new FormData();
				fd.append('token', tk.token);
				fd.append('started', String(S.startedAt));
				fd.append('website', hp || '');
				Object.keys(C).forEach(function (k) { fd.append(k, C[k] || ''); });
				fd.append('items', JSON.stringify(S.items.map(function (it) {
					return { style: it.style, color: it.color, sizes: it.sizes, method: it.method, placements: it.placements, logo_colors: it.logo_colors, notes: it.notes };
				})));
				if (S.logo) {
					fd.append('logo', S.logo.file, S.logo.file.name);
					if (!S.logo.previewable && S.logo.preview && S.logo.preview.file) {
						fd.append('extra_2', S.logo.preview.file, S.logo.preview.file.name);
					}
				}
				S.extras.slice(0, S.logo && !S.logo.previewable && S.logo.preview ? 2 : 3).forEach(function (f, i) { fd.append('extra_' + i, f, f.name); });
				mocks.forEach(function (m) { if (m) { fd.append(m.key, m.blob, m.name); } });
				return api('submit', { method: 'POST', body: fd });
			}).then(function (res) {
				S.done = { ref: res.ref, message: res.message, mocks: mocks.filter(Boolean) };
				S.screen = 'done';
				render();
				scrollTop();
			});
		}).catch(function (e) {
			btn.disabled = false;
			btn.textContent = 'Send my request';
			errEl.textContent = ((e && e.message) || 'Something went wrong.') + ' If it keeps happening, please call us and we will take your order by phone.';
		});
	}

	function renderDone() {
		var D = S.done || {};
		var gallery = h('div', { class: 'iaq-done-mocks' });
		(D.mocks || []).forEach(function (m) {
			gallery.appendChild(h('img', { src: URL.createObjectURL(m.blob), alt: 'Mockup' }));
		});
		add(root, h('div', { class: 'iaq-done' },
			h('div', { class: 'iaq-done-icon', 'aria-hidden': 'true' }, '✓'),
			h('h3', null, 'Request sent'),
			h('p', null, D.message || 'Thanks! We will get back to you with a quote shortly.'),
			D.ref ? h('p', null, 'Your reference: ', h('strong', null, D.ref)) : null,
			gallery,
			h('button', {
				type: 'button', class: 'iaq-btn', onclick: function () {
					S.items = []; S.extras = []; S.done = null; S.screen = 'browse'; S.startedAt = Math.floor(Date.now() / 1000);
					render(); scrollTop();
				}
			}, 'Start a new request')
		));
	}

	/* ------------------------------------------------------------------ */
	/* Boot                                                                */
	/* ------------------------------------------------------------------ */

	function loadCatalog() {
		var fromRest = function () { return api('catalog'); };
		if (!CFG.catalogUrl) { return fromRest(); }
		return fetch(CFG.catalogUrl).then(function (r) { if (!r.ok) { throw new Error('x'); } return r.json(); }).catch(fromRest);
	}
	function expandCatalog(c) {
		if (!c.rows) { return c; }
		var cats = {};
		c.cats.forEach(function (n, i) { cats[n] = c.counts[i]; });
		return {
			img: c.img, categories: cats,
			styles: c.rows.map(function (r) {
				return { s: r[0], t: r[1], b: c.brands[r[2]], c: c.cats[r[3]], sc: r[4], n: r[5], i: r[6], k: r[7] };
			})
		};
	}

	loadCatalog().then(expandCatalog).then(function (cat) {
		cat.styles = cat.styles.map(function (s, i) { return [catRank(s.c), i, s]; })
			.sort(function (a, b) { return a[0] - b[0] || a[1] - b[1]; })
			.map(function (x) { return x[2]; });
		var ordered = {};
		Object.keys(cat.categories).sort(function (a, b) { return catRank(a) - catRank(b) || (a < b ? -1 : 1); })
			.forEach(function (k) { ordered[k] = cat.categories[k]; });
		cat.categories = ordered;
		S.catalog = cat;
		if (S.browse.cat && !cat.categories[S.browse.cat]) {
			var want = String(S.browse.cat).toLowerCase().replace(/[^a-z0-9]+/g, '');
			S.browse.cat = Object.keys(cat.categories).filter(function (c) { return c.toLowerCase().replace(/[^a-z0-9]+/g, '') === want; })[0] || '';
		}
		if (CFG.startStyle) { openStyle(CFG.startStyle); return; }
		render();
	}).catch(function () {
		clear(root).appendChild(h('p', { class: 'iaq-error' }, 'The product catalog could not be loaded. Please refresh the page or call us.'));
	});

	window.addEventListener('beforeunload', function (e) {
		if (S.items.length && S.screen !== 'done') { e.preventDefault(); e.returnValue = ''; }
	});
})();
