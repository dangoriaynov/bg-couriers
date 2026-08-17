#!/usr/bin/env bash
# Read or write a single WordPress option on the DEV site.
#
# Exists for global-setup.js, which has to turn auto-labelling off for the length of a run. The SSH and
# wp-cli incantation is identical to bin/deploy.sh's and is kept here rather than inlined into
# JavaScript, where quoting it three times over is how it would come to be wrong.
#
#   e2e/dev-option.sh get bgcouriers_autolabel_enabled
#   e2e/dev-option.sh set bgcouriers_autolabel_enabled no
set -euo pipefail
cd "$(dirname "$0")/.."

[ -f bin/deploy.conf ] || { echo "bin/deploy.conf is missing" >&2; exit 1; }
. bin/deploy.conf
: "${BGC_SSH_HOST:?set BGC_SSH_HOST in bin/deploy.conf}"
: "${BGC_DEV_PATH:?set BGC_DEV_PATH in bin/deploy.conf}"

DEVROOT="$(dirname "$(dirname "$(dirname "${BGC_DEV_PATH%/}")")")"
WP="/opt/alt/php-fpm83/usr/bin/php /usr/local/bin/wp --allow-root --path=${DEVROOT}"
# The host's login shell prints a manpath locale warning on every connection; it would otherwise end up
# in the value this script returns.
run() { ssh -p "${BGC_SSH_PORT:-22}" "$BGC_SSH_HOST" "$@" 2>/dev/null; }

case "${1:-}" in
  get) run "$WP option get $2" | tr -d '\r' ;;
  set) run "$WP option update $2 $3" >/dev/null ;;
  # Every waybill on a recent order, printed as "<order id> <courier> <waybill>". The teardown's proof
  # that the run booked nothing - and, if it did, the NUMBERS, on screen, rather than a discovery weeks
  # later in the courier's own panel.
  waybills)
    run "$WP eval '
      foreach (wc_get_orders([\"limit\"=>40,\"orderby\"=>\"ID\",\"order\"=>\"DESC\",\"return\"=>\"objects\"]) as \$o) {
        \$w = \$o->get_meta(\"_bgcouriers_waybill\");
        if (\$w !== \"\") { printf(\"%d %s %s\n\", \$o->get_id(), \$o->get_meta(\"_bgcouriers_courier\"), \$w); }
      }'" | tr -d '\r' ;;
  *) echo "usage: dev-option.sh get|set|waybills [name] [value]" >&2; exit 1 ;;
esac
