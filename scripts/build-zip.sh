#!/usr/bin/env bash
#
# build-zip.sh — deterministic builder for the shippable HRIZN plugin ZIP.
#
# The archive ROOT contains ONLY what install + runtime need:
#     manifest.json  +  src/  +  database/  +  dist/
# Everything else (VCS, tests, docs, scripts, tools, .github, marketplace-
# submission, .superpowers, vendor, node_modules, dotfiles) is excluded by
# construction — we stage only the four shipped paths.
#
# Reproducibility: we stage into a temp tree, zero every mtime to a fixed epoch,
# feed a LC_ALL=C-sorted file list to `zip -X` (no uid/gid/extra attributes), so
# repeated builds on the same zip toolchain yield a byte-stable archive. Bit-exact
# reproducibility across different `zip` versions/platforms is a nice-to-have, not
# guaranteed — Task 4/5 regenerate the manifest artifact block from the bytes that
# are actually built, so the digest is always taken from the real output.
#
# Usage:
#   scripts/build-zip.sh [version]      # default version = manifest.json "version"
#
# Prints the resulting path and its sha256.
set -euo pipefail

PLUGIN="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ROOT="$PLUGIN"
cd "$PLUGIN"

# ── Build the ESM bundle so dist/entry.js is fresh in the artifact ──────────────
( cd "$ROOT" && npm run build )
test -f "$ROOT/dist/entry.js" || { echo "dist/entry.js missing after build" >&2; exit 1; }

# ── Resolve version (arg overrides manifest.json) ───────────────────────────────
VERSION="${1:-}"
if [ -z "$VERSION" ]; then
  VERSION="$(grep -oE '"version"[[:space:]]*:[[:space:]]*"[^"]*"' manifest.json \
             | head -1 \
             | sed -E 's/.*"version"[[:space:]]*:[[:space:]]*"([^"]*)".*/\1/')"
fi
if [ -z "$VERSION" ]; then
  echo "build-zip.sh: could not determine version (pass one as \$1 or set manifest.json)" >&2
  exit 1
fi

# ── Sanity: the four shipped paths must exist ───────────────────────────────────
[ -f manifest.json ] || { echo "build-zip.sh: manifest.json missing" >&2; exit 1; }
[ -d src ]           || { echo "build-zip.sh: src/ missing" >&2; exit 1; }
[ -d database ]      || { echo "build-zip.sh: database/ missing" >&2; exit 1; }
[ -d dist ]          || { echo "build-zip.sh: dist/ missing" >&2; exit 1; }

DIST="$PLUGIN/dist"
mkdir -p "$DIST"
ZIP="$DIST/vb-hrizn-$VERSION.zip"
rm -f "$ZIP"

# ── Stage only shipped content (excludes everything else by construction) ───────
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
cp manifest.json "$STAGE/"
cp -R src        "$STAGE/src"
cp -R database   "$STAGE/database"
cp -R dist       "$STAGE/dist"

# Drop any hidden/VCS/OS cruft that may have ridden along under the staged paths.
find "$STAGE" -depth -name '.*' -exec rm -rf {} + 2>/dev/null || true

# Zero every mtime for a reproducible archive (2000-01-01 00:00:00).
find "$STAGE" -exec touch -t 200001010000.00 {} +

# ── Deterministic zip: sorted entry list, stripped extra attributes ─────────────
( cd "$STAGE" \
  && find . -type f | LC_ALL=C sort | sed 's|^\./||' | zip -X -q "$ZIP" -@ )

# Sanity: manifest.json must be at the archive ROOT.
if ! unzip -l "$ZIP" | grep -qE '[[:space:]]manifest\.json$'; then
  echo "build-zip.sh: manifest.json is not at the archive root" >&2
  exit 1
fi

SHA="$(sha256sum "$ZIP" | awk '{print $1}')"
echo "built:  $ZIP"
echo "sha256: $SHA"
