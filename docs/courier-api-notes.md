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
- **`CREATE_REQUEST: 1` on create-bol makes a COLLECTION ORDER instead of a waybill** - a courier comes
  to the sender's address for it, rather than the parcel being dropped at a counter. Undocumented: it is
  in neither the API documentation nor their own rest-api-helper, and came from their developer. The
  record it makes reads `STATUS_ID 1 "Създадена поръчка"` where an ordinary one reads `0 "Създадена
  товарителница"`, and it answers with an empty `PACKS` and no `RETURN_CODE`. `SEND_HOUR`/`SEND_MIN`/
  `WORK_HOUR`/`WORK_MIN` are accepted alongside it (the window the courier may come in).
- **Additional services, all measured as applied** (each changes the price, which is how one can tell
  the courier did not quietly drop it): `COD`, `INSURANCE` (обявена стойност), `CHECK_BEFORE_PAY`
  (преглед преди плащане), `PACK_COUNT`, `PAYER`, and - not offered by this plugin yet - `FRAGILE`,
  `RETURN_RECEIPT` (обратна разписка), `RETURN_DOCUMENTS`, `SATURDAY_DELIVERY`, `FIX_HOUR` (a three-part
  string, e.g. `ПРЕДИ:15:30`). Base 1.5 kg parcel to a Sofia counter 4.06; fragile + return receipt
  5.51; Saturday + return documents 6.23; fix hour 6.25.
- Also there and unused: `/1/list-all-status`, `/1/list-bol`, `/1/bol-finance-info`, `/1/list-cod-order`
  (max 35 days), `/1/info-cod-order[-detailed]`, `/1/list-invoice`, `/1/list-return-redirect`.
- **Documentation disagrees with the API** in at least: `/1/me` is documented POST and is GET-only, and
  neither the currency, the status vocabulary nor the required `RECEIVER_CITY` is in it.
- **Cash on delivery pays out through ППП** on this shop's contract (owner, 2026-08-25), so the courier
  is ticked for it; without that tick a shop that fiscalises through ППП is correctly offered no
  наложен платеж for Express One, the way BOX NOW is not.
- **RULES FROM THEIR OWN INTEGRATION DEVELOPER** (2026-08-25, after they validated nine test shipments).
  None of these is enforced by the API, which accepts every one of the fields quite happily:
  - **`FIX_HOUR` and `SATURDAY_DELIVERY` are not provided at all** - never offer them, never send them.
  - **`RETURN_RECEIPT`** is a paid service aimed at institutional clients. Their advice for a shop: it
    would mostly be ticked by mistake, which puts extra work on the courier and a charge on the
    merchant's invoice, and a claim afterwards. Left to us; not offered.
  - **NO наложен платеж to an EXOBOX locker** (2026-08-26) and **a recipient phone is mandatory for
    one**. Neither is enforced by the API - `/1/create-bol` takes `COD` beside `TAKE_OFFICE_ID` quite
    happily - so a locker parcel would print, travel and be handed over collecting nothing. Held in the
    plugin instead, in the three places the rule leaks out of otherwise:
    `BGCouriers_Expressone::no_cod_methods()` declares it, the checkout takes the cash-on-delivery
    gateway away while a locker is chosen (and stops quoting a collection fee for one), and
    `BGCouriers_Labels::generate()` refuses to book the waybill at all. Filterable through
    `bgcouriers_no_cod_methods` - the courier said "at the moment". The phone half is the checkout's
    own: a blank number is now refused on the BLOCK checkout too, where the field's required-ness is
    WooCommerce's setting rather than ours.
  - **`FRAGILE` only ever together with `INSURANCE`.**
  - **EVERY cash-on-delivery shipment carries an `INSURANCE`** (declared value). The plugin declares the
    collected amount, or the merchant's own higher figure - and puts the same number in the QUOTE,
    because the service is charged for and a price without it is lower than the invoice that follows.
  - `CREATE_REQUEST` needs no companion fields: "Игнорирайте тези параметри" about
    SEND_HOUR/SEND_MIN/WORK_HOUR/WORK_MIN.
  - **`/1/request-courier` has no request number by design**: "Полученият номер на пратка представлява и
    самата поръчка за посещения. Няма индивидуален номер на поръчка."
- **The three PDF formats are one label on this account.** 0, 1 and 2 came back the same length, the
  same page box (416.69 x 282.61 pt, a ~147 x 100 mm label) and the same drawn content; they differ in
  132 bytes, all of them the embedded `/LastModified` timestamps. Samples were sent to Express One to
  confirm it is not something peculiar to the test account.
- **The street box must not accept a typed street** for this courier - `street_list_only()` says so and
  the checkout turns select2's free tagging off for it. Found by driving a real order on dev: the picker
  offered "БУЛ. ПРОФ. ЦВЕТАН ЛАЗАРОВ", the typed "Цветан Лазаров" was stored instead, the order was
  placed happily, and the label was refused afterwards with the customer long gone.


### Sameday summons itself

**There is no pickup API.** `capabilities()` has no `pickup` and `request_pickup()` is not implemented -
the "Request a courier" screen lists Sameday as unsupported. What brings the courier is **creating the
AWB**: it enters the pickup point's collection list for that day. Measured on prod order 11260
(2026-08-26): waybill created 09:44:04 by auto-label, four seconds after checkout; by 11:45 it read
**"Отказ от взимане от подател"** - the courier had come and gone while the parcel was still on the
shelf, and the order itself said "Изпращане в: четвъртък 27 август".

`POST /api/awb` carries no pickup date to push it back with (`deliveryInterval` is about delivery), so
the only lever is WHEN the waybill is created. Hence `books_pickup_on_create()` on the courier and the
per-courier "Auto-generate labels" row: a courier that answers true starts with automatic labels OFF
whatever the shop-wide setting says, and existing installs are pinned to what they had by
`BGCouriers_Plugin::pin_autolabel()`. Questions still out with Sameday: a cutoff hour, whether the
portal has a pickup schedule, whether a refused AWB can be reused and whether it is charged.

## 4. Европът / Evropat-2000 - *MEASURED against a live account, 2026-08-31*

The API key arrived and the whole surface was mapped against the shop's own production account. What
follows replaces the guesses that stood here before; where the courier's own documentation disagrees
with the live service, the live service is what is written down.

**Their documentation is served by the API itself and it is behind the service.** `https://api.evropat.com/`
is an apiDoc page whose full spec sits inside `assets/main.bundle.js` - 29 endpoints, every one POST,
every one authenticated by a `clientKey` in the BODY (no header, no token, no session). It is worth
reading and it must not be trusted on its own: four required fields were found only by being refused.

**Every answer is HTTP 200.** Success is `{"error":null,"errorMessage":null,"response":…}`, failure is
`{"error":"CODE","errorMessage":"…на български…","request":{…},"response":null}`. The HTTP status
decides nothing.

**Their refusals name the field they actually read.** The `request` object in an error echoes the
parameter the service looked for, with `null` when it was not sent. That is how every undocumented
field below was found without guessing, and it is the technique to reach for first next time.

### Where the documentation is wrong

| documented | actually | how it failed |
|---|---|---|
| `/calculateprice` takes no destination | needs `fromDestID` **and** `toDestID` | `INVALID_FROM_DESTINATION_ID` |
| `/calculateprice` `method:1` needs nothing more | needs `clientNumber` | `INVALID_CLIENT_NUMBER` |
| `/getshipmentprice` same spelling | wants `fromDestinationID` | different endpoint, different spelling for one idea |
| `/createshipment` lists no weight field at all | requires `shipmentWeight` | `INVALID_SHIPMENT_WEIGHT` |
| `POST /print` with a `barcodes` array of up to 200 | **`/printshipment`**, ONE `shipmentBarCode` | `SERVICE_NOT_FOUND`, then the error echoed `shipmentBarCode` |
| `senderFileID` "Related Link: /library/list/addresses" | `/getclientaddresses` | the doc carries old paths throughout (`/destinations` for `/getdestinations`) |
| `/getshipmenthistory` returns name only | also returns `statusID` | the live answer is richer than its own example |

### The shape

- **Nomenclature.** `/getdestinations` (`limit: -1` for all) gives `destinationID`, name, English name,
  zone, postcode, province, `destinationServicingDays` ("1111110" from Monday) and
  `destinationServicingOfficeID` - the office that serves a town with no counter of its own.
  `/getoffices` gives id, name, address, phone, working hours and lat/lng. `/getaddresses` is a full
  per-town STREET list with an `addressID` each, which is why `street_list_only()` is true here.
- **No lockers in Bulgaria - but the reason changed.** The 2023 manual said Европът has none at all.
  The API *does*: `/get-box`, `/get-boxes`, and `deliveryType` 5 and 6 deliver to one. What settles it
  is `/getcountries`, which answers `countryBoxDeliveryAvailable: "0"` for BG. So two delivery options
  here, and the day that flag flips, `automat` becomes real without an API change.
- **`deliveryType` encodes BOTH ends** - 1 ОФ-ОФ, 2 ОФ-ВР, 3 ВР-ОФ, 4 ВР-ВР - which no other courier
  in this plugin does. Measured, Sofia -> Sofia, 1 kg, sender pays by account, EUR net:
  **office->office 4.5885, office->door 5.4389, door->door 6.5150.** A 40% spread, so the sender's end
  is a merchant setting (`bgcouriers_evropat_sender_end`) and not an assumption.
- **Price is by ZONE, not by distance.** Sofia -> Varna quoted identically to Sofia -> Sofia; both are
  zone 1.
- **The price is GROSS - and the API never says so.** There is not one mention of VAT in any request,
  response, example or error, and `price` is the exact sum of the parts listed beside it (3.313 service
  + 1.27551 fuel = 4.5885), which reads exactly like a net total. **The printed товарителница is what
  settles it:** its price block is headed **"ЦЕНА С ДДС"** and its total is that same 4.59 EUR - the
  amount the payer hands over at the door (waybill 9107785603). Every quote in this plugin is net,
  because the rate is added with `taxes => ''` and WooCommerce puts the shipping tax on top, so the
  figure is split back with `BGCouriers_Pricing::split_gross()` using the shop's own shipping rates -
  the very ones WooCommerce is about to re-add. Passing it through untouched is the 0.3.5 double-VAT
  fault. The fuel surcharge is inside the quote too: **do not add one.**
- **Two currencies in every answer,** and which is which belongs to the ACCOUNT: this one answers
  `mainCurrency: EURO` / `secondCurrency: BGN` while their own documented example answers the other way
  round. Reading `price` blindly is a 1.95583x error waiting for the first shop set up the other way.
- **Collecting money is priced and must reach the quote.** `cashOnDelivery: 50` cost 0.6136 and lifted
  the fuel surcharge with it, because the surcharge applies to the whole service and not to the base
  rate. Leaving it out is the 0.3.6 fault again.
- **ППП is an ACCOUNT permission, and its absence is silent.** `/getclientaddresses` carries
  `allowedPostalMoneyOrder`. On an account where it is "0", `postalMoneyOrder: 50` is accepted, priced
  at **0.00**, and the shipment is booked with no money to collect - no error, no flag. So the flag is
  read from the account and a ППП that cannot be done becomes a наложен платеж with a note on the
  order. Европът activates ППП per account on request.
- **`senderFileID` fills the sender half** - their own note: "actually fills the following parameters
  when they not passed: senderDestID, senderAddress, senderName, senderPhone, senderFirm, clientNumber,
  paymentWay". So the merchant picks a line from their own cabinet instead of retyping seven fields.
- **An office delivery overwrites the recipient address with the office's own,** exactly as documented:
  a waybill sent with `recipientAddress: "СОФИЯ Ул. Градинарска 7"` came back as
  `recipientAddress: "УЛ. ГРАДИНАРСКА"` + `recipientAddressNumber: "7"`.
- **The label is a LINK, not bytes.** `/printshipment` answers in the ordinary envelope with a URL -
  `{"error":null,"response":"https://api.evropat.com/printshipment?clientKey=...&barcode=..."}` - and the
  document is a GET away. Their success example says "Binary of PDF printout"; their own description one
  line above says "get the URL of the PDF file", and the description is right. **That URL carries the
  account's API key in its query string**, so it is fetched server-side and dropped: never returned,
  stored, logged, or put in an exception message.
- **There is no paper size.** `format` is documented A4/A6/sticker and is read and ignored: all three
  answered with a link ending `format=A4` and all three documents were the same 93819 bytes. So this
  courier declares no `label_formats()` and its tab offers no paper select - a control that changes
  nothing is exactly the decoration the Express One audit went looking for. The printout is their
  standard **three copies landscape on one A4**, as the 2023 manual described.
- **Cancelling.** `/cancelshipment` answers a bare `true`. A SECOND cancel of the same waybill is
  refused with `INVALID_SHIPMENT_STATE` rather than answering "already done", so `is_cancelled()` has
  to read the history - an API cancel lands on status **18** ("Анулирана от експорт" /
  "Анулирана от онлайн акаунт").
- **Tracking.** `/getshipmenthistory` returns `{statusID, stateName, dateAndTime, additionalInformation}`.
  `/shipment-statuses-nomenclature` publishes all 41 statuses; note that the history's `stateName` is
  the nomenclature's DESCRIPTION ("Създадена") and not its NAME ("Създаване"), so a name-based map has
  to index both. 19 = Разнесена (delivered), 10 = Върната на подател, 18 = Анулирана.
- **No public tracking page.** evropat.com serves its application shell for every path - `/favicon.png`
  included - and nothing there shows one waybill to somebody who is not signed in. So `tracking_url()`
  is empty and the admin's Track button is not rendered at all for this courier.

### The live proof

The whole cycle was driven through the plugin's own code on 2026-08-31 - Sofia -> Sofia, office to
office, 1 kg, no cash on delivery:

    created 9107785603 -> printed (PDF, 93807 bytes, three copies on one A4)
      -> tracked (status 2 "Разпечатана" -> our stage `registered`: still on the merchant's desk)
      -> cancelled -> is_cancelled() true

Getting there cost three earlier waybills, each spent on something the documentation had wrong: the
weight field, the print endpoint's name, and then the discovery that it answers with a link rather than
a document.

**The printed waybill is what corrected the VAT reading above** - no amount of reading the API would
have. It also confirmed two things that were until then only inferred: `senderFileID` really does fill
the whole sender half from the cabinet, and `shipmentMoreInfo` prints the order number in the
"Др. условия" box, which is the only reference field this courier has.


### Not built

`/create-international-shipment`, `/createshipmentbetweenrange`, `/changeparcelsandweight`,
`/getbulkshipmenthistory`, `/printtwowayshipment` and the box endpoints. `twoWayShipment` (their return
voucher, the same idea as Speedy's) and `shipmentValue` (declared value, which needs a document type
and number - not optional the way Speedy's is) are both real and both unmeasured.

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
