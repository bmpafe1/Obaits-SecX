#!/bin/bash
# Fetches upstream licence texts for every distributed component.
#
# REAL FINDING 2026-08-24: the original version of this script extracted
# licence files from the RUNNING containers. Confirmed live that this does not
# work -- not one of the deployed images (Fleet, Zabbix, CISO Assistant,
# DFIR-IRIS, lightNVR) carries a LICENSE, COPYING or NOTICE file anywhere in
# its top three directory levels. The Wazuh packages yielded only a 426-byte
# Debian PACKAGING copyright file, not the GPLv2 text.
#
# So licences are fetched from upstream at the deployed version instead. This
# is reproducible, which extraction was not -- but it depends on knowing the
# exact version, which is why unpinned image tags (gap G-06) block this.
set -u
OUT="ops/compliance/licences"
mkdir -p "$OUT"
FAIL=0

get() {
  local name="$1" url="$2"
  if curl -fsSL --max-time 20 "$url" -o "$OUT/$name.txt" 2>/dev/null; then
    echo "  OK      $name  ($(wc -c < "$OUT/$name.txt") bytes)"
  else
    echo "  FAILED  $name  -- fetch manually from $url"
    rm -f "$OUT/$name.txt"
    FAIL=1
  fi
}

echo "Fetching upstream licences (versions per COMPONENT-MANIFEST.md)..."
get "fleet-4.88.0"        "https://raw.githubusercontent.com/fleetdm/fleet/fleet-v4.88.0/LICENSE"
get "wazuh-4.9.2"         "https://raw.githubusercontent.com/wazuh/wazuh/v4.9.2/LICENSE"
get "zabbix-7.0"          "https://raw.githubusercontent.com/zabbix/zabbix/release/7.0/COPYING"
get "ciso-assistant"      "https://raw.githubusercontent.com/intuitem/ciso-assistant-community/main/LICENSE.md"
get "dfir-iris-2.4.29"    "https://raw.githubusercontent.com/dfir-iris/iris-web/v2.4.29/LICENSE.txt"
get "lightnvr"            "https://raw.githubusercontent.com/opensensor/lightNVR/main/LICENSE"
get "headscale"           "https://raw.githubusercontent.com/juanfont/headscale/main/LICENSE"

# REFERENCED LICENCE TEXTS, required for the above to be complete.
# LGPLv3 (DFIR-IRIS) is written as a SUPPLEMENT to GPLv3 and incorporates it by
# reference -- shipping the LGPL alone does not convey the full terms.
# CISO Assistant's LICENSE.md is a dual-licence POINTER, not the AGPL text, so
# the AGPL itself is fetched separately.
get "gpl-3.0-referenced-by-lgpl"  "https://www.gnu.org/licenses/gpl-3.0.txt"
get "agpl-3.0-referenced-by-ciso" "https://www.gnu.org/licenses/agpl-3.0.txt"

echo
echo "Collected into $OUT:"
ls -1 "$OUT"/*.txt 2>/dev/null | sed 's/^/  /' || echo "  (none)"
echo
if [ "$FAIL" -ne 0 ]; then
  echo "Some fetches failed -- the tag or path may differ upstream. Resolve"
  echo "before any release; a missing licence text is a distribution defect."
fi
echo "NOT COVERED HERE: GVM/OpenVAS (mixed licences, see Greenbone's licence"
echo "page) and OWASP ZAP (Apache 2.0 plus bundled third-party notices)."
