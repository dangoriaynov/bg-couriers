# bg-couriers - WordPress.org readiness audit

**Date:** 2026-07-07 · **Status:** pre-submission audit (owner requested before publishing).
Grounded in the current code - not a generic checklist. Order of work: fix blockers → i18n plumbing → Bulgarian translation → readme + disclosures → final review → submit.

## A. Translations / i18n (owner's request)
- ✅ **Text domain consistent** - all ~219 translatable strings use `'bg-couriers'`; `Text Domain: bg-couriers` header present.
- ❌ **Translations will NOT load** - there is **no `load_plugin_textdomain()`** call and **no `Domain Path` header**. Must add both (`Domain Path: /languages`, load on `init`/`plugins_loaded`).
- ❌ **No `languages/` dir, no `.pot`** - must generate `languages/bg-couriers.pot` (`wp i18n make-pot`) and ship `bg_BG.po` + `bg_BG.mo`.
- ⚠️ **UI source strings are English** - for a BG audience the `bg_BG` translation must be **complete + high quality** (proper courier terms: наложен платеж, товарителница, до офис/до автомат, ППП, etc.). Some Cyrillic is hardcoded (e.g. "Хранителни добавки" default, "лв.") - keep those, but every user-facing English string needs a good BG translation.

## B. WordPress.org guideline compliance (blockers)
- ❌ **No `readme.txt`** - WP.org requires the exact format (stable tag, "Tested up to", short/long description, FAQ, changelog, screenshots). We only have `README.md`. Must author `readme.txt`.
- ⚠️ **External-services disclosure required** - the plugin calls **Speedy / Econt / Pigeon / BoxNow** APIs and embeds the **BoxNow map widget iframe** (`map.boxnow.bg`). WP.org mandates a readme section stating which third-party services are contacted, what data is sent, and links to their terms/privacy. Must add it.
- ✅ **Escaping / sanitization / nonces present** (esc_* ×96, sanitize_* ×20, nonce checks ×11) - but the audit must **verify coverage**: every `$_POST`/`$_GET` sanitized, every echo escaped, every admin action nonce+capability guarded.
- ⚠️ **License** - confirm a `License: GPLv2+` (or later) line in the header + a LICENSE file; verify no bundled non-GPL code (we're clean-room, no copied CSESM2/Bulgarisation code).
- ⚠️ **Credentials** - courier secrets are encrypted (`BGC_Encryption`); confirm nothing is logged/exposed and no test secrets ship in the repo.

## C. Quality findings (owner spot-checks)
- ❌ **"Sender address (for labels)" is DEAD** - `bgc_sender_*` → `BGC_Settings::sender()` → `courier_config()['sender']`, but **every courier ignores it** and uses its own registered account sender (Econt `getClientProfiles`, Speedy the API user's default, BoxNow the warehouse, Pigeon the pickup office). So the fields are advertised but do nothing. **Decision needed: remove the section (recommended - matches the no-dangling-params rule) or wire it as a real override.**
- ⚠️ **Emergency help (phone/message) works but is untested** - `BGC_Settings::emergency()` → localized `BGC.emergency` → the JS shows a one-time help box after 2 checkout failures. Functionally wired; **add an automated test**.
- ✅ **Label generation is tested** - `EcontLabelIntegrityTest`, `LabelGenerationTest`, `LabelIntegrityTest`, Speedy/Pigeon label tests; Econt COD packing-list verified live.

## D. Functional completeness (not WP.org blockers, but affects "what we advertise")
- **Express One** - planned only: spec/plan `docs/superpowers/plans/2026-06-29-expressone-phase4.md` exist, **no adapter, not registered**. Gated on the BG API key (`international@expressone.bg`). Next courier to build.
- **Sameday** - adapter Task 1 only (paused on `feat/sameday`); needs completion + a contract/sandbox account.
- **Advanced parity** (per `2026-07-04-courier-competitive-settings.md`) - Sameday per-service/3-way pricing/open-package; BoxNow home/any-APM/returns; unified GPS map - post-launch.

## E. Path to submission
1. Decide sender-address (remove/wire) + add the emergency test → close the quality findings.
2. i18n plumbing (`load_plugin_textdomain` + `Domain Path` + `languages/` + `.pot`).
3. Author `bg_BG.po`/`.mo` - complete, reviewed Bulgarian.
4. `readme.txt` + external-services disclosure + license/LICENSE.
5. Full escaping/sanitization/capability sweep.
6. Final whole-plugin review, then submit to WordPress.org.
