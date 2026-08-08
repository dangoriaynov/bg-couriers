#!/usr/bin/env bash
# Push the plugin to a site. The server it goes to is NOT in this file.
#
# This repository is public. A host name, an SSH port and a login are not secrets in the cryptographic
# sense, but publishing them hands a stranger the first three answers of a break-in attempt for free -
# and they were in here in plain sight, root login included. They now come from bin/deploy.conf, which
# is gitignored, or from the environment.
#
#   cp bin/deploy.conf.example bin/deploy.conf   # then fill in your own
#   ./bin/deploy.sh dev|prod
set -euo pipefail
cd "$(dirname "$0")/.."

[ -f bin/deploy.conf ] && . bin/deploy.conf

TARGET="${1:-dev}"   # dev | prod
: "${BGC_SSH_HOST:?set BGC_SSH_HOST in bin/deploy.conf (see bin/deploy.conf.example)}"
: "${BGC_SSH_PORT:=22}"

case "$TARGET" in
  dev)  DEST="${BGC_DEV_PATH:?set BGC_DEV_PATH in bin/deploy.conf}";;
  prod) DEST="${BGC_PROD_PATH:?set BGC_PROD_PATH in bin/deploy.conf}";
        read -p "Deploy to PROD (${BGC_SSH_HOST})? type yes: " c; [ "$c" = yes ] || exit 1;;
  *) echo "usage: deploy.sh dev|prod"; exit 1;;
esac

rsync -az --delete \
  --exclude '.git' --exclude '.gitignore' --exclude '.superpowers' --exclude '.claude' \
  --exclude '.phpunit.result.cache' --exclude 'tests' --exclude 'node_modules' \
  --exclude 'vendor' --exclude 'docs' --exclude 'bin' --exclude 'e2e' \
  --exclude '.wp-env.json' --exclude 'composer.*' --exclude 'phpunit.xml.dist' \
  --exclude '.wordpress-org' --exclude '.distignore' --exclude '.github' --exclude 'README.md' \
  -e "ssh -p ${BGC_SSH_PORT}" ./ "${BGC_SSH_HOST}:${DEST}"
echo "Synced to ${TARGET}. Activate via wp-admin."
