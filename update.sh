#!/usr/bin/env bash
# BearerMail: update this server to the latest version on GitHub, in one go.
#
#   ./update.sh                 latest main (the normal case)
#   ./update.sh some-branch     a test branch, when you've been asked to try one
#
# Backs up first, then downloads, switches to it, and rebuilds.
set -euo pipefail
cd "$(dirname "$0")"

branch="${1:-main}"

echo "==> Backing up"
docker compose exec -T php php bin/console app:backup || echo "   (backup skipped: the site isn't running)"

echo "==> Downloading the latest code"
git fetch origin "$branch"
git reset --hard "origin/$branch"
git log -1 --oneline

echo "==> Rebuilding and restarting"
# Stamp the build with the commit (and the nearest release tag), so Admin →
# Updates can say whether this installation is behind its channel.
APP_COMMIT="$(git rev-parse HEAD)"
APP_VERSION="$(git describe --tags --abbrev=0 2>/dev/null || true)"
export APP_COMMIT APP_VERSION
docker compose --profile mail up -d --build

echo "==> Done. Running: $(git log -1 --format='%h %s')"
