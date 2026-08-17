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
  # The teardown's proof that the run booked nothing - and its cleanup if it did.
  #
  # Turning auto-labelling off should mean there is never anything here. "Should" is not a guarantee: the
  # retry cron can fire one late, and a run killed before its teardown leaves whatever it made. So this
  # CANCELS rather than reporting and hoping - the standing rule is that anything created at a courier is
  # cancelled straight away and its number printed even when the cancel succeeds, because a silent
  # cleanup that failed looks exactly like one that worked.
  #
  # Only ever the suite's OWN orders, matched on the e2e-*@example.com addresses the specs check out
  # with. Everything else is left strictly alone and printed instead: dev carries real test orders the
  # owner made, and voiding one of those to tidy up would be a far worse bug than the one this prevents.
  sweep)
    run "$WP eval '
      foreach (wc_get_orders([\"limit\"=>40,\"orderby\"=>\"ID\",\"order\"=>\"DESC\",\"return\"=>\"objects\"]) as \$o) {
        \$w = (string) \$o->get_meta(\"_bgcouriers_waybill\");
        if (\$w === \"\") { continue; }
        \$mine = (bool) preg_match(\"/^e2e-.*@example\\.com\$/\", (string) \$o->get_billing_email());
        if (!\$mine) { printf(\"KEPT %d %s %s (not this suite - left alone)\n\", \$o->get_id(), \$o->get_meta(\"_bgcouriers_courier\"), \$w); continue; }
        try { BGCouriers_Labels::cancel(\$o->get_id());
              printf(\"CANCELLED %d %s %s\n\", \$o->get_id(), \$o->get_meta(\"_bgcouriers_courier\"), \$w); }
        catch (\Exception \$e) { printf(\"FAILED %d %s %s - %s\n\", \$o->get_id(), \$o->get_meta(\"_bgcouriers_courier\"), \$w, \$e->getMessage()); }
      }'" | tr -d '\r' ;;
  *) echo "usage: dev-option.sh get|set|sweep [name] [value]" >&2; exit 1 ;;
esac
