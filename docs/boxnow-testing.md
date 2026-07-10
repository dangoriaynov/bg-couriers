# BOX NOW - testing guide (the generic approach)

This is the **generic approach for testing BOX NOW** with this plugin, using BOX NOW's **stage
(sandbox)** environment. Follow it for every BOX NOW test cycle; production testing is the same flow
with production credentials + real APMs (see the last section).

> **Credentials are NOT in this file.** The OAuth **Client ID + Client Secret** live ONLY in the
> plugin's encrypted settings (server-side) - never in the repo, per the project's credential rule.
> The **stage creds are already stored on dev** (encrypted) as `bgc_boxnow_username` +
> `bgc_boxnow_password`, with `bgc_boxnow_base_url` / `bgc_boxnow_partner_id` / `bgc_boxnow_origin_id`
> set - round-trip verified, so the build can use them directly (no re-entry). Everything below is
> non-secret test configuration + the rules to follow.

## Stage environment
- **API base:** `https://api-stage.boxnow.bg`
- **Static location lists:** `https://locationapi-stage.boxnow.bg`
- **Locker-picker widget:** `https://widget-v5.boxnow.bg`
- **Auth:** OAuth2 client-credentials → `POST /api/v1/auth-sessions {grant_type:"client_credentials", client_id, client_secret}` → `{access_token}` (Bearer, ~1 h - cache it).
- **Required header on EVERY call:** `X-PartnerID: 11239`

## Which "boxes" to use
- **Origin (where the parcel ships FROM)** - the plugin's BOX NOW origin setting:
  - `5726` → **warehouse** origin (*доставка с взимане от склад* - courier collects from your warehouse).
  - `2` → **any-apm** origin (*изпращане от всеки автомат* - you drop the parcel at any APM).
- **Destination (the customer's locker)** - chosen via the BOX NOW **widget** (returns `boxnowLockerId`).

## Testing limitations - IMPORTANT, follow exactly
- ✅ Generate **test waybills only to APM `8009`**.
- 🚫 **Do NOT use APMs from the real widget map** - those are **production-only** and must not receive stage shipments.
- Stage labels are throwaway test data → log each one in `docs/test-boxnow-waybills.md`; the **owner cancels** them (Claude never cancels test waybills).

## How to test (end to end)
1. **Auth** - `POST /api/v1/auth-sessions` → Bearer token. Send `X-PartnerID: 11239` + `Authorization: Bearer <token>` on every subsequent call.
2. **Sanity (optional)** - `GET /api/v1/origins` → confirm `5726` + `2`; `GET /api/v1/destinations` → APM list/shape.
3. **Pick a locker** - in a real checkout the **widget** returns `boxnowLockerId`. For API-only label tests, use destination APM **`8009`** directly.
4. **Create the shipment** - `POST /api/v1/delivery-requests` with `origin.locationId` = `5726` (or `2`), `destination.locationId` = `8009`, plus `orderNumber`, `invoiceValue`, `paymentMode` (`prepaid` / `cod` + `amountToBeCollected`), `items[]` → returns `{referenceNumber, parcels:[{id}]}`.
5. **Label** - fetch the PDF: `/api/v1/labels` (the official plugin uses this) or `/api/v1/parcels/{id}/label.pdf` (the manual) - try `/labels` first.
6. **Track** - `GET /api/v1/parcels` → parcel `state` + `events` (also pushed via webhook).
7. **Log** the `referenceNumber` in `docs/test-boxnow-waybills.md` for the owner to cancel.

## Going to production
Same flow, swapping: production base `https://api.boxnow.bg`, **production** Client ID/Secret + Partner ID,
your real warehouse origin, and **real APMs** from the widget map (`8009` is stage-only).

Related: build plan `docs/superpowers/plans/2026-06-29-boxnow-phase5.md`; credential acquisition
`docs/getting-api-credentials.md`.
