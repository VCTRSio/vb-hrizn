#!/usr/bin/env bash
#
# test-in-app.sh — run the HRIZN Pest suite against a throwaway
# vctrbase-php worktree, inside the app container, using a PRIVATE test database.
#
# HRIZN is an EXTERNAL, uploaded, first-party plugin — it does not live
# in the app tree. Its tests therefore run against a mounted, throwaway app
# worktree:
#
#   1. Restore the private DB ($DB) from the committed schema dump (shared postgres).
#   2. Run the app's in-tree migrations (`artisan migrate`) — this does NOT create
#      the plugin's own tables (the plugin is not installed yet); feature tests
#      install+migrate the plugin in-process, unit tests run its migrations directly.
#   3. Sync this plugin's tests/ into the worktree at tests/Feature/Plugins/VbHrizn/
#      — a path Pest already scans, so the tests inherit the app's TestCase +
#      DatabaseTransactions + the worktree Pest.php beforeEach hooks (sync queue,
#      array cache, CSRF bypass, withoutVite).
#   4. Mount the plugin read-only at /hz-src and pass the VCTRS signing key via env
#      (HZ_PRIV / HZ_PUB) so the signed-install boot test can sign a real ZIP.
#   5. Run Pest against the synced test path (or an optional override arg).
#
# Usage:
#   scripts/test-in-app.sh                                     # whole suite
#   scripts/test-in-app.sh tests/Feature/Plugins/VbHrizn/HriznMigrationsTest.php
#   scripts/test-in-app.sh tests/Feature/Plugins/VbHrizn "--filter=hrizn"
#
# Env overrides:
#   MAIN   app repo (has the running docker compose stack)   default: ../../vctrbase-php
#   WT     throwaway app worktree to mount                    default: ../../vctrbase-php-hrizn-test
#   KEYDIR dir holding vctrs.privkey.b64 / vctrs.pubkey.b64
#   DB     private test database name                         default: vctrs_test_hrizn
set -euo pipefail

PLUGIN="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MAIN="${MAIN:-$(cd "$PLUGIN/../../vctrbase-php" && pwd)}"
WT="${WT:-$(cd "$PLUGIN/../../vctrbase-php-hrizn-test" && pwd)}"
KEYDIR="${KEYDIR:-$(cd "$PLUGIN/../../.plugin-signing-keys" && pwd)}"
DB="${DB:-vctrs_test_hrizn}"

# Optional args: $1 = test path/dir (relative to worktree root), $2… = extra pest flags.
TARGET="${1:-tests/Feature/Plugins/VbHrizn}"
shift || true
EXTRA_ARGS="$*"

PRIV="$(cat "$KEYDIR/vctrs.privkey.b64")"
PUB="$(cat "$KEYDIR/vctrs.pubkey.b64")"

DEST="$WT/tests/Feature/Plugins/VbHrizn"

echo ">> syncing plugin tests → $DEST"
rm -rf "$DEST"
mkdir -p "$DEST"
cp -R "$PLUGIN/tests/." "$DEST/"

# Remove the in-tree hrizn plugin from the throwaway worktree so the in-tree
# copy does not shadow the uploaded install (its manifest declares nav key
# `hrizn` + route `/dashboard/plugins/vb-hrizn/view`, which would collide with
# the adapted feature test's assertions when the uploaded plugin is installed
# in-process). Removing it keeps the harness reproducible (the worktree is a
# throwaway test mount).
if [ -d "$WT/plugins/hrizn" ]; then
  echo ">> removing in-tree plugins/hrizn from worktree ($WT)"
  rm -rf "$WT/plugins/hrizn"
fi

cd "$MAIN"

echo ">> restoring $DB from the schema dump (shared postgres, private DB)…"
docker compose exec -T postgres sh -c \
  "dropdb -U postgres --if-exists $DB >/dev/null 2>&1; \
   createdb -U postgres $DB && \
   psql -q -U postgres -d $DB -f /docker-entrypoint-initdb.d/01-schema.sql >/dev/null 2>&1 && \
   echo '   '$DB' ready'"

echo ">> running pest ($TARGET) in worktree ($WT)…"
docker compose run --rm -T \
  -v "$WT:/var/www/html" \
  -v "$MAIN/vendor:/var/www/html/vendor" \
  -v "$PLUGIN:/hz-src:ro" \
  -e DB_DATABASE="$DB" -e APP_ENV=testing \
  -e HZ_SRC=/hz-src -e HZ_PRIV="$PRIV" -e HZ_PUB="$PUB" \
  app sh -c "rm -rf database/schema 2>/dev/null || true; \
             touch .env; \
             php artisan migrate --force --no-interaction >/dev/null 2>&1 || true; \
             php -d memory_limit=1024M ./vendor/bin/pest $TARGET $EXTRA_ARGS"
