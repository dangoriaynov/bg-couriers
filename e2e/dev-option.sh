#!/usr/bin/env bash
# Read or write a single WordPress option on the DEV site.
#
# Exists for global-setup.js, which has to turn auto-labelling off for the length of a run. The SSH and
# wp-cli incantation is identical to bin/deploy.sh's and is kept here rather than inlined into
# JavaScript, where quoting it three times over is how it would come to be wrong.
#
#   e2e/dev-option.sh get bgcouriers_autolabel_enabled
#   e2e/dev-option.sh set bgcouriers_autolabel_enabled no
#   e2e/dev-option.sh label 1234        # book the waybill for order 1234 (the international spec)
#   e2e/dev-option.sh cancel 1234       # and void it again
#   e2e/dev-option.sh gateway bacs yes  # switch a payment gateway on for one spec (no value = read it)
set -euo pipefail
cd "$(dirname "$0")/.."

[ -f bin/deploy.conf ] || { echo "bin/deploy.conf is missing" >&2; exit 1; }
. bin/deploy.conf
if [ -z "${BGC_LXC_HOST:-}" ]; then : "${BGC_SSH_HOST:?set BGC_SSH_HOST (or BGC_LXC_HOST) in bin/deploy.conf}"; fi
: "${BGC_DEV_PATH:?set BGC_DEV_PATH in bin/deploy.conf}"

DEVROOT="$(dirname "$(dirname "$(dirname "${BGC_DEV_PATH%/}")")")"
# The PHP binary is the host's business, not this script's: the old CWP box hid its CLI php under
# /opt/alt and would not run the plain one, while an ordinary container has php on the PATH. Set
# BGC_WP_BIN in deploy.conf where it differs; the default is what a normal machine has.
WP="${BGC_WP_BIN:-wp} --allow-root --path=${DEVROOT}"
# The host's login shell prints a manpath locale warning on every connection; it would otherwise end up
# in the value this script returns.
#
# The timeouts are not politeness. Playwright calls this through execFileSync, which blocks the worker's
# event loop, so while ssh waits NOTHING else in the run can happen - not the spec's own 90s timeout, not
# the teardown that sweeps for waybills. A laptop that slept mid-command left one of these hanging for
# thirteen hours with a freshly booked waybill on a live courier account on the other side of it. Now the
# connection dies within a minute and the spec fails, which is a thing that can be seen and acted on.
#
# Two ways in, because dev is not always a machine you can SSH into. It now lives in an LXC container
# behind a host: BGC_LXC_HOST is the machine that holds the containers and BGC_LXC_DEV names the one to
# run in. Set neither and this behaves exactly as it always did, so nothing changes for a plain host.
#
# Getting this wrong is not a cosmetic failure. This script is what turns auto-labelling OFF before the
# suite runs, and dev shares a LIVE courier account: pointing it at the wrong machine means the safety
# switch is thrown somewhere harmless while the real shop books six real waybills. That is what happened
# the day dev moved and the config did not - the run reported "[autolabel] already no on dev" about a
# server nobody was testing.
run() {
  if [ -n "${BGC_LXC_HOST:-}" ] && [ -n "${BGC_LXC_DEV:-}" ]; then
    ssh -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=15 -o ServerAliveCountMax=4 \
      "$BGC_LXC_HOST" "lxc exec ${BGC_LXC_DEV} -- bash -lc $(printf '%q' "$*")" 2>/dev/null
    return
  fi
  ssh -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=15 -o ServerAliveCountMax=4 \
    -p "${BGC_SSH_PORT:-22}" "$BGC_SSH_HOST" "$@" 2>/dev/null
}

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
  # Book the waybill for ONE order and say what was booked. Only the international spec uses this: every
  # other spec leaves auto-labelling off and books nothing, and this one exists precisely to prove that a
  # parcel to another country reaches the courier - which cannot be proved without asking the courier.
  #
  # Prints WAYBILL <number> <courier> on success and nothing else, so the spec can assert on it. A failure
  # is printed as ERROR <message>: the spec must fail on it rather than be left waiting for a number.
  label)
    run "$WP eval '
      try { \$l = BGCouriers_Labels::generate((int) $2); printf(\"WAYBILL %s %s\n\", \$l->waybill,
              wc_get_order((int) $2)->get_meta(\"_bgcouriers_courier\")); }
      catch (\Exception \$e) { printf(\"ERROR %s\n\", \$e->getMessage()); }'" | tr -d '\r' ;;
  # And void it again. The number is printed either way - a cancel that reported success and did not take
  # looks exactly like one that worked, and only the number lets anyone re-check it at the courier.
  cancel)
    run "$WP eval '
      \$o = wc_get_order((int) $2); \$w = (string) \$o->get_meta(\"_bgcouriers_waybill\");
      if (\$w === \"\") { print(\"NOTHING\n\"); return; }
      try { BGCouriers_Labels::cancel((int) $2); printf(\"CANCELLED %s\n\", \$w); }
      catch (\Exception \$e) { printf(\"FAILED %s - %s\n\", \$w, \$e->getMessage()); }'" | tr -d '\r' ;;
  # Turn a WooCommerce payment gateway on or off. The international spec needs one: a shop whose
  # cash-on-delivery is legal only because the courier does the ППП cannot take COD abroad - no courier's
  # ППП crosses the border - so a Romanian order has to be a prepaid one, and dev has COD and nothing
  # else. Switched back off in the spec's teardown, because a payment method left enabled changes what
  # every other spec sees.
  #
  #   e2e/dev-option.sh gateway bacs yes|no   # set, and print what it now is
  #   e2e/dev-option.sh gateway bacs           # just read it
  # Reading matters as much as writing: the spec puts back what it FOUND rather than a hard-coded "no".
  # Forcing it off at the end is how dev was left unable to quote a foreign address at all - the shop had
  # no prepaid gateway, so every rate abroad was correctly refused, and the next person to open the
  # checkout saw an empty delivery box with no explanation.
  #
  # Through the gateway's own settings API rather than by patching the option: a gateway that has never
  # been configured has no option row at all, and `option patch` on a missing option does nothing at all
  # and says nothing about it.
  gateway)
    run "$WP eval '
      \$g = WC()->payment_gateways()->payment_gateways()[\"$2\"] ?? null;
      if (!\$g) { print(\"NO GATEWAY $2\n\"); return; }
      \$want = \"${3:-}\";
      if (\$want !== \"\") { \$g->update_option(\"enabled\", \$want); }
      printf(\"%s\n\", \$g->get_option(\"enabled\"));'" | tr -d '\r' ;;
  # One meta value off one order, for a spec that has to check what the checkout actually wrote.
  meta) run "$WP eval 'print((string) wc_get_order((int) $2)->get_meta(\"$3\"));'" | tr -d '\r' ;;
  *) echo "usage: dev-option.sh get|set|sweep|label|cancel|meta [name|order_id] [value|meta_key]" >&2; exit 1 ;;
esac
