/* Configuration view: place search in the marker form. */
(function () {
    'use strict';

    const box = document.querySelector('.place-search');
    if (!box) return;

    const input = box.querySelector('#place-search');
    const list = box.querySelector('#place-results');
    const status = box.querySelector('.place-status');
    const onlineBtn = box.querySelector('.place-online');
    const form = box.closest('form');
    const nameField = form.querySelector('input[name="name"]');
    const coordField = form.querySelector('input[name="coordinates"]');
    const lang = box.dataset.lang === 'en' ? 1 : 0;

    let places = null;          // [{name, lat, lng, region, country, pop, keys: [...]}]
    let loading = null;
    let results = [];
    let active = -1;
    let timer = null;

    /** "Zürich" -> "zurich", "Straße" -> "strasse" */
    const norm = (s) => String(s).normalize('NFD').replace(/[̀-ͯ]/g, '')
        .toLowerCase().replace(/ß/g, 'ss').replace(/[^\p{L}\p{N}]+/gu, ' ').trim();

    function loadPlaces() {
        if (!loading) {
            loading = fetch('assets/vendor/geo/places.json')
                .then((r) => r.json())
                .then((rows) => {
                    // [name_de, name_en, lat, lng, country_de, country_en, region, population, country codes]
                    places = rows.map((r) => ({
                        name: r[lang] || r[0],
                        lat: r[2], lng: r[3],
                        country: r[4 + lang],
                        region: r[6],
                        pop: r[7],
                        keys: [...new Set([norm(r[0]), norm(r[1])])],
                        where: ` ${norm(`${r[4]} ${r[5]} ${r[6]} ${r[8] || ''}`)} `,
                    }));
                })
                .catch(() => { status.textContent = 'The place list could not be loaded – use “Search online”.'; places = []; });
        }
        return loading;
    }

    /**
     * Local search. Ranking: exact name, name starts with the query, a word of the name
     * starts with it, name contains it; then by population. "Paris, USA" also filters by
     * country or region.
     */
    function searchLocal(query) {
        const [namePart, ...whereParts] = query.split(',');
        const q = norm(namePart);
        const where = norm(whereParts.join(' '));
        if (q.length < 2) return [];
        const found = [];
        for (const p of places) {
            let score = 9;
            for (const k of p.keys) {
                const s = k === q ? 0 : k.startsWith(q) ? 1 : k.includes(` ${q}`) ? 2 : k.includes(q) ? 3 : 9;
                score = Math.min(score, s);
            }
            // Country/region part: a whole word ("usa", "de") or the start of a name ("bay", "frank").
            if (score < 9 && (!where || p.where.includes(` ${where}`))) found.push([score, p]);
        }
        found.sort((a, b) => a[0] - b[0] || b[1].pop - a[1].pop);
        return found.slice(0, 8).map((f) => f[1]);
    }

    function formatPop(n) {
        if (!n) return '';
        return n >= 1e6 ? `${(n / 1e6).toFixed(1)} M inhabitants` : `${Math.round(n / 1000)}k inhabitants`;
    }

    function render(items, source) {
        results = items;
        active = items.length ? 0 : -1;
        list.innerHTML = '';
        items.forEach((p, i) => {
            const li = document.createElement('li');
            li.id = `place-option-${i}`;
            li.setAttribute('role', 'option');
            const name = document.createElement('strong');
            name.textContent = p.name;
            const detail = document.createElement('span');
            detail.className = 'muted';
            detail.textContent = [p.region, p.country].filter(Boolean).join(', ');
            const extra = document.createElement('small');
            extra.className = 'muted';
            extra.textContent = [p.type, formatPop(p.pop), `${p.lat.toFixed(4)}, ${p.lng.toFixed(4)}`].filter(Boolean).join(' · ');
            li.append(name, detail, extra);
            li.addEventListener('mousedown', (e) => { e.preventDefault(); choose(i); });
            list.append(li);
        });
        if (source === 'osm' && items.length) {
            const credit = document.createElement('li');
            credit.className = 'credit small muted';
            credit.textContent = 'Search results © OpenStreetMap contributors';
            list.append(credit);
        }
        list.hidden = !items.length;
        input.setAttribute('aria-expanded', String(!!items.length));
        highlight();
    }

    function highlight() {
        [...list.querySelectorAll('[role=option]')].forEach((li, i) => li.classList.toggle('active', i === active));
        if (active >= 0) input.setAttribute('aria-activedescendant', `place-option-${active}`);
        else input.removeAttribute('aria-activedescendant');
    }

    function choose(i) {
        const p = results[i];
        if (!p) return;
        nameField.value = p.name;
        coordField.value = `${p.lat}, ${p.lng}`;
        status.textContent = `Filled in: ${[p.name, p.region, p.country].filter(Boolean).join(', ')} (${p.lat}, ${p.lng}).`;
        input.value = p.name;
        render([]);
        for (const f of [nameField, coordField]) {
            f.classList.remove('flash-fill');
            void f.offsetWidth; // restart the highlight animation
            f.classList.add('flash-fill');
        }
    }

    input.addEventListener('focus', loadPlaces);
    input.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(async () => {
            await loadPlaces();
            const items = searchLocal(input.value);
            render(items, 'local');
            status.textContent = input.value.trim().length >= 2 && !items.length
                ? 'No city found in the built-in list – try “Search online” for smaller places.' : '';
        }, 80);
    });
    input.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            if (!results.length) return;
            e.preventDefault();
            active = (active + (e.key === 'ArrowDown' ? 1 : -1) + results.length) % results.length;
            highlight();
        } else if (e.key === 'Enter') {
            e.preventDefault(); // never submit the marker form from the search field
            if (active >= 0 && !list.hidden) choose(active);
            else onlineBtn.click();
        } else if (e.key === 'Escape') {
            render([]);
        }
    });
    // Close the list when the focus leaves the search box (not when it moves to the
    // "Search online" button, and not while an online search is running).
    input.addEventListener('blur', () => setTimeout(() => {
        if (!box.contains(document.activeElement) && !onlineBtn.disabled) render([]);
    }, 150));

    onlineBtn.addEventListener('click', async () => {
        const q = input.value.trim();
        if (q.length < 2) {
            input.focus();
            status.textContent = 'Type a place name first.';
            return;
        }
        clearTimeout(timer); // a pending local search must not replace the online results
        onlineBtn.disabled = true;
        status.textContent = 'Searching OpenStreetMap…';
        try {
            const res = await fetch(`admin.php?geocode=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
            render(data.results, 'osm');
            status.textContent = data.results.length ? '' : `Nothing found for “${q}”.`;
            if (data.results.length) input.focus();
        } catch (err) {
            status.textContent = `Online search failed: ${err.message}`;
        } finally {
            onlineBtn.disabled = false;
        }
    });
})();
