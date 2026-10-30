#!/usr/bin/env python3
"""
Build the compact map overlay data in assets/vendor/geo/ from Natural Earth
(public domain, https://www.naturalearthdata.com/):

  borders-50m.json   land borders between countries, coarse (zoomed out)
  borders-10m.json   land borders between countries, detailed (zoomed in)
  countries.json     country label positions:  [name_de, name_en, lat, lng, min_zoom]
  cities.json        populated places:          [name_de, name_en, lat, lng, min_zoom, capital]
  places.json        place search in the configuration view:
                     [name_de, name_en, lat, lng, country_de, country_en, region, population, country codes]

Usage:  python3 tools/build-geodata.py [folder with the Natural Earth .geojson files]
Files that are missing in that folder are downloaded from GitHub.
"""
import json
import os
import sys
import urllib.request

SOURCE = 'https://raw.githubusercontent.com/nvkelso/natural-earth-vector/master/geojson/'
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'assets', 'vendor', 'geo')


def load(folder, name):
    path = os.path.join(folder, name + '.geojson')
    if not os.path.exists(path):
        print('downloading', name)
        urllib.request.urlretrieve(SOURCE + name + '.geojson', path)
    with open(path, encoding='utf-8') as f:
        return json.load(f)


def write(name, data):
    path = os.path.join(OUT, name)
    with open(path, 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False, separators=(',', ':'))
    print(f'{name}: {os.path.getsize(path) // 1024} KB')


def lines(geojson, digits):
    out = []
    for feature in geojson['features']:
        geom = feature['geometry']
        parts = [geom['coordinates']] if geom['type'] == 'LineString' else geom['coordinates']
        for part in parts:
            line = []
            for lng, lat, *_ in part:
                p = [round(lng, digits), round(lat, digits)]
                if not line or line[-1] != p:
                    line.append(p)
            if len(line) > 1:
                out.append(line)
    return out


def main():
    folder = sys.argv[1] if len(sys.argv) > 1 else os.path.join(ROOT, 'data', 'naturalearth')
    os.makedirs(folder, exist_ok=True)
    os.makedirs(OUT, exist_ok=True)

    write('borders-50m.json', lines(load(folder, 'ne_50m_admin_0_boundary_lines_land'), 2))
    write('borders-10m.json', lines(load(folder, 'ne_10m_admin_0_boundary_lines_land'), 3))

    countries = []
    country_names = {}
    for f in load(folder, 'ne_50m_admin_0_countries')['features']:
        p = f['properties']
        country_names[p['ADM0_A3']] = (p.get('NAME_DE') or p['NAME'], p.get('NAME_EN') or p['NAME'])
        if p.get('LABEL_X') is None:
            continue
        en = p.get('NAME_EN') or p['NAME']
        countries.append([p.get('NAME_DE') or en, en, round(p['LABEL_Y'], 3), round(p['LABEL_X'], 3), p.get('MIN_LABEL') or 5])
    countries.sort(key=lambda c: c[4])
    write('countries.json', countries)

    cities = []
    places = []
    for f in load(folder, 'ne_10m_populated_places')['features']:
        p = f['properties']
        en = p.get('NAME_EN') or p['NAME']
        country_de, country_en = country_names.get(p['ADM0_A3'], (p['ADM0NAME'], p['ADM0NAME']))
        places.append([p.get('NAME_DE') or en, en, round(p['LATITUDE'], 5), round(p['LONGITUDE'], 5),
                       country_de, country_en, p.get('ADM1NAME') or '', int(p.get('POP_MAX') or 0),
                       ' '.join(sorted({c for c in (p.get('ISO_A2'), p.get('ADM0_A3'), p.get('SOV_A3')) if c and c != '-99'}))])
        if (p.get('MIN_ZOOM') or 99) > 7:
            continue
        en = p.get('NAME_EN') or p['NAME']
        capital = 1 if p.get('ADM0CAP') == 1 else 0
        cities.append([p.get('NAME_DE') or en, en, round(p['LATITUDE'], 3), round(p['LONGITUDE'], 3), p['MIN_ZOOM'], capital])
    # Most important first: the globe keeps the first labels when they overlap.
    cities.sort(key=lambda c: (c[4], -c[5]))
    write('cities.json', cities)
    places.sort(key=lambda c: -c[7])
    write('places.json', places)


if __name__ == '__main__':
    main()
