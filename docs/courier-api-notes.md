# Remaining couriers - API research notes (what each adapter needs)

Researched 2026-06-28 from each courier's actual docs (not assumptions). Access/credential steps are in
`docs/courier-api-access.md`; this file is the **technical** map: how each API fits the plugin's
`BGC_Courier_Interface` framework, where it diverges, and what's blocking.

**TL;DR effort (updated 2026-06-29):** Pigeon ≈ Econt (fits the framework, easiest; SCAFFOLDED) ·
BoxNow = medium (locker-only, geo picker, flat rate - diverges) · Express One = medium (address +
pickup-point, flat rate, no live quote - **API IS readable** via its official open-source WooCommerce
plugin; not blocked). **All three couriers have real, readable APIs** - only credentials are pending.

---

## Convention every adapter follows: the shipment says which ORDER it is

**A new courier is not finished until its shipment carries the shop's order number.** The merchant's
daily job is the reverse of ours - they are looking at a list of parcels in the courier's own panel and
need to know which order each one is. A courier told nothing does not leave the field blank: it fills it
with its own waybill number, which is the one number the merchant already has and the one that finds
nothing in the shop. That is exactly how it was found, on Econt, on 2026-08-17.

**The value is always `$order->get_order_number()`, never `get_id()`.** They are equal on a plain shop
and differ the moment a shop numbers its orders through a plugin - and the number to print is the one
the merchant is shown. Sameday was reading the post id and was corrected.

**The format follows what kind of field the courier gives you:**

| Field it offers | Send | Live examples |
|---|---|---|
| A dedicated order-number / reference field | the bare number | Econt `orderNumber`, BOX NOW `orderNumber`, Pigeon `external_reference` |
| Free text a human reads off the waybill | `sprintf(__('Order %s', 'bg-couriers'), …)` - a bare number there says nothing about what it is | Speedy `ref1` |
| A field the courier requires to be UNIQUE | suffix it, and say why in the comment | Sameday `clientInternalReference` = `<number>-<timestamp>` (it keeps a **cancelled** AWB's reference forever); BOX NOW retries count up `-2`, `-3` (P410 = duplicate orderNumber, and cancelling does not release it) |

**Read the field name off the courier's own schema before wiring it** - do not infer it from another
courier or from a plugin that talks to it. Econt's was settled by fetching
`https://ee.econt.com/services/openapi.yaml` and reading `ShippingLabel`; Speedy publishes a schema ZIP
at `/v1/schema`; Pigeon an OpenAPI spec. A field name that is merely plausible is how insurance and
return labels stayed unwired for three couriers.

**Then add the courier to `tests/unit/OrderReferenceOnLabelTest.php`**, which is where this stops being
a convention and becomes a thing that fails. It builds a body for an order whose displayed number is
deliberately not its post id, so both halves of the rule are pinned at once.

---

## 1. Pigeon Express - *best fit, build first when creds arrive*

Source: OpenAPI 3.0 spec at `https://api-docs.pigeonexpress.com/openapi.yaml` (Redoc).

- **Auth:** headers `X-API-Key` + `X-API-Secret` (issued by Pigeon). Base URL is `{BASE_URL}` per account (prod + sandbox provided with creds - request both).
- **Endpoints (map almost 1:1 to our interface):**
  - `GET /v1/cities`, `GET /v1/cities/{cityId}`, `GET /v1/cities/{cityId}/streets` → `fetch_cities` / `search_streets`.
  - `GET /v1/offices?city_id=..&type=office|locker` → `fetch_offices` (it returns **both offices and lockers**, distinguished by `type` - so office **and** automat from one endpoint).
  - `POST /v1/shipments/calculate` → **live quote** (`quote()`). Request: `{pickup_type, pickup_office_id, delivery_type:"office|address|locker", delivery_address{}, packages:[{weight,length,width,height}], service_type, service_codes:{cod_amount, sms_notification_receiver}}`.
  - `POST /v1/shipments` → `create_label`; `GET /v1/shipments/{ref}/label` → `get_label_pdf`; `GET /v1/shipments/{ref}/track` (+ `/track/bulk`) → `track`; `POST /v1/shipments/{ref}/cancel` → `cancel_label`. Also `/v1/shipment-statuses`, `/v1/additional-services`.
- **Delivery types:** office + address + locker(automat) - same 3-tab UX as Speedy/Econt.
- **Framework fit:** ✅ excellent. `BGC_Pigeon extends BGC_Abstract_Courier` with a header-auth `http_post` override (like Econt's Basic-auth override); parsers for cities/offices(type→office/automat)/streets; `quote` via `/shipments/calculate`; create/label/track/cancel. `capabilities()` = `['address','office','automat','live_quote']`. **No checkout changes** - reuses the existing courier-aware checkout.
- **What's needed:** Pigeon API Key + Secret + base URLs (prod+sandbox) → then a normal Phase-2-style adapter (confirm live shapes → adapter → method → settings → `@group pigeon` tests → E2E). Estimated the same shape/size as the Econt phase.
- **Note:** the spec's examples use `city_id=68134` (Sofia) - same id as Speedy; confirm Pigeon's real city ids on first live call.

## 2. BOX NOW (Bulgaria) - *locker-only; diverges from the city/office model*

Source: BG Partner API manual v1.65 (`boxnow.bg/en/partner-api`).

- **Auth:** OAuth2 **client_credentials** - `POST /api/v1/auth-sessions {grant_type, client_id, client_secret}` → `{access_token, token_type:"Bearer", expires_in:3600}`; send `Authorization: Bearer <token>`. Base URL per account; Stage(sandbox) + Production.
- **Locations (geo, not city/office):**
  - `GET /api/v1/origins` → your **pickup warehouses** (id, lat/lng, address). One must be chosen as the ship-from → **a plugin setting**.
  - `GET /api/v1/destinations` → **APM lockers** (id, type"apm", lat, lng, title, name, addressLine1/2, postalCode, note). Filter by `latlng`+`radius` (default 25000m), `requiredSize` (1/2/3 = S/M/L), `limit`. Faster static list: `https://locationapi-production.boxnow.bg/v1/apms_bg-BG.json` (+ `-stage`).
  - Max parcel **36×45×60 cm, 20 kg** → the method should be hidden for larger carts.
- **Create / label / track:**
  - `POST /api/v1/delivery-requests {orderNumber, invoiceValue, paymentMode:"prepaid|cod", amountToBeCollected, allowReturn, origin{contactNumber/Email/Name, locationId}, destination{contactNumber/Email/Name, locationId}, items:[{id,name,value,weight}]}` → `{referenceNumber, parcels:[{id}]}`.
  - `GET /api/v1/parcels/{id}/label.pdf` → label. `GET /api/v1/parcels` → state (new/in-transit/in-depot/final-destination/delivered/returned/cancelled/lost). **Tracking via webhook** (push) too.
- **No price/quote endpoint** → BoxNow pricing is the contracted partner rate. So **configured flat rate** (no `live_quote`).
- **Framework fit:** ⚠️ partial. `capabilities()` = `['automat']` only (home delivery is restricted - error P415; no "office"). Divergences to handle:
  1. **Locker picker UX** - selection is by **geo/postcode → nearby lockers**, not city→office. Either build a postcode/area → `destinations` search, or embed BoxNow's official locker **widget/iframe** (`widget-v5.boxnow.bg`). The current city-select → office-select UI does **not** fit as-is.
  2. **Origin warehouse setting** (which `/origins` location ships from).
  3. **Flat-rate pricing** (override `BGC_Pricing` to skip live quote for BoxNow).
  4. COD via `amountToBeCollected` (0-5000); compartment size S/M/L; hide method when cart exceeds locker size.
- **What's needed:** OAuth2 client_id+secret (sandbox+prod) → adapter (token caching, destinations/origins, delivery-requests, label, parcels/webhook) **+ a locker-picker checkout component** + a flat-rate config + an origin-warehouse setting. More work than Pigeon/Econt because of the UX divergence.

## 3. Express One (Bulgaria) - *API IS readable (via its open-source plugin); medium effort*

Source (2026-06-29): Express One's **official open-source WooCommerce plugin** `express-one-shipment`
(wordpress.org/plugins/express-one-shipment/, GPL) - read its source for the exact endpoints/shapes
(facts, not copied code). Express One BG is part of the **Austrian Post** CEE network; the plugin's API
host is `https://api.expressone.si/`.
**Caveat:** that plugin is Express One's **Slovenia** instance (default sender country `SI`, tracking
`inet.expressone.si`). The API *style* (apikey + these endpoints) is the group's, but the **Bulgaria**
base URL + access must be confirmed with Express One BG - do NOT assume the `.si` host serves BG.

- **Auth:** an **API Key** (`apikey=` query param, also in POST bodies), issued by Express One. No OAuth.
- **Endpoints (`api.expressone.si/...`):**
  - `GET /apiuserinfo?apikey=` - validate credentials / account info (→ `check_credentials`).
  - `GET /places?apikey=` - cities/postcodes (→ `fetch_cities` / address validation).
  - `GET /parcelshops?isActive=true&apikey=` - **pickup points** (parcel shops): `{id, pickupCodes, name, address, city, postcode/zip, GeoLat, GeoLong}` (→ `fetch_offices`, type "office"/pickup-point).
  - `GET /checkcountryiseligible?apikey=` - is a ZIP/country deliverable.
  - `POST /createshipment` (apikey in body) - recipient `{name, countryCode, zipcode, city, streetAndNumber, telephone, notifyEmail}`, `sender`, `isSenderNonCustomer`, `codValue`/`codCurrency` (COD), `collies` (parcels), `pickupCodes` (for pickup-point delivery) → returns a barcode/shipment id. `POST /updateshipment`.
  - `GET /pdfinternal` (+ `/layouts`) - the PDF label.
- **Delivery types:** **Home delivery** (recipient address) + **Pickup Point** (parcelshop ≈ office). **No locker/automat.**
- **No `calculate`/price endpoint** → the plugin uses a **configured Delivery Fee** → **flat/configured rate** (like BoxNow), no `live_quote`.
- **Framework fit:** ⚠️ partial. `capabilities()` = `['address','office']` (pickup-point as "office"); flat-rate (no live quote). Maps reasonably to the existing checkout (address + office tabs), but: cities come from `/places` (not a Speedy-style nomenclature - confirm pagination/shape live), offices = `parcelshops`, and pricing is flat (override `BGC_Pricing`). COD via `codValue/codCurrency`.
- **What's needed:** an **API Key** from Express One (international@expressone.bg / sales) - then a normal adapter built against the plugin-derived shapes (validate via `/apiuserinfo`; `/places`+`/parcelshops` nomenclature; `/createshipment`+`/pdfinternal` label; flat-rate config). Confirm exact request/response shapes live with the key (the plugin reveals the structure; field nuances + a tracking endpoint need live confirmation).

---

## Recommended order

1. **Pigeon** - SCAFFOLDED (code on `main`); just needs the API Key/Secret + base URL to live-verify.
2. **Express One** - readable via its open-source plugin (address + pickup-point, flat-rate); build once an API Key is in hand. Reasonably fits the existing checkout (no locker-picker needed - pickup-points are office-like).
3. **BoxNow** - build last; budget extra time for the locker-picker UX + flat-rate + origin-warehouse setting (the biggest UX divergence).

(Express One promoted above BoxNow now that its API is confirmed readable + it fits the existing office/address checkout, whereas BoxNow needs new locker-picker UI.)

Each adapter follows the proven flow: obtain creds (server-side) → confirm live API shapes (fixtures) →
`BGC_<Courier>` adapter → `BGC_Method_<Courier>` + register → settings section → `@group <courier>`
tests + E2E live-verify → review → merge.
