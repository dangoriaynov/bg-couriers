#!/usr/bin/env bash
# Push the plugin to a site. The repo is public: hosts, ports and logins come only from bin/deploy.conf
# (gitignored) or the environment.
#   cp bin/deploy.conf.example bin/deploy.conf   # then fill in your own
#   ./bin/deploy.sh dev|prod
set -euo pipefail
cd "$(dirname "$0")/.."

[ -f bin/deploy.conf ] && . bin/deploy.conf

TARGET="${1:-dev}"   # dev | prod
if [ -z "${BGC_LXC_HOST:-}" ]; then
  : "${BGC_SSH_HOST:?set BGC_SSH_HOST (or BGC_LXC_HOST) in bin/deploy.conf (see bin/deploy.conf.example)}"
fi
: "${BGC_SSH_PORT:=22}"

case "$TARGET" in
  dev)  DEST="${BGC_DEV_PATH:?set BGC_DEV_PATH in bin/deploy.conf}"; LXC="${BGC_LXC_DEV:-}"; OWNER="${BGC_DEV_OWNER:-}"
        LXC_HOST="${BGC_LXC_DEV_HOST:-${BGC_LXC_HOST:-}}"; LXC_PORT="${BGC_LXC_DEV_PORT:-${BGC_LXC_PORT:-22}}";;
  prod) DEST="${BGC_PROD_PATH:?set BGC_PROD_PATH in bin/deploy.conf}"; LXC="${BGC_LXC_PROD:-}"; OWNER="${BGC_PROD_OWNER:-}"
        LXC_HOST="${BGC_LXC_HOST:-}"; LXC_PORT="${BGC_LXC_PORT:-22}";
        read -p "Deploy to PROD (${LXC:-$BGC_SSH_HOST})? type yes: " c; [ "$c" = yes ] || exit 1;;
  *) echo "usage: deploy.sh dev|prod"; exit 1;;
esac
# A half-configured container route must not fall back to plain SSH (wrong machine).
if [ -n "$LXC_HOST" ] && [ -z "$LXC" ]; then
  echo "BGC_LXC_HOST is set but no container is named for '$TARGET' - set BGC_LXC_DEV / BGC_LXC_PROD" >&2
  exit 1
fi

# Untranslated strings warn here (preflight refuses them at release).
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

EXCLUDES=(
  --exclude '.git' --exclude '.gitignore' --exclude '.superpowers' --exclude '.claude'
  --exclude '.phpunit.result.cache' --exclude 'tests' --exclude 'node_modules'
  --exclude 'test-results' --exclude 'playwright-report'
  --exclude 'vendor' --exclude 'docs' --exclude 'bin' --exclude 'e2e'
  --exclude '.wp-env.json' --exclude 'composer.*' --exclude 'phpunit.xml.dist'
  --exclude '.wordpress-org' --exclude '.distignore' --exclude '.github'
  --exclude 'README.md' --exclude 'CONTRIBUTING.md' --exclude 'CLAUDE.md'
  # Untracked local files that Plugin Check on dev would refuse; build-zip never sees them, rsync/tar does.
  --exclude 'tmp'
  --exclude '.DS_Store' --exclude '._*'
)
# Stops macOS bsdtar from packing `._name` AppleDouble entries.
export COPYFILE_DISABLE=1

if [ -n "$LXC_HOST" ]; then
  # Via the LXC host: tar into a sibling dir, then one rsync --delete inside the container swaps it in.
  # Ownership is configured (BGC_*_OWNER), never guessed; unset leaves it as found.
  # REMOTE travels inside a single-quoted `bash -c`: no apostrophes or comments in it.
  REMOTE="set -e
T=${DEST%/}.deploy.\$\$
rm -rf \"\$T\"; mkdir -p \"\$T\"
tar xzf - -C \"\$T\"
mkdir -p \"${DEST}\"
rsync -a --delete \"\$T/\" \"${DEST%/}/\"
rm -rf \"\$T\""
  if [ -n "${OWNER:-}" ]; then
    REMOTE="${REMOTE}
chown -R ${OWNER} \"${DEST}\""
  fi
  tar czf - "${EXCLUDES[@]}" . \
    | ssh -o ConnectTimeout=25 -p "$LXC_PORT" "$LXC_HOST" "lxc exec ${LXC} -- bash -c $(printf '%q' "$REMOTE")"
  echo "Synced to ${TARGET} (${LXC}). Activate via wp-admin."
else
  rsync -az --delete "${EXCLUDES[@]}" -e "ssh -p ${BGC_SSH_PORT}" ./ "${BGC_SSH_HOST}:${DEST}"
  echo "Synced to ${TARGET}. Activate via wp-admin."
fi

# Warn (read-only) when the last release never reached WordPress.org.
if [ "$TARGET" = dev ]; then
  bash bin/release-status --quiet || true
fi
