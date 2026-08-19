// Where these specs run is the operator's own dev shop, and that address is theirs, not the plugin's:
// it lives in bin/deploy.conf (gitignored) or in BASE_URL, and never in this repository.
//
// There is deliberately NO default. A default host in a suite that places cash-on-delivery orders is a
// live-fire hazard - COD lands in `processing`, dev auto-labels that status against the couriers' real
// accounts, and a run pointed at the wrong site books real parcels. Unset means refuse to start.
const fs = require('fs');
const path = require('path');

const CONF = path.join(__dirname, '..', 'bin', 'deploy.conf');

function fromConf(key) {
  if (!fs.existsSync(CONF)) return '';
  const m = fs.readFileSync(CONF, 'utf8').match(new RegExp('^\\s*' + key + '=(.*)$', 'm'));
  return m ? m[1].trim().replace(/^["']|["']$/g, '') : '';
}

/** The site the suite drives. Throws rather than guessing. */
function baseURL() {
  const url = (process.env.BASE_URL || fromConf('BGC_E2E_BASE_URL')).replace(/\/+$/, '');
  if (!url) {
    throw new Error(
      'No site to test against.\n' +
      '  Set BGC_E2E_BASE_URL in bin/deploy.conf (see bin/deploy.conf.example), or pass BASE_URL=...\n' +
      '  There is no default on purpose: these specs place COD orders, and a COD order on a site with\n' +
      '  live courier credentials books a real shipment.'
    );
  }
  return url;
}

module.exports = { baseURL, fromConf };
