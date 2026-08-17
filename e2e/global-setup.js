const { execFileSync } = require('child_process');
const path = require('path');

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
  // local wp-env, somebody's own copy - has nothing to protect and no reason to need SSH.
  if (!/dev\.dobavki\.club/.test(baseURL)) {
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
    console.log(`[autolabel] already ${previous} on dev - nothing to change.`);
  } else {
    sh('set', OPTION, 'no');
    console.log('[autolabel] off for this run (was yes).');
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
