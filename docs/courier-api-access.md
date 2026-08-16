# Getting API access for the remaining couriers

Speedy and Econt are integrated and live-verified. The couriers below still need **you to open
an account and obtain API credentials** before their adapters can be built + tested. None of them
offer fully self-service API signup - each requires contacting the courier.

> **Credential handling:** once you have credentials, transfer them **server-side** (enter them in
> the plugin's WooCommerce settings on the server, encrypted) - never paste them in chat or commit
> them to git. Use a courier **sandbox/test** account where one exists so development never creates
> real shipments.

---

## 1. BOX NOW (Bulgaria) - parcel lockers (APM)

- **API docs:** https://www.boxnow.bg/diy/eshops/api (BG) · https://t.boxnow.bg/en/diy/eshops/api (EN) · Partner API manual (PDF): https://www.boxnow.bg/media/PDF/API%20integration%20manual%20-%20BOX%20NOW%20-%20EN_v1.65.pdf
- **Auth:** OAuth 2.0 **Client Credentials** - you receive a Client ID + Client Secret, exchange them for a Bearer access token.
- **Environments:** sandbox (`t.boxnow.bg`) + production.
- **Steps to get access:**
  1. Email **integrationsupport@boxnow.bg** from your business, requesting Partner API access.
  2. Provide: company name, registered address, **tax ID / ЕИК**, and contact details.
  3. Provide the **phone numbers** of the people who will use the Partner Portal (login uses an OTP SMS code).
  4. They issue your **OAuth2 Client ID + Client Secret** (sandbox first, then production).
  5. Share the Client ID/Secret with me server-side; I'll wire the `boxnow` adapter (destinations / delivery-requests / label.pdf / parcels).

## 2. Pigeon Express (Bulgaria) - courier

- **API docs:** https://api-docs.pigeonexpress.com/
- **Auth:** `X-API-Key` + `X-API-Secret` request headers.
- **Environments:** Production + Sandbox base URLs are issued with your credentials.
- **Steps to get access:**
  1. Contact Pigeon Express directly (business/sales contact via https://pigeonexpressint.com/ or their Bulgaria page) and request **developer API access**.
  2. Ask specifically for: an **API Key + API Secret**, the **Production and Sandbox base URLs**, and confirmation of the available endpoints.
  3. There is **no self-service portal** - credentials are issued by their team.
  4. Hand me the API Key/Secret server-side; the `pigeon` adapter is partly mapped already from prior art.

## 3. Express One (Bulgaria) - courier (expressone.bg)  *(newly added to the roadmap)*

- **API IS readable** (2026-06-29): Express One BG is part of the Austrian Post CEE network; the API host is the group's **`https://api.expressone.si/`**, and Express One's **official open-source WooCommerce plugin** (wordpress.org/plugins/express-one-shipment/) reveals the endpoints/shapes. Full technical map in `docs/courier-api-notes.md`. Only an **API Key** is needed from them.
- **Auth:** **API Key** (sent as `apikey=` query param + in POST bodies). Endpoints: `/apiuserinfo`, `/places`, `/parcelshops` (pickup points), `/checkcountryiseligible`, `/createshipment`, `/updateshipment`, `/pdfinternal` (label). Delivery = home + pickup-point; flat rate (no live quote).
- **Steps to get access:**
  1. Contact Express One Bulgaria - **international@expressone.bg** (or the office number on expressone.bg) - and request an **API Key** for shipment integration.
  2. Ask for: the **API Key**, a **test account** if available, and confirmation of the BG base URL (the open-source plugin uses `api.expressone.si`).
  3. Drop the API Key server-side; I'll build the `expressone` adapter against the plugin-derived shapes (validate via `/apiuserinfo`, confirm field nuances live).

---

## 4. Европът / Evropat-2000 (Bulgaria) - courier (evropat.bg)  *(owner wrote to them 2026-08-17)*

- **The API key is self-service, and that is what makes this one cheap.** It is generated from the
  merchant's own account at **https://online.evropat.com** - or by asking sales - and generating it also
  unlocks the download of their plugin and its instructions. No procurement, no waiting on a person,
  which is the opposite of Express One.
- **Auth:** an **API Key**. There is **no public technical documentation**: it ships with the key, so the
  shapes cannot be mapped until the key exists. Nothing about this courier should be built from the
  descriptions written by third-party integrators.
- **Steps to get access:**
  1. Generate the API Key in the online cabinet, or e-mail **sales@evropat.com** and ask for one.
  2. Take the documentation and their own WooCommerce plugin that the key unlocks - the plugin is the
     same kind of readable prior art that made Express One's shapes knowable in advance.
  3. Drop the key server-side; the adapter goes on the existing multi-courier framework like the rest.
- **Two things NOT confirmed, and both change what the checkout shows:**
  - **Lockers/APS are not mentioned anywhere on their site** - only offices and addresses. If that holds,
    Европът gets two delivery options here, not three.
  - **Cash on delivery** is not stated in those words, but they do offer a **postal money order**
    service, which is the mechanism this shop already fiscalises through (`cod_fiscalization = ppp`).
    Encouraging, not proof - confirm the field name against the real documentation.
- **Why it is worth doing:** a full national office network, and their own WooCommerce plugin already
  exists - which is both proof the integration is real and a reminder that our value here is having every
  courier in one place, not merely supporting this one.

---

## Roadmap

1. **Speedy** - done, live-verified, on `main`.
2. **Econt** - Phase 2 (in progress), live-verified against your real account.
3. **BOX NOW**, **Pigeon Express**, **Express One**, **Европът** - each a Phase-3 adapter on the existing multi-courier
   framework (registry + `BGC_Method_*` + settings section + `@group <courier>` tests), built once its
   credentials are available. Each follows the same shape as Econt: confirm live API shapes → adapter
   (nomenclature/quote/label/track) → method + settings → checkout + E2E live-verify.
