Translation tools
=================

This folder contains helper scripts to audit and sync translation files.

Files:
- translation_audit.py  : list missing keys per locale (read-only)
- translation_merge.py  : merge en.json into other locale files, preserving translations

Run the merge locally (it will back up originals with .bak suffix):

```bash
python3 tools/translation_merge.py
```

After running you'll find `tools/missing_keys_report.txt` with details.
