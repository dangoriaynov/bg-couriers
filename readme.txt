=== BG Couriers for WooCommerce ===
Contributors: winter2007d
Donate link: https://revolut.me/danq6lus
Tags: woocommerce, shipping, bulgaria, courier, cash on delivery
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shipping for Bulgarian stores: Speedy, Econt, BOX NOW, Pigeon Express, Sameday - office/address/locker delivery, live rates, labels, tracking.

== Description ==

BG Couriers adds the major Bulgarian couriers to WooCommerce as shipping methods. At checkout the customer picks a delivery type per courier - **to an office, to an address, or to an APS/locker** - searches for their city and office (or picks it from an **interactive map**), and sees a **live price** from the courier's own API. The merchant generates shipping labels and tracks shipments from the WordPress admin.

Deliveries are Bulgaria-only.

**Couriers**

* **Speedy** - office / address / APS, live rates, labels, tracking.
* **Econt** - office / address / Econtomat, live rates, labels, tracking, and **cash on delivery (наложен платеж)** with an itemised packing list paid out via your postal-money-transfer agreement.
* **Pigeon Express** - office / address / locker, live rates, labels, tracking.
* **BOX NOW** - locker (APM) delivery with an embedded GPS map picker, flat rate.
* **Sameday** - office / address / easyBox, live rates, labels, tracking.

**Highlights**

* Live prices per courier and delivery type, with a daily reference baseline and a configurable fallback.
* Interactive map picker for offices/lockers (geolocation "nearest to me").
* Per-order and bulk label generation to a combined PDF (A6 / A4), tracking links.
* Optional dual BGN + EUR price display (fixed peg 1 EUR = 1.95583 BGN).
* Per-courier settings: only the fields each courier actually needs.

== External services ==

This plugin relies on the online APIs of the couriers you enable to calculate shipping prices, create shipping labels and track shipments. Data is sent **only for the couriers you configure**, and only when an action requires it (a checkout price quote, a label generation, or a tracking lookup).

**What is sent and when**

* **Price quote (checkout / cart):** the parcel weight, the destination city/office and delivery type. Triggered when a customer views shipping options.
* **Label creation (admin):** the recipient's name, phone, e-mail, and the chosen address or office/locker, the parcel weight, and - if you enable cash on delivery - the amount to collect and an item list. Triggered when you generate a label.
* **Tracking:** the waybill number. Triggered when you or the customer open tracking.

**Services used** (each only if you enable that courier):

* **Speedy** - api.speedy.bg. Terms: https://www.speedy.bg/en/terms-and-conditions · Privacy: https://www.speedy.bg/en/privacy-policy
* **Econt** - ee.econt.com. Terms: https://www.econt.com/en/terms · Privacy: https://www.econt.com/en/privacy-policy
* **Pigeon Express** - api.pigeonexpress.com. Terms/Privacy: https://pigeonexpress.com
* **BOX NOW** - api-production.boxnow.bg, and the locker-selection **map widget map.boxnow.bg**, which is loaded in an iframe **only when the customer opens the BOX NOW locker picker**. Terms/Privacy: https://boxnow.bg
* **Sameday** - api.sameday.ro (or the demo host sameday-api.demo.zitec.com in test mode). Terms/Privacy: https://sameday.bg

**Maps:** the map pickers (office/locker, and the address picker) load map tiles from **OpenStreetMap (tile.openstreetmap.org)** and reverse-geocode a picked point via **OpenStreetMap Nominatim (nominatim.openstreetmap.org)** - only when the customer opens a map / drops a pin. OSM tile policy: https://operations.osmfoundation.org/policies/tiles/ · Nominatim policy: https://operations.osmfoundation.org/policies/nominatim/ · Privacy: https://wiki.osmfoundation.org/wiki/Privacy_Policy
If the merchant sets a **Google Maps API key** (optional), the address picker instead uses **Google Maps Geocoding (maps.googleapis.com)** for that lookup - sending only the picked coordinates. Google terms: https://cloud.google.com/maps-platform/terms · Privacy: https://policies.google.com/privacy

No data is sent to any service the merchant has not configured, and the plugin sends nothing to the plugin author.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/bg-couriers` (or install via the Plugins screen) and activate it. WooCommerce must be active.
2. Go to **WooCommerce → Settings → BG Couriers**.
3. Open a courier's tab, enter its API credentials, click **Validate**, then **Sync** its cities/offices.
4. Add the courier's shipping method to your **Bulgaria** shipping zone (WooCommerce → Settings → Shipping).

== Frequently Asked Questions ==

= Which countries are supported? =
Bulgaria only.

= Do I need an account with the couriers? =
Yes. Each courier requires its own API credentials, obtained from that courier. Enter them on the courier's settings tab.

= How are prices calculated? =
Live from each courier's API for the parcel weight and destination. If the API is briefly unreachable, a daily reference price (or your configured default) is used. BOX NOW uses a flat rate you set (it has no rate API).

= Does it bundle any third-party libraries? =
Yes, and all are GPL-compatible and shipped with their source: **FPDF** (permissive) and **FPDI** (MIT) to compose label PDFs, and **Leaflet** (BSD-2-Clause) with OpenStreetMap tiles for the map picker. The courier and courier brand names are used only to identify the services the plugin integrates with; this plugin is not affiliated with or endorsed by any of them.

== Screenshots ==

1. Checkout: delivery-type tabs (to office / address / APS locker), searchable city and office pickers, a live per-courier price with optional dual BGN/EUR, and a "Map" button.
2. Checkout: the office/locker map picker - a searchable list beside the map, markers, and "show my location".
3. Order screen: the shipment panel (waybill number + print / track / cancel) and the inline delivery editor (courier, delivery option, city, office/APS).
4. Orders list: the "Waybill" column - generate a label, or print / track / cancel an existing one, per order.
5. Settings: a courier tab - COD payout method, open-before-payment, contents/package, the delivery-option sub-tabs and per-method prices.
6. Settings: masked credentials with Validate / Sync and the built-in "How do I get API credentials?" hint.

== Changelog ==

= 0.2.0 =
* BOX NOW courier (locker delivery + embedded GPS map widget).
* Sameday courier (address/easyBox/Sameday Point) - services and pickup point auto-discovered from your account.
* Econt cash on delivery (наложен платеж) with itemised packing list.
* Interactive office/locker map picker (Leaflet + OpenStreetMap) for Speedy/Econt/Pigeon/Sameday.
* Optional dual BGN/EUR price display.
* Courier-aware checkout validation and full Bulgarian translation.
* Per-courier "Delivery in the order total" toggle - or let the customer pay the courier on delivery (COD then collects only the goods total).
* Thank-you page order summary: [bgc_order_summary] shortcode for custom pages + a delivery card on the native order-received page.
* Speedy batch printing uses Speedy's own 4-labels-per-A4 layout.
* Automatic shipment-status tracking (WP-Cron) with order notes.

= 0.1.0 =
* Initial release: Speedy, Econt and Pigeon Express - office/address/APS delivery, live rates, labels, tracking.

== Upgrade Notice ==

= 0.2.0 =
Adds BOX NOW, Sameday, cash on delivery, a map picker and Bulgarian translation.
