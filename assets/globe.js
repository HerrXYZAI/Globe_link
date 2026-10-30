/* global Globe */
(function () {
    'use strict';

    const el = document.getElementById('globe');
    const popup = document.getElementById('popup');
    const statusEl = document.getElementById('status');
    const listEl = document.getElementById('track-list');
    const listToggle = document.getElementById('list-toggle');

    const HIDE_DELAY = 350;          // ms before the hover window disappears
    const HIT_STROKE = 16;           // invisible, wider copy of each line that makes hovering easier

    // Track lines are drawn in layers, bottom to top. Each layer floats a little higher
    // than the one below so the layers never fight over the same depth.
    const LAYER_ALT = { glow3: 1, glow2: 1.02, glow1: 1.04, outline: 1.15, main: 1.3, hit: 1.35 };
    // Soft glow from three transparent lines: [share of the glow size, share of the strength].
    const GLOW_LAYERS = { glow3: [1, 0.3], glow2: [0.65, 0.45], glow1: [0.3, 0.7] };

    // Display settings from the configuration view (see DISPLAY_DEFAULTS in lib/bootstrap.php).
    let display = {
        track_width: 3, outline_enabled: true, outline_color: '#000000', outline_width: 1.5,
        glow_enabled: false, glow_use_track_color: true, glow_color: '#ffffff', glow_size: 8, glow_opacity: 0.45,
        borders_enabled: true, border_color: '#ffffff', border_opacity: 0.55,
        country_names_enabled: true, city_names_enabled: true, label_language: 'de',
    };

    let globe;
    let tracks = [];
    let markers = [];
    let paths = [];
    let hoverItem = null;
    let pinnedItem = null;
    let hideTimer = null;
    let mouse = { x: 0, y: 0 };
    let currentAlt = 2.5;
    let linkTargetBlank = true;

    // ------------------------------------------------------------------ helpers
    const rad = (d) => (d * Math.PI) / 180;
    const deg = (r) => (r * 180) / Math.PI;

    /** Center (lat/lng) and angular radius (deg) of a list of [lat, lng] points. */
    function boundsOf(points) {
        let x = 0, y = 0, z = 0;
        for (const [lat, lng] of points) {
            x += Math.cos(rad(lat)) * Math.cos(rad(lng));
            y += Math.cos(rad(lat)) * Math.sin(rad(lng));
            z += Math.sin(rad(lat));
        }
        const len = Math.hypot(x, y, z) || 1;
        x /= len; y /= len; z /= len;
        const center = { lat: deg(Math.asin(z)), lng: deg(Math.atan2(y, x)) };
        let radius = 0;
        for (const [lat, lng] of points) {
            const d = Math.cos(rad(lat)) * Math.cos(rad(lng)) * x
                + Math.cos(rad(lat)) * Math.sin(rad(lng)) * y
                + Math.sin(rad(lat)) * z;
            radius = Math.max(radius, deg(Math.acos(Math.min(1, Math.max(-1, d)))));
        }
        return { center, radius };
    }

    function flyTo(points, ms = 1500) {
        if (!points.length) return;
        const { center, radius } = boundsOf(points);
        // Rough mapping from angular radius to camera altitude (in globe radii).
        const altitude = Math.min(2.5, Math.max(0.01, radius / 22));
        globe.pointOfView({ lat: center.lat, lng: center.lng, altitude }, ms);
    }

    const allPoints = (list) => list.flatMap((t) => t.segments.flat());

    function formatKm(km) {
        return km >= 100 ? `${Math.round(km).toLocaleString()} km` : `${km.toFixed(1)} km`;
    }

    const dateFormat = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });

    /** "12 Jun 2024" for a single day, otherwise a range like "12 – 18 Jun 2024". */
    function formatDateRange(start, end) {
        if (!start) return '';
        const a = new Date(`${start}T00:00:00Z`);
        if (!end || end === start || Number.isNaN(Date.parse(end))) return dateFormat.format(a);
        const b = new Date(`${end}T00:00:00Z`);
        return dateFormat.formatRange ? dateFormat.formatRange(a, b) : `${dateFormat.format(a)} – ${dateFormat.format(b)}`;
    }

    function formatCoords(lat, lng) {
        return `${Math.abs(lat).toFixed(4)}° ${lat >= 0 ? 'N' : 'S'}, ${Math.abs(lng).toFixed(4)}° ${lng >= 0 ? 'E' : 'W'}`;
    }

    const itemKey = (item) => `${item.kind}-${item.id}`;

    /** "#ff8800" + 0.4 -> "rgba(255,136,0,0.4)" */
    function rgba(hex, alpha) {
        const n = parseInt(String(hex).replace('#', ''), 16);
        if (Number.isNaN(n)) return `rgba(255,255,255,${alpha})`;
        return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${alpha})`;
    }

    // ------------------------------------------------------------------ popup
    function fillPopup(item) {
        const img = popup.querySelector('img');
        if (item.image) {
            img.src = item.image;
            img.hidden = false;
        } else {
            img.hidden = true;
            img.removeAttribute('src');
        }
        popup.querySelector('h2').textContent = item.name;
        popup.querySelector('.meta').textContent = item.kind === 'marker'
            ? formatCoords(item.lat, item.lng)
            : [formatDateRange(item.start_date, item.end_date), item.distance_km ? formatKm(item.distance_km) : '']
                .filter(Boolean).join(' · ');
        popup.querySelector('.text').textContent = item.description || '';
        const a = popup.querySelector('.more');
        if (item.link) {
            a.href = item.link;
            if (linkTargetBlank) {
                a.target = '_blank';
                a.rel = 'noopener';
            } else {
                a.removeAttribute('target');
            }
            a.hidden = false;
        } else {
            a.hidden = true;
        }
        popup.dataset.key = itemKey(item);
    }

    function placePopup(x, y) {
        const pad = 12;
        const w = popup.offsetWidth;
        const h = popup.offsetHeight;
        let left = x + pad;
        let top = y + pad;
        if (left + w > window.innerWidth - pad) left = Math.max(pad, x - w - pad);
        if (top + h > window.innerHeight - pad) top = Math.max(pad, window.innerHeight - h - pad);
        popup.style.left = `${left}px`;
        popup.style.top = `${top}px`;
    }

    function showPopup(item, x, y, pinned) {
        clearTimeout(hideTimer);
        if (popup.dataset.key !== itemKey(item) || popup.hidden) {
            fillPopup(item);
            popup.hidden = false;
            placePopup(x, y);
        }
        popup.classList.toggle('pinned', !!pinned);
    }

    function hidePopup() {
        clearTimeout(hideTimer);
        popup.hidden = true;
        popup.classList.remove('pinned');
        delete popup.dataset.key;
        pinnedItem = null;
        refreshPaths();
    }

    function scheduleHide() {
        if (pinnedItem) return;
        clearTimeout(hideTimer);
        hideTimer = setTimeout(() => {
            if (!popup.matches(':hover') && !hoverItem && !pinnedItem) hidePopup();
        }, HIDE_DELAY);
    }

    function pin(item, x, y) {
        pinnedItem = item;
        showPopup(item, x, y, true);
        refreshPaths();
    }

    popup.addEventListener('mouseleave', scheduleHide);
    popup.addEventListener('mouseenter', () => clearTimeout(hideTimer));
    popup.querySelector('.close').addEventListener('click', hidePopup);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') hidePopup(); });

    // ------------------------------------------------------------------ paths
    const isActive = (track) => track === hoverItem || track === pinnedItem;

    /** One path object per segment and layer: optional glow and outline, the line itself, a hover helper. */
    function buildPaths() {
        const layers = [
            ...(display.glow_enabled ? ['glow3', 'glow2', 'glow1'] : []),
            ...(display.outline_enabled ? ['outline'] : []),
            'main',
            'hit',
        ];
        paths = tracks.flatMap((track) => track.segments.flatMap((segment) => layers.map((layer) => ({
            track,
            layer,
            // The third value is the layer's altitude factor (the altitude accessor only sees points).
            points: segment.map(([lat, lng]) => [lat, lng, LAYER_ALT[layer]]),
        }))));
        if (globe) globe.pathsData(paths);
    }

    function pathStroke(p) {
        const active = isActive(p.track);
        const w = active ? Math.max(display.track_width * 1.8, display.track_width + 2) : display.track_width;
        switch (p.layer) {
            case 'hit': return Math.max(HIT_STROKE, w + 10);
            case 'outline': return w + 2 * display.outline_width;
            case 'glow1': case 'glow2': case 'glow3':
                return w + 2 * display.glow_size * GLOW_LAYERS[p.layer][0] * (active ? 1.3 : 1);
            default: return w;
        }
    }

    function pathColor(p) {
        const active = isActive(p.track);
        switch (p.layer) {
            case 'hit': return 'rgba(0,0,0,0)';
            case 'outline': return display.outline_color;
            case 'glow1': case 'glow2': case 'glow3':
                return rgba(display.glow_use_track_color ? p.track.color : display.glow_color,
                    Math.min(1, display.glow_opacity * GLOW_LAYERS[p.layer][1] * (active ? 1.5 : 1)));
            default: return active ? '#ffffff' : p.track.color;
        }
    }

    function refreshPaths() {
        if (!globe) return;
        // Keep the lines just above the surface: high enough not to clip into it, low enough
        // not to drift visibly away from the ground when zoomed in.
        const alt = Math.max(currentAlt * 0.01, 0.000003);
        globe
            .pathStroke(pathStroke)
            .pathColor(pathColor)
            .pathPointAlt((pt) => alt * pt[2]);
        if (bordersShown) globe.polygonAltitude(alt * 0.5);
    }

    // ------------------------------------------------------------------ country borders
    let bordersShown = null;      // '50m' | '10m' | null
    const borderData = {};        // detail level -> polygon objects

    /**
     * Land borders from Natural Earth: coarse when zoomed out, detailed when zoomed in.
     * Drawn through the polygons layer as outlines only (no surfaces, so they never cover
     * anything or catch the mouse).
     */
    async function updateBorders() {
        if (!display.borders_enabled || !globe) return;
        const level = currentAlt < 0.35 ? '10m' : '50m';
        if (bordersShown === level) return;
        if (!borderData[level]) {
            try {
                const lines = await (await fetch(`assets/vendor/geo/borders-${level}.json`)).json();
                // A "polygon" whose only ring runs along the border and back again (rings must be
                // closed); only its stroke is drawn, so the way back lies on the same pixels.
                borderData[level] = lines.map((line) => ({
                    geometry: { type: 'Polygon', coordinates: [[...line, ...line.slice(0, -1).reverse()]] },
                }));
            } catch (err) {
                console.warn('Globe Link: country borders could not be loaded', err);
                return;
            }
        }
        if ((currentAlt < 0.35 ? '10m' : '50m') !== level) return; // zoom changed while loading
        bordersShown = level;
        globe.polygonsData(borderData[level]);
        refreshPaths();
    }

    // ------------------------------------------------------------------ country and city names
    const labelSets = { countries: [], cities: [] };
    let shownLabels = [];
    let labelTimer = null;

    async function loadLabels() {
        const lang = display.label_language === 'en' ? 1 : 0;
        const load = async (name, kind) => {
            try {
                const rows = await (await fetch(`assets/vendor/geo/${name}.json`)).json();
                return rows.map((r) => ({ kind, name: r[lang], lat: r[2], lng: r[3], minZoom: r[4], capital: !!r[5] }));
            } catch (err) {
                console.warn(`Globe Link: ${name} could not be loaded`, err);
                return [];
            }
        };
        if (display.country_names_enabled) labelSets.countries = await load('countries', 'country');
        if (display.city_names_enabled) labelSets.cities = await load('cities', 'city');
        scheduleLabels();
    }

    function labelElement(label) {
        const el = document.createElement('div');
        el.className = `map-label ${label.kind}${label.capital ? ' capital' : ''}`;
        const span = document.createElement('span');
        span.textContent = label.name;
        el.append(span);
        return el;
    }

    function scheduleLabels() {
        if (labelTimer) return;
        labelTimer = setTimeout(() => { labelTimer = null; updateLabels(); }, 120);
    }

    /** Angular distance in degrees. */
    function angularDistance(lat1, lng1, lat2, lng2) {
        const c = Math.sin(rad(lat1)) * Math.sin(rad(lat2))
            + Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.cos(rad(lng2 - lng1));
        return deg(Math.acos(Math.min(1, Math.max(-1, c))));
    }

    /**
     * Pick the names to show: by importance for the current zoom level, only on the
     * visible side of the globe, and never overlapping each other on screen.
     */
    function updateLabels() {
        if (!globe || (!labelSets.countries.length && !labelSets.cities.length)) return;
        const pov = globe.pointOfView();
        const alt = pov.altitude;
        // Rough equivalent of a web map zoom level (0 = whole world).
        const zoom = Math.log2(1 / alt) + 1.6;
        const horizon = deg(Math.acos(1 / (1 + alt)));
        const viewRadius = Math.min(horizon, alt * 55 + 3);
        const candidates = [
            ...labelSets.countries.filter((l) => l.minZoom <= zoom + 1.6),
            ...labelSets.cities.filter((l) => l.minZoom <= zoom + 0.5),
        ]
            .filter((l) => angularDistance(pov.lat, pov.lng, l.lat, l.lng) < viewRadius)
            // Countries before cities of the same importance; capitals before other cities.
            .sort((a, b) => (a.minZoom - (a.kind === 'country' ? 0.5 : 0)) - (b.minZoom - (b.kind === 'country' ? 0.5 : 0))
                || (b.capital - a.capital));

        const w = window.innerWidth;
        const h = window.innerHeight;
        // Keep the markers free: names never cover them.
        const taken = markers.map((m) => globe.getScreenCoords(m.lat, m.lng, 0))
            .filter(Boolean).map((p) => [p.x - 12, p.y - 12, p.x + 12, p.y + 12]);
        const result = [];
        for (const l of candidates) {
            if (result.length >= 150) break;
            const p = globe.getScreenCoords(l.lat, l.lng, 0);
            if (!p || p.x < 0 || p.y < 0 || p.x > w || p.y > h) continue;
            const textW = l.name.length * (l.kind === 'country' ? 8.5 : 6.8);
            const box = l.kind === 'country'
                ? [p.x - textW / 2 - 4, p.y - 10, p.x + textW / 2 + 4, p.y + 10]
                : [p.x - 5, p.y - 10, p.x + 9 + textW, p.y + 10];
            if (taken.some((t) => box[0] < t[2] && box[2] > t[0] && box[1] < t[3] && box[3] > t[1])) continue;
            taken.push(box);
            result.push(l);
        }
        const changed = result.length !== shownLabels.length || result.some((l, i) => l !== shownLabels[i]);
        if (changed) {
            shownLabels = result;
            globe.htmlElementsData([...markers, ...shownLabels]);
        }
    }

    // ------------------------------------------------------------------ markers
    function markerElement(marker) {
        const m = document.createElement('div');
        m.className = 'marker';
        m.style.setProperty('--marker-color', marker.color);
        m.setAttribute('role', 'button');
        m.setAttribute('aria-label', marker.name);
        m.addEventListener('pointerenter', (e) => {
            hoverItem = marker;
            if (!pinnedItem || pinnedItem === marker) showPopup(marker, e.clientX, e.clientY, pinnedItem === marker);
        });
        m.addEventListener('pointerleave', () => {
            if (hoverItem === marker) hoverItem = null;
            scheduleHide();
        });
        m.addEventListener('pointerdown', (e) => e.stopPropagation());
        m.addEventListener('click', (e) => {
            e.stopPropagation();
            pin(marker, e.clientX, e.clientY);
            history.replaceState(null, '', `#marker=${marker.id}`);
        });
        return m;
    }

    // ------------------------------------------------------------------ track list
    function buildList() {
        const ul = listEl.querySelector('ul');
        ul.innerHTML = '';
        for (const item of [...tracks, ...markers]) {
            const li = document.createElement('li');
            const btn = document.createElement('button');
            btn.type = 'button';
            const dot = document.createElement('span');
            dot.className = item.kind === 'marker' ? 'dot pin' : 'dot';
            dot.style.background = item.color;
            const name = document.createElement('span');
            name.textContent = item.name;
            const km = document.createElement('small');
            km.textContent = item.kind === 'marker' ? 'Marker' : formatKm(item.distance_km);
            btn.append(dot, name, km);
            btn.addEventListener('click', () => focusItem(item));
            li.append(btn);
            ul.append(li);
        }
    }

    function focusItem(item) {
        if (item.kind === 'marker') {
            globe.pointOfView({ lat: item.lat, lng: item.lng, altitude: Math.min(currentAlt, 0.35) }, 1500);
        } else {
            flyTo(item.segments.flat());
        }
        history.replaceState(null, '', `#${item.kind}=${item.id}`);
        const x = window.innerWidth > 700 ? window.innerWidth / 2 + 40 : 16;
        const y = window.innerWidth > 700 ? window.innerHeight / 2 - 120 : 70;
        pin(item, x, y);
        if (window.innerWidth <= 700) toggleList(false);
    }

    function toggleList(open = listEl.hidden) {
        listEl.hidden = !open;
        listToggle.setAttribute('aria-expanded', String(open));
    }
    listToggle.addEventListener('click', () => toggleList());

    // ------------------------------------------------------------------ init
    const OCEAN_COLOR = '#1d4e7a';

    /**
     * Resolves true once an image has loaded. globe.gl draws nothing until every
     * image it was given has loaded, so a single missing or blocked image (e.g. a
     * CDN error) would leave the whole globe black. Images are only handed over
     * after this check succeeded.
     */
    function whenLoaded(url) {
        return new Promise((resolve) => {
            if (!url) return resolve(false);
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = () => resolve(true);
            img.onerror = () => {
                console.warn(`Globe Link: image could not be loaded and is skipped: ${url}`);
                resolve(false);
            };
            img.src = url;
        });
    }

    function initGlobe(cfg) {
        globe = new Globe(el, { rendererConfig: { antialias: true } })
            .showAtmosphere(true)
            .atmosphereColor('#7cc4ff')
            .atmosphereAltitude(0.15)
            .pathsData(paths)
            .pathPoints('points')
            .pathPointLat((p) => p[0])
            .pathPointLng((p) => p[1])
            .pathResolution(1)
            .pathTransitionDuration(0)
            .onPathHover((path) => {
                hoverItem = path ? path.track : null;
                el.style.cursor = path ? 'pointer' : '';
                if (hoverItem) {
                    if (!pinnedItem || pinnedItem === hoverItem) {
                        showPopup(hoverItem, mouse.x, mouse.y, pinnedItem === hoverItem);
                    }
                } else {
                    scheduleHide();
                }
                refreshPaths();
            })
            .onPathClick((path, event) => {
                pin(path.track, event.clientX, event.clientY);
                history.replaceState(null, '', `#track=${path.track.id}`);
            })
            .onGlobeClick(() => hidePopup())
            .htmlElementsData(markers)
            .htmlLat('lat')
            .htmlLng('lng')
            .htmlAltitude(0)
            .htmlTransitionDuration(0)
            .htmlElement((d) => (d.kind === 'marker' ? markerElement(d) : labelElement(d)))
            // Country borders: outline only, no surfaces.
            .polygonGeoJsonGeometry('geometry')
            .polygonCapColor(() => null)
            .polygonSideColor(() => null)
            .polygonStrokeColor(() => rgba(display.border_color, display.border_opacity))
            .polygonsTransitionDuration(0)
            .onZoom((pov) => {
                // Keep track lines close to the surface at every zoom level.
                const ratio = pov.altitude / currentAlt;
                if (ratio > 1.15 || ratio < 0.87) {
                    currentAlt = pov.altitude;
                    refreshPaths();
                    updateBorders();
                }
                scheduleLabels();
            });

        if (cfg.tileUrl) {
            globe
                .globeTileEngineUrl((x, y, l) => cfg.tileUrl.replace('{x}', x).replace('{y}', y).replace('{z}', l))
                .globeTileEngineMaxLevel(cfg.tileMaxLevel || 17);
        } else {
            whenLoaded(cfg.globeImage).then((ok) => {
                if (ok) globe.globeImageUrl(cfg.globeImage);
                else globe.globeMaterial().color.set(OCEAN_COLOR);
            });
            whenLoaded(cfg.bumpImage).then((ok) => ok && globe.bumpImageUrl(cfg.bumpImage));
        }
        // Plain ocean colour under the map tiles, visible while they load or if they fail.
        if (cfg.tileUrl) globe.globeMaterial().color.set(OCEAN_COLOR);
        whenLoaded(cfg.backgroundImage).then((ok) => ok && globe.backgroundImageUrl(cfg.backgroundImage));
        document.getElementById('attribution').textContent = cfg.tileUrl ? (cfg.tileAttribution || '') : '';

        const resize = () => globe.width(window.innerWidth).height(window.innerHeight);
        window.addEventListener('resize', resize);
        resize();

        el.addEventListener('pointermove', (e) => { mouse = { x: e.clientX, y: e.clientY }; });
        // Hide the hover window while the globe is being dragged.
        globe.controls().addEventListener('start', () => { if (!pinnedItem) hidePopup(); });
    }

    async function load() {
        let cfg;
        try {
            const source = document.querySelector('meta[name="globe-link-data"]')?.content || 'api.php';
            const res = await fetch(source, { headers: { Accept: 'application/json' } });
            if (!res.ok) {
                const body = await res.json().catch(() => null);
                throw new Error(body && body.error ? `HTTP ${res.status}: ${body.error}` : `HTTP ${res.status}`);
            }
            cfg = await res.json();
        } catch (err) {
            statusEl.textContent = `Tracks could not be loaded (${err.message}).`;
            statusEl.classList.add('error');
            return;
        }
        if (cfg.title) {
            document.title = cfg.title;
            document.getElementById('title').textContent = cfg.title;
        }
        linkTargetBlank = cfg.linkTargetBlank !== false;
        if (cfg.display) display = { ...display, ...cfg.display };
        tracks = (cfg.tracks || []).map((t) => ({ ...t, kind: 'track' }));
        markers = (cfg.markers || []).map((m) => ({ ...m, kind: 'marker' }));
        buildPaths();

        initGlobe(cfg);
        refreshPaths();
        updateBorders();
        loadLabels();
        buildList();
        const empty = !tracks.length && !markers.length;
        statusEl.hidden = !empty;
        if (empty) statusEl.textContent = 'No tracks configured yet.';

        const m = location.hash.match(/(track|marker)=(\d+)/);
        const target = m && [...tracks, ...markers].find((t) => t.kind === m[1] && String(t.id) === m[2]);
        if (target) {
            setTimeout(() => focusItem(target), 300);
        } else if (!empty) {
            flyTo([...allPoints(tracks), ...markers.map((mk) => [mk.lat, mk.lng])], 0);
        }
    }

    // Small public API, e.g. for embedding pages: GlobeLink.focus('track', 3)
    window.GlobeLink = {
        get globe() { return globe; },
        focus(kind, id) {
            const item = [...tracks, ...markers].find((t) => t.kind === kind && String(t.id) === String(id));
            if (item) focusItem(item);
        },
    };

    if (typeof Globe === 'undefined') {
        statusEl.textContent = 'The globe library could not be loaded.';
        statusEl.classList.add('error');
    } else {
        load();
    }
})();
