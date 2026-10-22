"""Merge English base translations into other locale files.

Usage:
  python3 tools/translation_merge.py

This will:
 - read lang/en.json as the source of truth
 - for each lang/*.json (except en.json) create a merged file that contains
   all keys present in en.json, keeping existing translations when present
   and copying the English value as a placeholder when missing.
 - write a small report to tools/missing_keys_report.txt
"""
import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
LANG_DIR = ROOT / 'lang'
OUT_REPORT = ROOT / 'tools' / 'missing_keys_report.txt'


def load_json(path: Path):
    with open(path, 'r', encoding='utf-8') as f:
        return json.load(f)


def write_json(path: Path, data):
    with open(path, 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False, indent=4, sort_keys=False)


def main():
    en_path = LANG_DIR / 'en.json'
    if not en_path.exists():
        print('en.json not found in', LANG_DIR)
        return

    en = load_json(en_path)
    locales = [p for p in LANG_DIR.glob('*.json') if p.stem != 'en']

    report_lines = []
    for p in sorted(locales):
        data = load_json(p)
        merged = {}
        missing = []
        for k, v in en.items():
            if k in data and data[k] is not None and str(data[k]).strip() != '':
                merged[k] = data[k]
            else:
                merged[k] = v  # fallback to English placeholder
                missing.append(k)

        # Backup original file
        backup = p.with_suffix(p.suffix + '.bak')
        p.rename(backup)
        write_json(p, merged)

        report_lines.append(f'{p.name}: {len(missing)} missing keys (filled with English)')
        for k in missing:
            report_lines.append('  ' + k)

    OUT_REPORT.parent.mkdir(parents=True, exist_ok=True)
    with open(OUT_REPORT, 'w', encoding='utf-8') as f:
        f.write('\n'.join(report_lines))

    print('Merge complete. See', OUT_REPORT)


if __name__ == '__main__':
    main()
