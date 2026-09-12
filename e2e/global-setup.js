const { execFileSync } = require('child_process');
const path = require('path');

/**
 * Two things dev has to be set to before this suite means anything, both put back afterwards.
 *
 * 1. Auto-labelling OFF, or a run books real shipments - see below.
 * 2. Delivery abroad ON, or three specs have nothing to drive.
 *
 * The second one had been answered with test.skip() for a month. Delivery outside Bulgaria is switched
 * off for the whole plugin behind a filter while the feature is unfinished (the owner's decision, and
 * the right one - docs/international-shipping.md), so no shop is offered a foreign rate and the specs
 * that watch the foreign path had nothing to open. Skipping them meant every foreign code path went
 * unwatched on every run, which is the opposite of what those specs are for. They borrow the switch
 * for the length of the run instead, exactly as they already borrow a payment gateway, and it goes
 * back when the run ends.
 *
 * Turning it on is not enough on its own: a courier's foreign towns are only FETCHED while it is on,
 * so Romania has to be synced too - and the same sync with the switch back off prunes those rows out
 * again, which is why the teardown runs one.
 */

/**
 * Turn dev's auto-labelling off for the length of a run, and put it back afterwards.
 *
 * Six of these specs place a COD order. WooCommerce puts a COD order straight into `processing`, which
 * is the status dev is configured to auto-label at, and dev labels against the couriers' LIVE accounts -
 * so a full run booked six real shipments, every time, with nobody meaning to. Three Speedy and three
 * Econt were found still live on 2026-08-17 and cancelled; an earlier incident of the same shape sent a
 * Sameday courier to the owner's address to collect parcels that did not exist.
 *
 * A note telling a human to flip the setting first is what this replaces. The suite causes the side
 * effect, so the suite carries the responsibility for it.
 *
 * Nothing is weakened by switching it off: no spec asserts anything about a waybill.
 */

const SH = path.join(__dirname, 'dev-option.sh');
const OPTION = 'bgcouriers_autolabel_enabled';

const sh = (...args) => execFileSync('bash', [SH, ...args], { encoding: 'utf8' }).trim();

module.exports = async (config) => {
  const baseURL = process.env.BASE_URL || (config.projects[0] && config.projects[0].use.baseURL) || '';

  // Only the shared dev site has live courier credentials behind it. A run pointed anywhere else - a
  // local wp-env, somebody's own copy - has nothing to protect and no reason to need SSH. Which one is
  // the shared dev site is the operator's own address, from bin/deploy.conf; baseURL() refuses to guess.
  if (baseURL.replace(/\/+$/, '') !== require('./config').baseURL()) {
    console.log(`[autolabel] ${baseURL} is not the dev site - leaving its settings alone.`);
    return undefined;
  }

  let previous;
  try {
    previous = sh('get', OPTION);
  } catch (e) {
    // Refusing is the safe answer, and it matches how every other gate in this repo behaves. Running
    // blind here does not risk a red test, it risks six real parcels.
    if (process.env.BGC_ALLOW_AUTOLABEL === '1') {
      console.warn('[autolabel] could not reach dev, and BGC_ALLOW_AUTOLABEL=1 - continuing anyway.');
      return undefined;
    }
    throw new Error(
      `Cannot read ${OPTION} on dev, so this run could book real courier shipments.\n` +
      `  ${e.message}\n` +
      '  Fix the SSH access (bin/deploy.conf), or set BGC_ALLOW_AUTOLABEL=1 if you have already\n' +
      '  turned auto-labelling off yourself.'
    );
  }

  if (previous !== 'yes') {
    // Said out loud because this run will NOT put it back - it restores only what it changed - and the
    // likeliest reason to find it off is a previous run killed before its teardown. Silent inheritance
    // would leave dev never auto-labelling again, which is safe here and wrong for the owner.
    console.warn(`[autolabel] already ${previous} on dev - left as is, and NOT restored at the end.\n` +
      '           If no one turned it off deliberately, an interrupted run did: set it back with\n' +
      '           e2e/dev-option.sh set bgcouriers_autolabel_enabled yes');
  } else {
    sh('set', OPTION, 'no');
    console.log('[autolabel] off for this run (was yes).');
  }

  // --- delivery abroad, for the length of the run ---------------------------------------------------
  // Never fatal: a run that cannot reach this switch is still a useful run of 55 domestic specs, and
  // the three foreign ones will say what is wrong themselves. Losing the whole suite over it would be
  // the worse trade.
  let intlWas = 'off';
  try {
    intlWas = sh('intl', 'status');
    if (intlWas !== 'on') {
      sh('intl', 'on');
      console.log('[intl] delivery abroad on for this run (was off).');
    }
    // Romania's towns only exist in the nomenclature while the switch is on. Synced once, then left -
    // the check is what keeps every run after the first from paying two minutes for rows already there.
    if (Number(sh('rows', 'speedy', 'RO')) < 100) {
      console.log(`[intl] syncing Speedy for Romania: ${sh('sync', 'speedy')}`);
    }
  } catch (e) {
    console.warn(`[intl] could not set delivery abroad up on dev: ${e.message}\n` +
      '      The three @intl specs will fail rather than pass quietly.');
  }

  return async () => {
    // Proof rather than assumption, and it runs whether or not anything was changed: if a waybill did
    // get made, the NUMBER belongs on screen now, not in a sweep somebody runs later.
    let swept = '';
    try { swept = sh('sweep'); } catch (e) { console.warn(`[autolabel] waybill sweep failed: ${e.message}`); }
    if (swept) {
      // Printed even when every line says CANCELLED. A cancel that reported success and did not take is
      // indistinguishable from one that did unless the numbers are on screen to re-check: Speedy and
      // Sameday answer false for an already-cancelled waybill, and Econt's tracking lags the
      // cancellation by about five minutes, so the only honest verification is a LATE one.
      console.error('\n!! waybills existed on dev after this run:\n' + swept +
        '\n   Re-check each number at the courier in a few minutes - a successful cancel call is not proof.\n');
    }
    // Put the switch back, and prune Romania out with it: the same sync with delivery abroad off drops
    // every row for a country the plugin is no longer offering, so dev ends the run exactly as it
    // started. Only if this run is what turned it on - a dev deliberately left international would
    // otherwise be switched off behind its owner's back.
    if (intlWas !== 'on') {
      try {
        sh('intl', 'off');
        console.log(`[intl] delivery abroad off again; Romania pruned: ${sh('sync', 'speedy')}`);
      } catch (e) {
        console.error(`!! [intl] COULD NOT switch delivery abroad back off on dev: ${e.message}\n` +
          '   Remove wp-content/mu-plugins/bgc-e2e-intl.php by hand.');
      }
    }
    if (previous === 'yes') {
      try {
        sh('set', OPTION, 'yes');
        console.log('[autolabel] restored to yes.');
      } catch (e) {
        console.error(`!! [autolabel] COULD NOT RESTORE ${OPTION}=yes on dev: ${e.message}`);
      }
    }
  };
};
