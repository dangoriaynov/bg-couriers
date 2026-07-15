#!/usr/bin/env bash
set -euo pipefail
TARGET="${1:-dev}"   # dev | prod
HOST="root@REDACTED-HOST"; PORT=REDACTED
case "$TARGET" in
  dev)  DEST="/home/dobavki/dev.dobavki.club/wp-content/plugins/bg-couriers/";;
  prod) DEST="/home/dobavki/public_html/wp-content/plugins/bg-couriers/";
        read -p "Deploy to PROD dobavki.club? type yes: " c; [ "$c" = yes ] || exit 1;;
  *) echo "usage: deploy.sh dev|prod"; exit 1;;
esac
rsync -az --delete \
  --exclude '.git' --exclude '.gitignore' --exclude '.superpowers' --exclude '.claude' \
  --exclude '.phpunit.result.cache' --exclude 'tests' --exclude 'node_modules' \
  --exclude 'vendor' --exclude 'docs' --exclude 'bin' --exclude 'e2e' \
  --exclude '.wp-env.json' --exclude 'composer.*' --exclude 'phpunit.xml.dist' \
  --exclude '.wordpress-org' --exclude '.distignore' --exclude '.github' --exclude 'README.md' \
  -e "ssh -p ${PORT}" ./ "${HOST}:${DEST}"
echo "Synced to ${TARGET}. Activate via wp-admin (wp-cli is blocked over SSH)."
