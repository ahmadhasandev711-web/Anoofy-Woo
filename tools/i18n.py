#!/usr/bin/env python3
"""Regenerate languages/anoofy-woo.pot, languages/anoofy-woo-ar.po and the compiled .mo.

Usage: python3 tools/i18n.py   (needs `pip install polib`)
Fails if a source string has no Arabic translation in tools/ar.json, so a new string cannot ship untranslated.
"""
import glob, json, re, sys
import polib

DOMAIN = 'anoofy-woo'
RX = re.compile(r"\b(?:__|esc_html__|esc_attr__|_e|esc_html_e|esc_attr_e)\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'" + DOMAIN + "'")

refs = {}
for f in sorted(glob.glob('src/**/*.php', recursive=True)) + ['anoofy-woo.php']:
    text = open(f, encoding='utf-8').read()
    for m in RX.finditer(text):
        msgid = m.group(1).replace("\\'", "'").replace('\\\\', '\\')
        line = text.count('\n', 0, m.start()) + 1
        refs.setdefault(msgid, []).append(f'{f}:{line}')

ar = json.load(open('tools/ar.json', encoding='utf-8'))
missing = [m for m in refs if m not in ar]
unused = [m for m in ar if m not in refs]
if missing:
    print('Missing Arabic translation for:'); [print('  ', m) for m in missing]; sys.exit(1)
if unused:
    print('Warning: unused translations in tools/ar.json:'); [print('  ', m) for m in unused]

def build(lang):
    po = polib.POFile()
    po.metadata = {
        'Project-Id-Version': 'Anoofy for WooCommerce',
        'Language': lang or 'en',
        'MIME-Version': '1.0',
        'Content-Type': 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding': '8bit',
    }
    if lang == 'ar':
        po.metadata['Plural-Forms'] = 'nplurals=6; plural=n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 ? 4 : 5;'
    for msgid, where in refs.items():
        po.append(polib.POEntry(msgid=msgid, msgstr=ar[msgid] if lang == 'ar' else '', occurrences=[(w.split(':')[0], w.split(':')[1]) for w in where]))
    return po

build(None).save('languages/anoofy-woo.pot')
po = build('ar')
po.save('languages/anoofy-woo-ar.po')
po.save_as_mofile('languages/anoofy-woo-ar.mo')
for f in ['languages/anoofy-woo.pot', 'languages/anoofy-woo-ar.po']:
    raw = open(f, 'rb').read().replace(b'\r\n', b'\n')
    with open(f, 'wb') as fp:
        fp.write(raw)
print(f'{len(refs)} strings; wrote languages/anoofy-woo.pot, anoofy-woo-ar.po, anoofy-woo-ar.mo')
