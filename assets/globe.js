/* global Globe */
(function () {
    'use strict';

    const el = document.getElementById('globe');
    const popup = document.getElementById('popup');
    const statusEl = document.getElementById('status');
    const listEl = document.getElementById('track-list');
    const listToggle = document.getElementById('list-toggle');

    const HIDE_DELAY = 350;          // ms before the hover window disappears
    const STROKE = 3;                // track line width in px (fat lines render in screen pixels)
    const HOVER_STROKE = 6;
    const HIT_STROKE = 16;           // invisible, wider copy of each line that makes hovering easier

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

    function refreshPaths() {
        if (!globe) return;
        // Keep the lines just above the surface: high enough not to clip into it, low enough
        // not to drift visibly away from the ground when zoomed in.
        const alt = Math.max(currentAlt * 0.01, 0.000003);
        globe
            .pathStroke((p) => (p.hit ? HIT_STROKE : isActive(p.track) ? HOVER_STROKE : STROKE))
            .pathColor((p) => (p.hit ? 'rgba(0,0,0,0)' : isActive(p.track) ? '#ffffff' : p.track.color))
            .pathPointAlt(alt);
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
    function initGlobe(cfg) {
        globe = new Globe(el, { rendererConfig: { antialias: true } })
            .backgroundImageUrl(cfg.backgroundImage || null)
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
            .htmlElement(markerElement)
            .onZoom((pov) => {
                // Keep track lines close to the surface at every zoom level.
                const ratio = pov.altitude / currentAlt;
                if (ratio > 1.15 || ratio < 0.87) {
                    currentAlt = pov.altitude;
                    refreshPaths();
                }
            });

        if (cfg.tileUrl) {
            globe
                .globeTileEngineUrl((x, y, l) => cfg.tileUrl.replace('{x}', x).replace('{y}', y).replace('{z}', l))
                .globeTileEngineMaxLevel(cfg.tileMaxLevel || 17);
        } else {
            globe.globeImageUrl(cfg.globeImage).bumpImageUrl(cfg.bumpImage || null);
        }
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
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
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
        tracks = (cfg.tracks || []).map((t) => ({ ...t, kind: 'track' }));
        markers = (cfg.markers || []).map((m) => ({ ...m, kind: 'marker' }));
        paths = tracks.flatMap((track) => track.segments.flatMap((points) => [
            { track, points },
            { track, points, hit: true },
        ]));

        initGlobe(cfg);
        refreshPaths();
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
