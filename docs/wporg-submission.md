# WordPress.org submission - runbook

State as of 2026-07-23: code frozen for submission, Plugin Check was clean on the 2026-07-15 report;
re-run it once more on dev before uploading (new code landed since: #83 ship-in-total, #84 thank-you,
Speedy A4_4xA6 batch, tracking poller).

## 1. Pre-flight (each item ~1 min)

- [ ] `wp-admin (dev) → Tools → Plugin Check` on the CURRENT deploy - must be clean (fix + redeploy otherwise).
- [ ] Versions agree: `bg-couriers.php` header `Version` == readme.txt `Stable tag` (now 0.2.1).
- [ ] readme.txt `Tested up to` == current WP major.minor (7.0 today; check https://api.wordpress.org/core/version-check/1.7/).
- [ ] Translation fresh: `msgfmt --statistics languages/bg-couriers-bg_BG.po` shows 0 untranslated (kept in the repo for our own sites; NOT shipped in the zip - see below).
- [ ] Build the zip (below) and install it once on a CLEAN dev/wp-env site - activates without notices.

## 2. Build the zip

```bash
STAGE=$(mktemp -d)/bg-couriers && mkdir -p "$STAGE"
rsync -a --exclude '.git' --exclude '.gitignore' --exclude '.superpowers' --exclude '.claude' \
  --exclude '.phpunit.result.cache' --exclude 'tests' --exclude 'node_modules' \
  --exclude 'vendor' --exclude 'docs' --exclude 'bin' --exclude 'e2e' \
  --exclude '.wp-env.json' --exclude 'composer.*' --exclude 'phpunit.xml.dist' \
  --exclude '.wordpress-org' --exclude '.distignore' --exclude '.github' --exclude 'README.md' \
  --exclude '.DS_Store' --exclude 'languages' ./ "$STAGE/"
(cd "$STAGE/.." && zip -qr ~/Downloads/bg-couriers-0.2.1.zip bg-couriers)
```

The zip root must contain exactly: `bg-couriers.php  readme.txt  LICENSE  includes/  assets/`.

**`languages/` is deliberately NOT in the zip.** The Plugins Team asked for it out: plugins on
WordPress.org get their translations from translate.wordpress.org, generated per locale and delivered by
the normal update system. It stays in the REPO, because dev.dobavki.club and dobavki.club deploy by rsync
from the repo (`bin/deploy.sh`) and would otherwise lose Bulgarian.

Consequence to remember: a site that ever updates the plugin **from wordpress.org** gets no bundled .mo,
so Bulgarian is only there once translate.wordpress.org has it. After approval, upload
`languages/bg-couriers-bg_BG.po` at `https://translate.wordpress.org/projects/wp-plugins/bg-couriers/`
as the plugin author, and it becomes the community-maintained source of truth.

## 3. Submit

1. Log in on wordpress.org as **winter2007d**.
2. https://wordpress.org/plugins/developers/add/ - upload `bg-couriers-0.2.1.zip`.
3. The requested slug will be derived from the plugin name (`bg-couriers`); keep it - it becomes permanent.
4. Wait for the review e-mail (usually days; replies go to the account e-mail). Reviewers may ask about:
   - external services -> already disclosed in readme.txt (couriers' APIs, BOX NOW widget, OSM/Nominatim, optional Google Geocoding);
   - bundled libs -> FPDF/FPDI/Leaflet listed in the FAQ with licenses, full sources shipped;
   - courier brand logos -> nominative use only; if the reviewer objects, swap `logo_url()` to return '' (falls back to our parcel badge) and resubmit.

## 4. After approval (SVN)

```bash
svn co https://plugins.svn.wordpress.org/bg-couriers svn-bgc && cd svn-bgc
# plugin files -> trunk/  (same file set as the zip)
# .wordpress-org/{icon,banner,screenshot}-*.png -> assets/   (screenshots are NOT inside the zip)
svn add --force trunk assets
svn ci -m 'Initial 0.2.1 release'
svn cp trunk tags/0.2.1 && svn ci -m 'Tag 0.2.1'
```

- Screenshot captions come from readme.txt `== Screenshots ==` (numbered 1..6 = screenshot-N.png).
- Assets (incl. refreshed screenshots 5-6) can be updated in SVN ANY time without another review -
  so submitting with the current screenshots and refreshing them later is fine.
- Releasing an update later = bump `Version` + `Stable tag`, update changelog, commit to trunk, `svn cp trunk tags/<ver>`.

## Known-acceptable review flags

- `BGC_` prefix warnings on our classes - accepted (4+ chars).
- FPDI/FPDF internals - each file carries `phpcs:ignoreFile` as a recognised bundled library.
- Public no-nonce nomenclature AJAX - read-only endpoints, annotated with phpcs:ignore + reason.
