import json
import os
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
LANG_DIR = ROOT / 'lang'

def load_json(path):
    with open(path, 'r', encoding='utf-8') as f:
        return json.load(f)

def main():
    files = list(LANG_DIR.glob('*.json'))
    if not files:
        print('No language files found in', LANG_DIR)
        return
    data = {p.stem: load_json(p) for p in files}
    en_keys = set(data.get('en', {}).keys())
    for lang, content in data.items():
        if lang == 'en':
            continue
        missing = sorted(en_keys - set(content.keys()))
        print(f'{lang}: {len(missing)} missing keys')
        for k in missing[:200]:
            print('  ', k)
        if len(missing) > 200:
            print('  ... and', len(missing)-200, 'more')

if __name__ == '__main__':
    main()
