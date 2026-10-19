#!/usr/bin/env bash
#
# Builds the release files: dist/UpdateGuard.zip (folder UpdateGuard/ ready to drop into FreeScout's Modules/),
# dist/version.txt (read by FreeScout's own module updater through "latestVersionUrl"), dist/module.json,
# dist/install.sh and dist/SHA256SUMS. The module's tests are left out; CHANGELOG and LICENSE are included.
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="${ROOT}/dist"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

version="$(sed -n 's/^[[:space:]]*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$ROOT/UpdateGuard/module.json" | head -n 1)"
script_version="$(sed -n 's/^SCRIPT_VERSION="\([^"]*\)".*/\1/p' "$ROOT/install.sh")"
if [ "$version" != "$script_version" ]; then
    printf 'module.json (%s) et install.sh (%s) n'"'"'ont pas la même version.\n' "$version" "$script_version" >&2
    exit 1
fi
if ! grep -q "^## \[${version}\]" "$ROOT/CHANGELOG.md"; then
    printf 'CHANGELOG.md : entrée [%s] manquante.\n' "$version" >&2
    exit 1
fi

cp -R "$ROOT/UpdateGuard" "$STAGE/UpdateGuard"
rm -rf "$STAGE/UpdateGuard/Tests"
cp "$ROOT/CHANGELOG.md" "$ROOT/LICENSE" "$STAGE/UpdateGuard/"
find "$STAGE" -name '.DS_Store' -delete

mkdir -p "$DIST"
rm -f "$DIST/UpdateGuard.zip" "$DIST/SHA256SUMS" "$DIST/module.json" "$DIST/version.txt" "$DIST/FILES.sha256"
if command -v zip >/dev/null 2>&1; then
    (cd "$STAGE" && zip -qrX "$DIST/UpdateGuard.zip" UpdateGuard)
else
    (cd "$STAGE" && python3 -m zipfile -c "$DIST/UpdateGuard.zip" UpdateGuard)
fi
cp "$ROOT/install.sh" "$DIST/install.sh"
cp "$ROOT/UpdateGuard/module.json" "$DIST/module.json"
# plain-text latest version: FreeScout's "latestVersionUrl" convention (app/Console/Commands/ModuleUpdate.php)
printf '%s\n' "$version" >"$DIST/version.txt"
# SHA-256 of every file of the module, as installed (paths relative to UpdateGuard/): UpdateGuard compares its own
# installed files with the FILES.sha256 of its version (SelfCheck). Unlike the ZIP, these hashes are reproducible.
(cd "$STAGE/UpdateGuard" && find . -type f | sed 's|^\./||' | LC_ALL=C sort | while IFS= read -r f; do sha256sum "$f"; done) >"$DIST/FILES.sha256"
(cd "$DIST" && sha256sum UpdateGuard.zip install.sh module.json version.txt FILES.sha256 >SHA256SUMS)
printf 'UpdateGuard %s : %s\n' "$version" "$DIST/UpdateGuard.zip"
cat "$DIST/SHA256SUMS"
