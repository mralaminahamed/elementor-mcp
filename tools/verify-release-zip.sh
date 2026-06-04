#!/usr/bin/env bash
#
# Verify a built release zip before publishing.
#
# Guards against the GitHub issue #44 defect, where security software on the
# build machine renamed assets/js/admin.js -> admin.j_ inside the packaged zip,
# 404ing the script and silently breaking the Connection-tab config generator.
# Run this on BOTH zips as the last step before uploading a release.
#
# Usage:
#   tools/verify-release-zip.sh releases/emcp-tools-X.Y.Z.zip
#   tools/verify-release-zip.sh releases/emcp-pro-X.Y.Z.zip
#
# Exit code 0 = well-formed, 1 = problem found (do not publish).

set -euo pipefail

SZ="${SEVENZIP:-/c/Program Files/7-Zip/7z.exe}"
zip="${1:-}"

if [ -z "$zip" ] || [ ! -f "$zip" ]; then
	echo "usage: $0 <release-zip>" >&2
	exit 2
fi
if [ ! -x "$SZ" ]; then
	if command -v 7z >/dev/null 2>&1; then SZ="7z"; else
		echo "7-Zip not found (set SEVENZIP=/path/to/7z.exe)" >&2
		exit 2
	fi
fi

# One normalized (forward-slash) path per line.
listing="$("$SZ" l -slt "$zip" 2>/dev/null | sed -n 's/^Path = //p' | tr '\\' '/')"

fail=0

need() {
	if ! printf '%s\n' "$listing" | grep -qiE "(^|/)$1\$"; then
		echo "  MISSING: $1"
		fail=1
	fi
}

echo "Verifying $(basename "$zip") ..."

# Required, correctly-named assets.
need "assets/js/admin.js"
need "assets/css/admin.css"
need "readme.txt"
need "emcp-tools.php"
need "bin/mcp-proxy.mjs"

# Mangled / quarantined names: the #44 defect (admin.js -> admin.j_) plus the
# common AV / host patterns for neutralized scripts and PHP.
mangled="$(printf '%s\n' "$listing" | grep -iE "/admin\.(j_|js_|_s)\$|\.(php_|js_|j_)\$" || true)"
if [ -n "$mangled" ]; then
	echo "  MANGLED asset name(s) found:"
	printf '%s\n' "$mangled" | sed 's/^/    /'
	echo "  -> a .js/.php file was renamed (likely antivirus on the build machine)."
	echo "     Rebuild the zip on a machine without .js defang, or restore the name."
	fail=1
fi

if [ "$fail" -eq 0 ]; then
	echo "OK -- $(basename "$zip") is well-formed."
else
	echo "FAILED -- do NOT publish $(basename "$zip")."
	exit 1
fi
