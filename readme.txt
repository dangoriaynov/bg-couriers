=== BG Couriers for WooCommerce ===
Contributors: winter2007d
Donate link: https://revolut.me/danq6lus
Tags: speedy, econt, box now, sameday, express one
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.13
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bulgaria's couriers in WooCommerce: Speedy, Econt, BOX NOW, Pigeon, Sameday, Express One, Evropat - office, address and locker delivery, live rates.

== Description ==

BG Couriers puts Bulgaria's couriers inside WooCommerce: your customer chooses where the parcel goes and sees what that delivery costs, and you print the label and follow the parcel without leaving WordPress.

**At the checkout** every courier you switch on shows its own price for the basket, live from that courier's API. Your customer picks how the parcel is delivered - to an office, to an address, or to a locker/APS - and finds the office by typing the town, or by pointing at it on one map that carries every courier's offices and lockers at once, each with its own price. The map can also say which pickup point is closest to them.

**In the admin** one click issues the waybill and the label - one order, or fifty of them into a single PDF (A6 labels or an A4 sheet). The plugin then keeps each shipment's status up to date by itself, and your customer sees the waybill and a tracking link on their order and in the e-mails you already send them.

**It is free**, GPL, and stays that way: every courier, every feature, no paid tier. Deliveries are within Bulgaria.

**На български:** доставка с куриер за WooCommerce - Спиди, Еконт, BOX NOW, Sameday (easyBox), Pigeon Express, Express One и Европът. Всички куриери в един плъгин: до офис, до адрес или до автомат, наложен платеж, товарителници и етикети с едно кликване, проследяване на пратката. Интерфейсът е изцяло на български.

= Couriers =

* **Speedy (Спиди)** - office / address / APS. Live rates, labels, tracking.
* **Econt (Еконт)** - office / address / Econtomat. Live rates, labels, tracking, and **cash on delivery (наложен платеж)** with an itemised packing list.
* **Pigeon Express** - office / address / locker. Live rates, labels, tracking.
* **Sameday** - office / address / easyBox. Live rates, labels, tracking.
* **BOX NOW** (BoxNow) - lockers (APM) only, picked by town and locker like every other courier (the towns are read off its lockers). Flat rate.
* **Express One** - office / address / EXOBOX locker. Live rates, labels, tracking. The address is chosen from Express One's own street list, which is what its waybills require.
* **Европът (Evropat)** - office / address. Live rates, labels, tracking. The address is chosen from Европът's own street list, which its waybills require.

= Setting it up =

You need your own account with each courier you want to offer: the prices your customers see and the labels you print are your own contract's, and nothing is resold through this plugin. Once you have the API credentials, a courier takes a couple of minutes:

1. Paste the credentials on that courier's tab and switch the courier on - it checks them with the courier there and then, and says so plainly if they are refused.
2. Sync its towns and offices - one button.
3. Add it to your **Bulgaria** shipping zone - and to the zone of any other country you deliver to.

Everything else already has a working default: the prices, the map, the checkout fields and the label size all work as they ship.

= Also included =

* **Cash on delivery (наложен платеж)**, with the choice of who pays the delivery - and the amount to collect follows that choice.
* Free-shipping thresholds, per courier and per delivery type.
* Several parcels in one shipment, and insurance for a value you set (Speedy, Sameday).
* A delivery estimate on the cart page, before the customer reaches the checkout.
* Labels issued automatically when an order reaches a status you choose - or per courier, or not at all.
* Works on both the classic and the block checkout.
* Fully translated to Bulgarian.

== External services ==

The plugin uses the online API of each courier **you enable**, to price a delivery, to create a label and to track a parcel. Nothing is sent to a courier you have not configured.

= What is sent, and when =

* **Price quote** (cart / checkout) - the parcel weight, the destination town or office, and the delivery type. When the customer views the shipping options.
* **Label** (admin) - the recipient's name, phone, e-mail, the chosen address or office/locker, the parcel weight, and, with cash on delivery, the amount to collect and the item list. When you generate the label.
* **Tracking** - the waybill number. When tracking is opened or refreshed.

= Courier APIs =

* **Speedy** - api.speedy.bg. [Terms](https://www.speedy.bg/en/terms-and-conditions) · [Privacy](https://www.speedy.bg/en/gdpr)
* **Econt** - ee.econt.com. [Terms](https://www.econt.com/en/terms) · [Privacy](https://www.econt.com/en/privacy-policy)
* **Pigeon Express** - api.pigeonexpress.com (api-demo.pigeonexpress.com in test mode). [Terms](https://pigeonexpress.com/terms) · [Privacy](https://pigeonexpress.com/privacy)
* **Sameday** - api.sameday.bg (sameday-api-bg.demo.zitec.com in test mode). [Terms](https://sameday.bg/terms-and-conditions-delivery-courier-services-bg/) · [Privacy](https://sameday.bg/politika-za-poveritelnost/)
* **Express One** - system.expressone.bg. [Terms](https://expressone.bg/bg/terms) · [Privacy](https://expressone.bg/bg/privacy-policy)
* **BOX NOW** - api-production.boxnow.bg (api-stage.boxnow.bg in test mode). [Terms](https://boxnow.bg/terms-of-use-for-shipping-services) · [Privacy](https://boxnow.bg/personal-data-processing-notice)
* **Европът (Evropat)** - api.evropat.com. [Terms](https://evropat.bg/terms/) · [Privacy](https://evropat.bg/%D0%9F%D0%BE%D0%BB%D0%B8%D1%82%D0%B8%D0%BA%D0%B0+%D0%B7%D0%B0+%D0%BF%D0%BE%D0%B2%D0%B5%D1%80%D0%B8%D1%82%D0%B5%D0%BB%D0%BD%D0%BE%D1%81%D1%82+%D0%BD%D0%B0+%D0%95%D0%B2%D1%80%D0%BE%D0%BF%D1%8A%D1%82)

= Maps and address lookup =

* **OpenStreetMap tiles** - tile.openstreetmap.org. Map tiles only, loaded when the customer opens a map. [Tile policy](https://operations.osmfoundation.org/policies/tiles/) · [Privacy](https://wiki.osmfoundation.org/wiki/Privacy_Policy)
* **OpenStreetMap Nominatim** - nominatim.openstreetmap.org. One set of coordinates, turned into an address or a town name. It happens in two cases only: the customer drops a pin on the **address map picker** (a setting, **off by default**), or presses "find me" on the map before naming a town, so the town can be filled in for them. [Nominatim policy](https://operations.osmfoundation.org/policies/nominatim/) · [Privacy](https://wiki.osmfoundation.org/wiki/Privacy_Policy)
* **Google Maps Geocoding** - maps.googleapis.com. Takes over those same lookups, and only if you set a Google Maps API key in the settings (optional; OpenStreetMap is used if the key is empty or Google does not answer). It receives the coordinates and nothing else. [Terms](https://cloud.google.com/maps-platform/terms) · [Privacy](https://policies.google.com/privacy)

The map's "closest to you" (on by default, switchable off) works out the distances in the customer's own browser. Their position is never stored on the site and is forgotten when the page is closed.

No data is sent to any service you have not configured, and the plugin sends nothing to its author.

== Contributing ==

The plugin is developed in the open. Bugs, ideas and pull requests are welcome on [GitHub](https://github.com/dangoriaynov/bg-couriers/issues).

== Installation ==

1. Upload the plugin to `/wp-content/plugins/bg-couriers` (or install via the Plugins screen) and activate it. WooCommerce must be active.
2. Go to **WooCommerce → Settings → BG Couriers**.
3. Open a courier's tab, enter its API credentials, click **Validate**, then **Sync** its cities/offices.
4. Add the courier's shipping method to your **Bulgaria** shipping zone (WooCommerce → Settings → Shipping), and to the zone of any other country you deliver to.

== Frequently Asked Questions ==

= Which countries are supported? =
Bulgaria, by seven Bulgarian networks: **Speedy**, **Econt**, **Pigeon Express**, **Sameday** and **Express One** deliver to an office, to a street address or to a locker, **Европът** to an office or a street address, and **BOX NOW** to its lockers (APM). Delivery outside Bulgaria is not offered.

= How can I support the development? =
The plugin is free, GPL, and stays that way - every courier, every feature, no paid tier. If it has saved you work and you would like to put something behind it: [revolut.me/danq6lus](https://revolut.me/danq6lus). It is entirely voluntary and changes nothing about the support you get. Reporting a bug in the [support forum](https://wordpress.org/support/plugin/bg-couriers/), or leaving a review, helps just as much.

= When is the waybill created, and when should it be? =
Either when you choose, or by itself. **Auto-generate labels** (BG Couriers -> General) issues the waybill the moment an order reaches the status you pick; each courier's own tab can overrule that for itself. With it off, an order shows a **Generate** button instead, and the bulk action **Print waybills A4/A6** creates any that are missing and hands you one PDF - so you print at the packing table and the waybill is made at that moment.

If the order says when it ships - a dispatch day shown at the checkout, the way the Order Delivery Date plugin records one - **Wait for the dispatch day** (General, on by default for new installs) holds the automatic waybill for the morning of that day rather than the moment of payment; the order says so in a note and the shipment panel shows the day. Generating by hand is never held back, and an order that names no day is labelled at once. Shops that were already running keep issuing at once until they tick the box. Any other source of a dispatch day can answer through the `bgcouriers_dispatch_time` filter.

It matters more than it sounds. For most couriers a waybill is only data, and the visit is a separate request you make with **Request a courier**. **Sameday has no such request**: creating the AWB is what puts the parcel in that day's collection list, and its courier comes for it - measured within two hours. A waybill issued the moment an order is paid therefore sends a van to a parcel nobody has packed yet, the courier finds an empty counter and voids the waybill. So for couriers that behave that way this plugin leaves automatic labels **off by default**, whatever the general setting says, and you turn them on only if your parcels really are ready that early. Shops that were already running keep whatever they had.

= Do I need an account with the couriers? =
Yes. Each courier requires its own API credentials, obtained from that courier. Enter them on the courier's settings tab.

= How are prices calculated? =
Live from each courier's API for the parcel weight and destination. If the API is briefly unreachable, a daily reference price (or your configured default) is used. BOX NOW uses a flat rate you set (it has no rate API).

= Does it bundle any third-party libraries? =
Yes, and all are GPL-compatible and shipped with their source: **FPDF** (permissive) and **FPDI** (MIT) to compose label PDFs, and **Leaflet** (BSD-2-Clause) with OpenStreetMap tiles for the map picker. The courier and courier brand names are used only to identify the services the plugin integrates with; this plugin is not affiliated with or endorsed by any of them.

== Screenshots ==

1. Checkout: every courier with its own live price, delivery-type tabs (to office / to address / to APS locker) and searchable city and office pickers.
2. Checkout: choosing an office - the list searches as you type, and each entry shows the full address.
3. The interactive map: every courier's offices and lockers for a town at once, each with its own price - and, once the customer shows where they are, how far each one is and which is closest.
4. Choosing a point: which courier, how the parcel is collected, the price, the address and how far away it is - with one button to take it.
5. Cart: what each courier charges, before the customer reaches the checkout.
6. Order screen: the shipment panel (waybill, print, track, cancel) and the delivery editor - courier, delivery option, town and office, changed in place.
7. Orders list: the Waybill column - generate a label, or print, track and cancel an existing one, per order.
8. Orders list: the shipment's current state, and when it was last checked, on hover.

== Changelog ==

= 0.4.13 =
* Changed: the plugin page's tags and description carry the names people search for - Express One and BOX NOW as they are written, and Спиди, Еконт and Европът in Bulgarian, with a Bulgarian summary. Every courier's terms and privacy policy is a link, and the page has real headings. Readme only, no code change.

= 0.4.12 =
* Fixed: **the BOX NOW webhook accepts the header BOX NOW actually sends, and a refused message says why.** When you register the webhook URL with BOX NOW, give them the header name X-BGC-Webhook-Secret with your secret as its value (the settings show both); a message BOX NOW signs is still accepted, as hex or Base64. A refusal now names the reason, and with debug logging on says what arrived.
* Fixed: a BOX NOW webhook message is read by its event, as BOX NOW's guide says, so a parcel waiting in the locker, in the depot or expired and on its way back lands on its own stage instead of "in transit".

= 0.4.11 =
* Fixed: the Европът privacy-policy link now shows as a link rather than a long encoded web address.

= 0.4.10 =
* Fixed: **the estimated delivery price shown before a town is picked no longer reads about 20% low** for a courier that charges its own VAT (Speedy, Express One, Sameday) on a shop WooCommerce adds no shipping tax to - the estimate now carries the courier's tax and matches the price once a town is chosen.
* Added: **the map, and the checkout's automat dropdown, show which Sameday easyBox lockers are full and grey them out, kept live.** A full locker cannot be chosen; "full" follows your parcel's size against the box's small, medium and large compartments. Only Sameday reports free compartments, and staffed points are never marked full.
* Fixed: the address map now shows a delivery as free once the order has earned free shipping, matching the checkout row instead of the ordinary per-office price.
* Fixed: the checkout block's address hide and required phone now hold on the Store API even when another plugin reads the country locale early, so an order is no longer refused for a street or town the block never showed.

= 0.4.9 =
* Fixed: a delivery street that shares its name with another in the same town (Sofia's бул. ВИТОША and ул. ВИТОША) is no longer refused by the courier - the order now carries the exact street. Covers Speedy, Express One, Европът and Pigeon, and streets chosen on the address map.
* Fixed: a Pigeon Express street can now be found in a big town (only the first page of its street list was read before).
* Checkout block: on WooCommerce's block-based checkout the courier pickers now come to life and re-price after a tab click, the address is asked once instead of twice, the phone is required in place, cash on delivery re-prices the rate, a house number typed just before Place Order is kept, and a fresh cart no longer shows refusals before anything is done.
* Added: the waybill on the order screen and the orders list now shows when it is out of date (the order changed after it was issued) and when it still needs printing.
* Fixed: the "track this parcel" links for BOX NOW and Sameday now open the courier's tracking page.
* Fixed: an Econt address refusal is shown in the courier's own words.
* Faster: a town's street list is fetched once a day instead of on every keystroke; town search now lists a name that starts with what you typed before one that merely contains it.
* Fixed: switching the plugin off clears its scheduled tasks; the "Checkout dropdown results" setting honours its default of 20.

Earlier versions, and the full account of every entry above - what each fix was and how it was found - are in docs/CHANGELOG.md in the plugin's repository.

== Upgrade Notice ==

= 0.4.12 =
A shop whose BOX NOW webhook was registered had every message refused, so parcel stages never arrived. Ask BOX NOW to send the header the settings now show, with your secret as its value.

= 0.4.10 =
Fixes a delivery estimate that read low before a town was chosen for couriers that charge their own VAT (Speedy, Express One, Sameday) on shops with no shipping tax - the price shown could sit under the amount charged once a town was picked.

= 0.4.6 =
Fixes a BOX NOW checkout that could not be completed: the order was refused with "choose a locker" over a locker already picked. Also a delivery box that sat beside the courier's name on some themes, and a set of phone fixes.

= 0.4.1 =
A courier you had switched off was still being offered at the checkout. Automatic labels can now be set per courier, and start off for couriers that come for the parcel as soon as a waybill exists (Sameday).

= 0.4.0 =
Adds Express One as a sixth courier. Fixes a real overcharge: a product with no weight was quoted at 100 g and posted at a kilo, on every courier. "Re-issue waybill" no longer fails when the courier has already voided the old one.

= 0.3.8 =
The checkout can now require an e-mail address (off by default). A Pigeon Express parcel sent back by an uncollected delivery moves the order again.

= 0.3.7 =
Adds "Request a courier" for Speedy and Econt. BOX NOW is no longer told the value of a prepaid parcel unless you enable it.

= 0.3.6 =
Cash on delivery was never priced, so COD orders were undercharged. Deleting the plugin now removes its data.

= 0.3.5 =
Delivery was quoted about 20% high before a town was chosen, and the map could disagree with the checkout.
