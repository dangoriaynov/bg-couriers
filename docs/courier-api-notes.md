# Remaining couriers — API research notes (what each adapter needs)

Researched 2026-06-28 from each courier's actual docs (not assumptions). Access/credential steps are in
`docs/courier-api-access.md`; this file is the **technical** map: how each API fits the plugin's
`BGC_Courier_Interface` framework, where it diverges, and what's blocking.

**TL;DR effort:** Pigeon ≈ Econt (fits the framework, easiest) · BoxNow = medium (locker-only, geo
picker, flat rate — diverges) · Express One = **blocked** (no public API docs; must obtain from them).

---

## 1. Pigeon Express — *best fit, build first when creds arrive*

Source: OpenAPI 3.0 spec at `https://api-docs.pigeonexpress.com/openapi.yaml` (Redoc).

- **Auth:** headers `X-API-Key` + `X-API-Secret` (issued by Pigeon). Base URL is `{BASE_URL}` per account (prod + sandbox provided with creds — request both).
- **Endpoints (map almost 1:1 to our interface):**
  - `GET /v1/cities`, `GET /v1/cities/{cityId}`, `GET /v1/cities/{cityId}/streets` → `fetch_cities` / `search_streets`.
  - `GET /v1/offices?city_id=..&type=office|locker` → `fetch_offices` (it returns **both offices and lockers**, distinguished by `type` — so office **and** automat from one endpoint).
  - `POST /v1/shipments/calculate` → **live quote** (`quote()`). Request: `{pickup_type, pickup_office_id, delivery_type:"office|address|locker", delivery_address{}, packages:[{weight,length,width,height}], service_type, service_codes:{cod_amount, sms_notification_receiver}}`.
  - `POST /v1/shipments` → `create_label`; `GET /v1/shipments/{ref}/label` → `get_label_pdf`; `GET /v1/shipments/{ref}/track` (+ `/track/bulk`) → `track`; `POST /v1/shipments/{ref}/cancel` → `cancel_label`. Also `/v1/shipment-statuses`, `/v1/additional-services`.
- **Delivery types:** office + address + locker(automat) — same 3-tab UX as Speedy/Econt.
- **Framework fit:** ✅ excellent. `BGC_Pigeon extends BGC_Abstract_Courier` with a header-auth `http_post` override (like Econt's Basic-auth override); parsers for cities/offices(type→office/automat)/streets; `quote` via `/shipments/calculate`; create/label/track/cancel. `capabilities()` = `['address','office','automat','live_quote']`. **No checkout changes** — reuses the existing courier-aware checkout.
- **What's needed:** Pigeon API Key + Secret + base URLs (prod+sandbox) → then a normal Phase-2-style adapter (confirm live shapes → adapter → method → settings → `@group pigeon` tests → E2E). Estimated the same shape/size as the Econt phase.
- **Note:** the spec's examples use `city_id=68134` (Sofia) — same id as Speedy; confirm Pigeon's real city ids on first live call.

## 2. BOX NOW (Bulgaria) — *locker-only; diverges from the city/office model*

Source: BG Partner API manual v1.65 (`boxnow.bg/en/partner-api`).

- **Auth:** OAuth2 **client_credentials** — `POST /api/v1/auth-sessions {grant_type, client_id, client_secret}` → `{access_token, token_type:"Bearer", expires_in:3600}`; send `Authorization: Bearer <token>`. Base URL per account; Stage(sandbox) + Production.
- **Locations (geo, not city/office):**
  - `GET /api/v1/origins` → your **pickup warehouses** (id, lat/lng, address). One must be chosen as the ship-from → **a plugin setting**.
  - `GET /api/v1/destinations` → **APM lockers** (id, type"apm", lat, lng, title, name, addressLine1/2, postalCode, note). Filter by `latlng`+`radius` (default 25000m), `requiredSize` (1/2/3 = S/M/L), `limit`. Faster static list: `https://locationapi-production.boxnow.bg/v1/apms_bg-BG.json` (+ `-stage`).
  - Max parcel **36×45×60 cm, 20 kg** → the method should be hidden for larger carts.
- **Create / label / track:**
  - `POST /api/v1/delivery-requests {orderNumber, invoiceValue, paymentMode:"prepaid|cod", amountToBeCollected, allowReturn, origin{contactNumber/Email/Name, locationId}, destination{contactNumber/Email/Name, locationId}, items:[{id,name,value,weight}]}` → `{referenceNumber, parcels:[{id}]}`.
  - `GET /api/v1/parcels/{id}/label.pdf` → label. `GET /api/v1/parcels` → state (new/in-transit/in-depot/final-destination/delivered/returned/cancelled/lost). **Tracking via webhook** (push) too.
- **No price/quote endpoint** → BoxNow pricing is the contracted partner rate. So **configured flat rate** (no `live_quote`).
- **Framework fit:** ⚠️ partial. `capabilities()` = `['automat']` only (home delivery is restricted — error P415; no "office"). Divergences to handle:
  1. **Locker picker UX** — selection is by **geo/postcode → nearby lockers**, not city→office. Either build a postcode/area → `destinations` search, or embed BoxNow's official locker **widget/iframe** (`widget-v5.boxnow.bg`). The current city-select → office-select UI does **not** fit as-is.
  2. **Origin warehouse setting** (which `/origins` location ships from).
  3. **Flat-rate pricing** (override `BGC_Pricing` to skip live quote for BoxNow).
  4. COD via `amountToBeCollected` (0–5000); compartment size S/M/L; hide method when cart exceeds locker size.
- **What's needed:** OAuth2 client_id+secret (sandbox+prod) → adapter (token caching, destinations/origins, delivery-requests, label, parcels/webhook) **+ a locker-picker checkout component** + a flat-rate config + an origin-warehouse setting. More work than Pigeon/Econt because of the UX divergence.

## 3. Express One (Bulgaria) — *BLOCKED on docs*

Sources: `expressone.bg` (customer-facing: track / find office / calculate price) and `expressone.ba/en/technical-solutions/shipping-api`.

- Express One **has** a "Shipping API" (for systems/webshops to prepare + administer shipments), but the **technical documentation is not public** — it's provided to clients with their own system on request. The customer site exposes offices, address delivery, and price calculation, so the API very likely supports office + address delivery + pricing, but endpoint paths, auth scheme, and shapes are **unconfirmed**.
- **What's needed (blocker first):** contact Express One (`international@expressone.bg` / sales) to obtain (a) the **API documentation**, (b) a **test/sandbox account**, (c) **credentials**, and (d) confirmation of supported operations (offices, calculate, create label, tracking). Only after reading their real docs can the adapter be designed — do **not** guess the API. Until then, Express One stays a placeholder on the roadmap.

---

## Recommended order

1. **Pigeon** — fits the framework; build as soon as its API Key/Secret + base URLs are in hand.
2. **BoxNow** — build after Pigeon; budget extra time for the locker-picker UX + flat-rate + origin setting.
3. **Express One** — get the API docs + account from them first; then assess + plan.

Each adapter follows the proven flow: obtain creds (server-side) → confirm live API shapes (fixtures) →
`BGC_<Courier>` adapter → `BGC_Method_<Courier>` + register → settings section → `@group <courier>`
tests + E2E live-verify → review → merge.
