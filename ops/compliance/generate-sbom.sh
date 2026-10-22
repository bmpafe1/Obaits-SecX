#!/bin/bash
# Generates a CycloneDX SBOM per release.
# Guideline section 9 / release gate 13.
#
# Covers PHP and JS dependencies. Capability services are separately deployed
# containers and are recorded in COMPONENT-MANIFEST.md instead -- an SBOM of
# this repository does not describe them.
set -eu
OUT="ops/compliance/sbom"
STAMP=$(date +%Y%m%d)
REF=$(git rev-parse --short HEAD 2>/dev/null || echo nogit)
mkdir -p "$OUT"

echo "Generating PHP SBOM..."
composer CycloneDX:make-sbom --output-format=JSON \
  --output-file="$OUT/sbom-php-$STAMP-$REF.json" 2>/dev/null \
  || echo "  SKIPPED: run 'composer require --dev cyclonedx/cyclonedx-php-composer' first"

echo "Generating JS SBOM..."
if [ -f package-lock.json ]; then
  npx --yes @cyclonedx/cyclonedx-npm --output-file "$OUT/sbom-js-$STAMP-$REF.json" 2>/dev/null \
    || echo "  SKIPPED: @cyclonedx/cyclonedx-npm unavailable"
else
  echo "  SKIPPED: no package-lock.json"
fi

echo
ls -la "$OUT" 2>/dev/null | tail -5
echo
echo "Archive these with the release. Gate 13 requires an SBOM per release,"
echo "not merely the ability to generate one."
