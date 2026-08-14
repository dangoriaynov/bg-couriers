=== BG Couriers for WooCommerce ===
Contributors: winter2007d
Donate link: https://revolut.me/danq6lus
Tags: woocommerce, shipping, bulgaria, courier, cash on delivery
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.2.12
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
* Tap anywhere on a courier row at checkout to choose it - a full-width target on phones.
* Per-order and bulk label generation to a combined PDF (A6 / A4), tracking links.
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
* **Pigeon Express** - api.pigeonexpress.com (demo host api-demo.pigeonexpress.com in test mode). Terms: https://pigeonexpress.com/terms · Privacy: https://pigeonexpress.com/privacy
* **BOX NOW** - api-production.boxnow.bg (stage host api-stage.boxnow.bg in test mode), and the locker-selection **map widget map.boxnow.bg**, which is loaded in an iframe **only when the customer opens the BOX NOW locker picker**. Terms: https://boxnow.bg/terms-of-use-for-shipping-services · Privacy: https://boxnow.bg/personal-data-processing-notice
* **Sameday** - api.sameday.bg (demo host sameday-api-bg.demo.zitec.com in test mode). Terms: https://sameday.bg/terms-and-conditions-delivery-courier-services-bg/ · Privacy: https://sameday.bg/politika-za-poveritelnost/

**Maps:** the office/locker map picker loads map tiles from **OpenStreetMap (tile.openstreetmap.org)** - only when the customer chooses to open the map. The optional **address map picker is DISABLED by default**; if the merchant enables it in the settings, it additionally reverse-geocodes a point via **OpenStreetMap Nominatim (nominatim.openstreetmap.org)** - only the coordinates of the pin the customer drops, only when they drop it, never automatically. OSM tile policy: https://operations.osmfoundation.org/policies/tiles/ · Nominatim policy: https://operations.osmfoundation.org/policies/nominatim/ · Privacy: https://wiki.osmfoundation.org/wiki/Privacy_Policy
If the merchant sets a **Google Maps API key** (optional), the address picker instead uses **Google Maps Geocoding (maps.googleapis.com)** for that lookup - sending only the picked coordinates. Google terms: https://cloud.google.com/maps-platform/terms · Privacy: https://policies.google.com/privacy

No data is sent to any service the merchant has not configured, and the plugin sends nothing to the plugin author.

== Contributing ==

The plugin is developed in the open. Bugs, ideas and pull requests are welcome:
https://github.com/dangoriaynov/bg-couriers/issues

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

1. Checkout: delivery-type tabs (to office / address / APS locker), searchable city and office pickers, a live per-courier price, and a "Map" button.
2. Checkout: the office/locker map picker - a searchable list beside the map, markers, and "show my location".
3. Order screen: the shipment panel (waybill number + print / track / cancel) and the inline delivery editor (courier, delivery option, city, office/APS).
4. Orders list: the "Waybill" column - generate a label, or print / track / cancel an existing one, per order.
5. Settings: a courier tab - COD payout method, open-before-payment, contents/package, the delivery-option sub-tabs and per-method prices.
6. Settings: masked credentials with Validate / Sync and the built-in "How do I get API credentials?" hint.

== Changelog ==

= 0.2.12 =
* The interactive map now opens as a small dialog asking only where you are collecting from, and grows into the full map once it has something to show. It used to open at full width straight away, leaving the city field alone against the left edge of a large empty panel with the sidebar and search box of a list that did not exist yet.
* While the offices are being fetched the dialog now says so in words. There was a spinner before, but it was a small circle adrift in that empty panel, which read as nothing happening at all.

= 0.2.11 =
* Fixed: the interactive map could come up completely empty for a city, with no error shown. It asked every courier's server directly, for every delivery type, in one request - and if any of them was slow the whole request died. The busiest cities usually worked because their answers were still cached from an earlier customer; quieter ones were a lottery. It now reads the office list the plugin already keeps in sync, so it answers every time.

= 0.2.10 =
* The city box on the interactive map is now instant. It used to ask the server on every keystroke, and each of those requests costs a full WordPress load - about five seconds on a busy shop - so typing a city name looked like nothing was happening. It now searches the list of places the page already carries, exactly as each courier's own city field has always done.

= 0.2.9 =
* The interactive map now has a layout for phones. It used to stack the list and the map and give each a fixed minimum height, so on a phone the map arrived about 167px tall with the rest cut off. A narrow screen - or a short one, which is a phone held sideways - now shows one of them at a time, full screen, with a floating button to swap between them. The search, the prices and the courier filter are all still there.
* Fixed: on the map, the + and - zoom buttons were drawn on top of a pin's popup when it opened near the top-left corner, printing them across the courier and office names.
* Fixed: the shop's chat bubble was painted over the map, covering part of it on a phone. The map dialog now sits above it, and the bubble comes back when the map closes.
* Fixed: on a phone, the "Choose" button in a pin's popup could open underneath the map/list switch, where it was visible but could not be pressed.
* Fixed: hover hints in the admin were cut off at the edge of the panel or the orders table, and a hint explaining why an action is blocked was itself dimmed to the point of being hard to read. Hints are now drawn once, on top of everything, and stay inside the window.
* Fixed: with a shipment already collected, the delivery editor greyed out the courier and delivery-option fields but left the city, street and office fully editable - dropdowns, clear buttons and the map picker all worked. Every field is now genuinely read-only. Saving was already refused by the server, so no order was ever changed this way.

= 0.2.8 =
* Fixed: choosing a locker on the interactive map opened that courier's "to address" tab with an empty street form. The locker was saved but invisible, and the customer was being asked for an address for a parcel meant to go to a machine.
* The office or locker this courier already has selected is now marked on the map with a pulsing ring, so re-opening the map shows you what you chose rather than making you find it again. It keeps its courier's colour - the legend stays true.
* Fixed: the office map in the order editor had its search box and "show my location" button squashed, because it shares a stylesheet with the checkout's and had been left behind when that one changed. Both now look the same: search and location on one row, location as an icon.
* Fixed: a house number typed into the address fields could be lost if the checkout recalculated within the second after typing. The fields are now saved as soon as you leave them.
* Fixed: choosing a point on the map left a JavaScript error in the browser console.

= 0.2.7 =
* New: an **interactive map** at checkout showing every enabled courier's offices and lockers for a city at once, each with that courier's own price. Pick a point and the courier, delivery type, city and office are filled in for you. A legend names each courier, colours its pins and doubles as a filter; the list beside the map is searchable, and there is a "show my location" button. Offices and lockers share one map, because a customer looking for somewhere to collect a parcel is not thinking in those categories until they see what is nearby. It can be switched off in Settings; it is on by default.
* Each courier's own "Map" button now opens that same map, filtered to that courier, with the city already chosen carried over. The separate per-courier map is gone: it answered a narrower question, and keeping two meant every fix had to land twice.
* Fixed: the delivery price shown BEFORE a city is chosen was quoted for a fixed 2 kg parcel whatever the cart weighed, so a 10 kg order advertised the 2 kg price until the customer picked a town. It is now quoted for the cart's real weight. Prices after a city is chosen were already weight-aware.
* Fixed: clicking the delivery-type button you are already on cleared the office you had chosen and reloaded the list. It now does nothing, which is what it always should have done.
* Fixed: with a shipment already collected, the delivery editor's Save button was disabled on the server but switched back on in the browser, so the form invited a save it was always going to refuse. The Orders list now dims the edit pencil instead of showing a padlock beside a control that still looked usable.
* An order whose parcel has been collected can be worked on again by putting it back to Processing or Pending payment - the waybill can then be cancelled, re-issued and re-addressed as before.
* The checkout's (i) beside each price is now an icon that says which way the delivery is paid: a banknote when the courier collects the money at the door, a shopping bag when it is already in the order total.
* The plugin's request limit for its own public lookups was too tight for real use - several customers behind one address (an office, or a mobile network) could exhaust it between them and see an empty map with no error.

= 0.2.6 =
* The shipment state in the Orders list is now a single icon per status instead of the courier's own sentence. Courier wordings run long, the column is narrow, so the line wrapped and every row stood taller than its own buttons; the icon sits in the button row and the full sentence, with the time it was updated, is on hover. Each status has its own drawing and its own colour.
* Fixed: those status colours were never actually visible in the Orders list. They were applied as an inline style, which WordPress strips from this column when it prints it, so every row looked the same whatever state its parcel was in.
* The "update from the courier" button on the order screen is now pinned to the corner of the shipment box. It used to sit at the end of the status line, so a longer status - or the padlock appearing once the courier collected the parcel - pushed it onto another line.

= 0.2.5 =
* Fixed: Pigeon Express orders stayed on "Label created" for the whole life of the parcel and never moved to Shipped, however far the shipment had actually travelled. The plugin was reading Pigeon's tracking history from the wrong place in its reply, so it always looked empty - and an empty history is what tells the plugin the courier has not collected anything yet. Tracking for the other couriers was never affected. Existing Pigeon orders correct themselves at the next tracking check, or straight away with the refresh button on the order.
* Fixed: a Pigeon parcel that had arrived at the office or locker was read as delivered to the customer, which completed the order and stopped the parcel being tracked at all - so nobody would have seen it go unclaimed and travel back. It is now shown as waiting for collection, and Pigeon's own status codes decide this, not the wording of the message.
* Fixed: the bundled PDF library used to build multi-label sheets is now under this plugin's own name, so it cannot clash with another plugin that bundles the same library - a shop that also prints invoices or packing slips no longer depends on which plugin loaded first.

= 0.2.4 =
* Removed the second-currency display. Bulgaria stopped requiring dual BGN/EUR prices on 9 August 2026, so delivery prices now show only in the shop currency and the "Enable 2 currencies" setting is gone. Site-wide currency conversion, if you still want it, belongs to a dedicated currency plugin.

= 0.2.3 =
* Fixed: the heading above the shipping methods is now recognised by watching what WooCommerce itself put there, instead of looking its wording up in WooCommerce's translations. It is removed in every language, including ones where WooCommerce has not translated it yet - and the plugin no longer reads a catalogue that is not its own.

= 0.2.2 =
* New setting "Use the plugin's own address fields": the plugin removes WooCommerce's Address / City / Region / Post code at checkout, because the courier city, office or automat picked there is the delivery address. Ships on, as before - turn it off if your store also delivers some other way and needs those fields.
* New setting "Hide the cart shipping calculator": the same choice for WooCommerce's "Calculate shipping" box on the cart, which prices a delivery to a post code while every price here is for the office, automat or address picked at checkout. Ships on, as before.
* Removed the Print Invoices/Packing Lists integration. Printing a document is the job of the plugin that prints it, and this one had grown from "say which courier" into type sizes and column widths for a page it does not own. Everything a printing plugin needs stays available: BGCouriers_Labels::order_courier(), BGCouriers_Couriers::logo_url(), BGCouriers_Icons::method_label() and the _bgcouriers_method / _bgcouriers_waybill order meta.
* Fixed: the example phone number shown in the BOX NOW "sender phone is invalid" message, and in that field's placeholder, was a real number.
* Bugs and ideas: https://github.com/dangoriaynov/bg-couriers/issues

= 0.2.1 =
* New "Shipped" order status, set automatically once tracking shows the courier has actually collected the parcel (optional, off by default; pairs with the existing on-delivery status rule).
* One-click waybill re-issue on the orders list and the order screen, and as a bulk action - voids the current waybill and issues a new one from the order's current details.
* Orders list: rows tinted by courier (colour per courier in the settings), and the plugin's bulk actions grouped under their own section.
* Checkout: tap anywhere on a courier row to choose it - a full-width target on phones.
* Settings: an unsaved-changes indicator, with a warning before you navigate away.
* Shared default parcel weight for every courier, and a per-courier parcel-contents description that Sameday now sends too.
* Fixed: Pigeon office lists were truncated to the first 100 offices, so most of the country's offices could not be picked.
* Fixed: Speedy tracking showed raw status codes instead of the courier's own wording, and never recognised delivery.
* Fixed: cancelling a waybill left cached alternate-size labels behind, so a later print could show the voided number.

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
* Automatic shipment-status tracking (WP-Cron) with order notes, and an optional “Shipped” order status set automatically once the courier actually picks the parcel up (and “Completed” on delivery).
* One-click waybill re-issue from the orders list and the order screen - voids the current waybill and issues a new one from the order's current details; also available as a bulk action that re-issues every selected order.
* Orders list rows tinted by courier, with a colour per courier in the settings.
* Unsaved-changes indicator on the settings screen, with a warning before you navigate away.
* Bulk actions grouped under their own section in the orders-list dropdown; bulk generate and bulk print only create the MISSING waybills and never touch existing ones.
* Free-shipping thresholds per delivery option (a courier-level threshold overrides them), shared parcel dimensions, weight and contents description for all couriers, per-option card-payment control for Speedy COD.

= 0.1.0 =
* Initial release: Speedy, Econt and Pigeon Express - office/address/APS delivery, live rates, labels, tracking.

== Upgrade Notice ==

= 0.2.8 =
Fixes choosing a locker on the interactive map, which landed on an empty address form, and marks the office you already picked when the map is re-opened.

= 0.2.7 =
Adds an interactive map of every courier's offices and lockers at checkout, and fixes the price shown before a city is chosen, which ignored the weight of the cart.

= 0.2.6 =
The Orders list gets its height back: the shipment state is an icon with a hover hint instead of a wrapping sentence, and the per-status colours are finally visible.

= 0.2.5 =
Fixes Pigeon Express tracking: orders follow the parcel again instead of sitting on "Label created", and a parcel waiting at the office is no longer counted as delivered. Recommended for anyone shipping with Pigeon.

= 0.2.3 =
Fixes the heading above the shipping methods in locales where WooCommerce has not translated its own wording yet.

= 0.2.2 =
The Print Invoices/Packing Lists integration is gone: a packing list that showed the courier and waybill no longer will. Replacing WooCommerce's checkout address fields and hiding the cart shipping calculator are now settings, both on by default.

= 0.2.1 =
Adds an optional "Shipped" order status driven by courier tracking, one-click waybill re-issue, courier-coloured order rows, and fixes Pigeon office lists and Speedy tracking statuses.

= 0.2.0 =
Adds BOX NOW, Sameday, cash on delivery, a map picker and Bulgarian translation.
