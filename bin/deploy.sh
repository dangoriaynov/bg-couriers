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

# Untranslated strings are caught HERE, not at release time. bin/preflight blocks a release on them,
# but that is the last gate - and the owner sees dev long before then. He found "Return waybill" sitting
# in English on a Bulgarian settings screen; it had been added and the catalogue never regenerated.
# A warning rather than a refusal: dev is where work in progress belongs.
if command -v msgattrib >/dev/null 2>&1 && [ -f languages/bg-couriers-bg_BG.po ]; then
  N="$(msgattrib --untranslated languages/bg-couriers-bg_BG.po | grep -c '^msgid "' || true)"
  # 1 header entry + 1 deliberately untranslated plugin name.
  if [ "$N" -gt 2 ]; then
    printf '\033[31m!! %s string(s) have no Bulgarian.\033[0m Run make-pot + msgmerge + msgfmt.\n' "$((N - 2))" >&2
    msgattrib --untranslated languages/bg-couriers-bg_BG.po | grep '^msgid "' | grep -v '^msgid ""' \
      | grep -v 'BG Couriers for WooCommerce' | head -5 | sed 's/^/   /' >&2
  fi
  if [ languages/bg-couriers-bg_BG.po -nt languages/bg-couriers-bg_BG.mo ]; then
    printf '\033[31m!! .po is newer than .mo\033[0m - the translation will not appear. Run msgfmt.\n' >&2
  fi
fi

rsync -az --delete \
  --exclude '.git' --exclude '.gitignore' --exclude '.superpowers' --exclude '.claude' \
  --exclude '.phpunit.result.cache' --exclude 'tests' --exclude 'node_modules' \
  --exclude 'test-results' --exclude 'playwright-report' \
  --exclude 'vendor' --exclude 'docs' --exclude 'bin' --exclude 'e2e' \
  --exclude '.wp-env.json' --exclude 'composer.*' --exclude 'phpunit.xml.dist' \
  --exclude '.wordpress-org' --exclude '.distignore' --exclude '.github' --exclude 'README.md' --exclude 'CONTRIBUTING.md' \
  -e "ssh -p ${BGC_SSH_PORT}" ./ "${BGC_SSH_HOST}:${DEST}"
echo "Synced to ${TARGET}. Activate via wp-admin."
