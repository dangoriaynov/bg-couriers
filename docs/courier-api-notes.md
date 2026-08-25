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

## 3. Express One (Bulgaria) - *BUILT 2026-08-25, measured against the live test account*

`https://system.expressone.bg/api/web`. **Everything below came back from the API**, not from its
documentation and not from Express One's Slovenian open-source plugin - the two systems are unrelated
and the earlier notes here (`api.expressone.si`, `apikey=` in the query, flat rate, no lockers, no
tracking, no cancel) described the wrong courier entirely. Full write-up:
`docs/superpowers/specs/2026-08-25-expressone-design.md`.

- **Auth:** `POST /1/authorize {username,password}` → `data.authorization_code`; `POST /1/accesstoken
  {authorization_code}` → `data.access_token` (+ `expires_at`, unix, ~24 h). Every later call carries
  `X-Access-Token`. The BOL account is NOT the my.expressone.bg login.
- **The envelope decides, the HTTP code does not.** Everything is HTTP 200: success is
  `{"status":true,"data":…}`, refusal `{"status":0,"error_code":200,"message":"…"}`. `data` is a JSON
  array from some endpoints and an object keyed `"1","2","3"…` from others (list-office).
- **Rate limit: >60 requests/minute blocks the IP for 30 minutes.** Never fan a call out per city.
- **Currency: EUR** (`/1/list-bol` prints `"COD": "0.00 EUR"`).
- **`/1/list-city {country_id:100}`** - one call, 9000 rows, **4337 distinct**: it is town × postcode
  (Sofia alone is 964 rows), `ID` is the ЕКАТТЕ code, `COUNTRY_ID` is space-padded. Dedupe by `ID`.
- **`/1/list-office {country_id:100}`** - one call for the whole country: 490 points, 247 towns.
  `LOCATION_TYPE` 2 = own depot, 3 = partner counter (PUP), **4 = EXOBOX locker**. Carries lat/lng,
  `POSTCODE`, `MAX_PARCEL_WEIGHT` (31-32 kg) and dimensions, hours `D1`-`D7`. `ID` is an int from the
  per-city call and a **string** from the country-wide one.
- **`/1/list-street {city_id}`** - per town, 4884 rows for Sofia, no search term. Live, never synced.
- **`/1/list-object`** - the account's own sender addresses (18 on the test account). Its `ID` is the
  `SEND_OFFICE_ID` every waybill needs.
- **`/1/calculate-bol`** - a real quote, and the destination TYPE changes it: the same 1 kg parcel to
  Sofia was 4.76 to an address, 4.06 to a counter, 3.73 to a locker (the last two only when
  `TAKE_OFFICE_ID` is sent). Itemised `TAX_SERVICE`/`TAX_FUEL`/`TAX_COD`/`INSURANCE`/`TAX_VAT`/`TOTAL`;
  **`TOTAL` includes VAT**, so what WooCommerce is given is `TOTAL - TAX_VAT`.
- **`/1/create-bol`** - `SEND_OFFICE_ID`, receiver name/phone/country/city id **and `RECEIVER_CITY`, the
  town NAME, which is required even with the id** ("The RECEIVER_CITY field cannot be empty!"), then
  `TAKE_OFFICE_ID` or the street fields; `CONTENT` + `WEIGHT` required; `COD`, `INSURANCE`, `PACK_COUNT`,
  `CLIENT_REFERENCE`, `CHECK_BEFORE_PAY`, `PAYER` (0 sender / 1 receiver). Answers `BILLOFLADING`,
  `TOTAL`, `PACKS[]` **and the label itself, base64, in `LABEL`** - printing costs no second call.
- **`/1/print-bol`** - `pdfformat` 0 = the account's own setting, 1 = PDF, 2 = label, 3 = ZPL, 4 = ZPL
  vertical (4 really does answer `^XA`).
- **`/1/cancel-bol`** → `SUCCESS: 1`; **a second cancel answers an empty `data`**, and `/1/bol-info` then
  reports `STATUS_ID 7`. Already-cancelled must read as cancelled.
- **`/1/track-bol` / `/1/track-bols`** - an untouched shipment answers one row of `"N/A"` with a null
  status: that is "no events", not a failure. Statuses (read off 32 shipments, undocumented): 0 booked,
  1 ordered, 2 picked up, 3 at the office, 5 out for delivery, **6 delivered**, **7 cancelled**,
  8 failed attempt (+ substatus), 10 finalised, **12 returned to sender**, 101 an event whose meaning is
  in its substatus text ("Налична за получаване в АПС", "Изтекла резервация в АПС", …).
  **A delivered parcel was observed ending 6 → 10 → 7**, so the newest event is NOT the verdict: 6 and 12
  are facts about the parcel and outrank the paperwork that follows them.
- **Customer tracking:** `https://expressone.bg/bg/tracking/<BILLOFLADING>` (a path, not a query - the
  page's own form posts `form[bols]`).
- **`/1/request-courier` {count, weight, readiness, take_office_id}** → `data.REQUEST`.
- Also there and unused: `/1/list-all-status`, `/1/list-bol`, `/1/bol-finance-info`, `/1/list-cod-order`
  (max 35 days), `/1/info-cod-order[-detailed]`, `/1/list-invoice`, `/1/list-return-redirect`.
- **Documentation disagrees with the API** in at least: `/1/me` is documented POST and is GET-only, and
  neither the currency, the status vocabulary nor the required `RECEIVER_CITY` is in it.
- **Cash on delivery pays out through ППП** on this shop's contract (owner, 2026-08-25), so the courier
  is ticked for it; without that tick a shop that fiscalises through ППП is correctly offered no
  наложен платеж for Express One, the way BOX NOW is not.
- **The street box must not accept a typed street** for this courier - `street_list_only()` says so and
  the checkout turns select2's free tagging off for it. Found by driving a real order on dev: the picker
  offered "БУЛ. ПРОФ. ЦВЕТАН ЛАЗАРОВ", the typed "Цветан Лазаров" was stored instead, the order was
  placed happily, and the label was refused afterwards with the customer long gone.


## 4. Европът / Evropat-2000 - *domain model known from their own manual; API still unseen*

Read from **"Указание за работа с модула ЕВРОПЪТ ОНЛАЙН"** (18 pages, LibreOffice, 2023-09-14), the
owner's copy of the manual for their web module at https://online.evropat.com. It is a **user manual, not
an API document** - there is no mention of an API, JSON, XML or an integration anywhere in it. What it
does give is the complete shape of a Европът товарителница, which is what an adapter has to fill in, and
it settles two questions that were open.

**SETTLED: no lockers.** Delivery is one of exactly four combinations, and every one of them is an office
or a door: **ОФ-ОФ** (office to office, до поискване), **ОФ-ВР** (office to the recipient's door),
**ВР-ОФ** (from the sender's door to an office), **ВР-ВР** (door to door). So Европът gets **two**
delivery options in our checkout - `office` and `address` - and no `automat`.

**SETTLED: cash on delivery exists, in both forms.** The waybill carries **НП (наложен платеж)** and
**ППП (пощенски паричен превод)** with the amount, and the direction is **mandatory**: `СЪБЕРИ` (collect
from the recipient) or `ИЗПЛАТИ` (pay out to them). That maps onto what this shop already fiscalises
through (`cod_fiscalization = ppp`).

**The four ends, and what they mean for us.** ОФ-ОФ/ОФ-ВР/ВР-ОФ/ВР-ВР encode BOTH ends of the journey, so
the adapter needs a merchant setting for the SENDER end (drop at an office vs courier collects from the
premises) as well as the customer's choice for the recipient end. This is the same gap the audit found in
our Pigeon adapter, which hardcodes `pickup_type: office` - worth solving once, for both.

**Waybill fields, from the manual:**

- **Recipient:** населено място, chosen as `postcode - name - region`; the **serving office is derived
  automatically from the town**; фирма; адрес with №/блок/вход/етаж/апартамент plus a free-text
  `адрес пояснение`; получател (three names); телефон.
- **Shipment kind:** `ДОКУМЕНТ` (a document up to **0.500 kg**) or `КОЛЕТ` (anything else), плюс `БРОЙ`
  (how many packages) and an `ОПИСАНИЕ` that is required for every parcel, cargo or pallet shipment.
- **Weight is TARIFF weight**, not the real one: the greater of the actual weight and the volumetric,
  where **volumetric = width x length x height / 6000** (cm). Dimensions are only filled in when the
  volumetric figure is the one that applies. Our packer would have to compute this, or send the box and
  let them.
- **Services:** SMS/Viber notice, верификация, чупливо, недостатъчна опаковка (which FORBIDS обявена
  стойност), карго експрес (>= 100 kg), евро палет / нестандартен палет, expres tiers **Е2/Е3/ЕК/ЕМ**,
  приоритет hour (10:00-18:00, zone 1 only), ОР (обратна разписка), ПД (signed documents back).
- **Cash on delivery extras:** `ДА СЕ ОТВОРИ ПРЕДИ ПЛАЩАНЕ` - the recipient may open the parcel before
  paying, which is our open-before-payment service under another name - and `ПРИ ОТКАЗ ДА СЕ ВЪРНЕ ЗА МОЯ
  СМЕТКА` (a refusal returns at the sender's expense).
- **Обявена стойност** requires the value AND the document proving it: type (фактура / стокова разписка /
  друго), number and date. Not optional the way Speedy's declared value is.
- **Payer:** `ПОДАТЕЛ` / `ПОЛУЧАТЕЛ` / `ТРЕТА СТРАНА`, paid `В БРОЙ` or against a **клиентски номер** in
  the form `E<three digits><office index>`. Three payers is one more than our sender/recipient model.
- **Price** comes back computed: a `ТАРИФЕН КОД` derived from sender + recipient + services, plus the
  month's `ТАКСА ГОРИВО` (fuel surcharge) on top. So their quote already includes the fuel surcharge -
  do not add one.
- **Sender-side:** `Изплати НП на мен` covers third-party logistics (shipping from an address that is not
  the registered one, with the collection still paid to the account holder).

**Operational shape** (useful for what an adapter must expose, and what it need not):

- A waybill can be **saved** (prepared, no print) or **saved and printed**; printing yields **three copies
  landscape on one A4**, and parcel labels print separately as **A4 or sticker**.
- Cancelling is `Отказване` in the Пратки menu - and a saved-but-unprinted waybill is a real, cancellable
  record, not a draft.
- **Pickup requests are a separate menu** (`Заяви куриер`) with a date and an earliest/latest hour, and a
  hard cut-off: after **17:30** on a full working day, or **12:00** on a short one, the request rolls to
  the next working day. If we ever automate pickup requests, that cut-off is the rule to encode.
- `Разпореждания` is their re-delivery instruction flow for undelivered parcels - no equivalent in this
  plugin, and none needed for a first adapter.
- `Фактури и описи` and `НП/ППП информация` are **activated on request** per account, so an adapter must
  not assume the account can read its own collections.

**Still unknown, and only the API key answers it:** everything about the transport - endpoints, auth
header, field names, whether a price can be quoted before a waybill is created (the manual only ever
prices a filled-in waybill), and whether nomenclature (towns/offices) can be pulled at all. Nothing above
should be turned into code until the real documentation is in hand; it is the domain, not the wire format.

The PDF itself is NOT in this repository - it is Европът's material and 2.4 MB of it; the owner holds the
copy.

## Courier pickup requests - NOT built, and it is part of the flow, not an extra

A waybill only says a parcel exists. The courier comes for it **on a request that names the specific
waybills** and a day - that is how these carriers work, and all three APIs that have it are built around
exactly that. The plugin creates waybills and cannot request a collection for them at all.

| courier | endpoint | shape |
|---|---|---|
| **Speedy** | `POST /v1/pickup` | `pickupScope` is an enum - **`EXPLICIT_SHIPMENT_ID_LIST`** (with `explicitShipmentIdList`), `ALL_CREATED_BY_LOGGED_USER`, `ALL_CREATED_BY_SAME_CLIENT`, `ALL_CREATED_BY_SAME_CONTRACT_USER` - plus `pickupDateTime`, `visitEndTime`, `contactName`, `phoneNumber`, `autoAdjustPickupDate`. **`POST /v1/pickup/terms`** answers which windows a date/service/sender actually allows, so the hours are asked for, not guessed. |
| **Econt** | `ShipmentService.requestCourier.json` | `requestCourierTimeFrom` / `requestCourierTimeTo`, the shipments attached to the request, and a `courierRequestID` back. `ShipmentService.getRequestCourierStatus.json` reads its state. Two format traps, from Drusoft's own code: the attach list wants the **13-digit** waybill numbers, and the times want **`Y-m-d H:i:s`, not ISO 8601**. |
| **Европът** | web module only | `Заяви куриер`: date, earliest/latest hour, waybills added/removed per request (`Добави пратка` / `Премахни пратка`), and a hard cut-off - after **17:30** on a full day or **12:00** on a short one it rolls to the next working day. The API is unseen. |
| **Sameday** | none | the SDK has pickup **points** (the merchant's own addresses: Get/Post/Delete) and no courier request. |
| **Pigeon** | none | absent from the vendor's own `ApiClient`. |
| **BOX NOW** | none | collection is per contract. |

**The reference plugins have this and we do not:** Drusoft Speedy posts to `/v1/pickup`, Drusoft Econt
calls `requestCourier`, and ShipBG exposes it as a bulk action on the orders list.

**Shape it should take here:** a bulk action on the orders list over the selected orders that already have
waybills, grouped by courier (one request per courier - the APIs take a list, so N orders = 1 call), a date
and a time window offered from Speedy's `pickup/terms` where available, the returned request id stored on
each order, and the action offered ONLY for couriers that support it. Sameday, Pigeon and BOX NOW must not
show it at all.

## Recommended order

1. **Pigeon** - SCAFFOLDED (code on `main`); just needs the API Key/Secret + base URL to live-verify.
2. **Express One** - readable via its open-source plugin (address + pickup-point, flat-rate); build once an API Key is in hand. Reasonably fits the existing checkout (no locker-picker needed - pickup-points are office-like).
3. **BoxNow** - build last; budget extra time for the locker-picker UX + flat-rate + origin-warehouse setting (the biggest UX divergence).

(Express One promoted above BoxNow now that its API is confirmed readable + it fits the existing office/address checkout, whereas BoxNow needs new locker-picker UI.)

Each adapter follows the proven flow: obtain creds (server-side) → confirm live API shapes (fixtures) →
`BGC_<Courier>` adapter → `BGC_Method_<Courier>` + register → settings section → `@group <courier>`
tests + E2E live-verify → review → merge.
