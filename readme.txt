=== BG Couriers for WooCommerce ===
Contributors: winter2007d
Donate link: https://revolut.me/danq6lus
Tags: speedy, econt, boxnow, sameday, bulgaria
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bulgaria's couriers in WooCommerce: Speedy, Econt, BOX NOW, Pigeon, Sameday, Express One - office, address and locker delivery, live rates, labels.

== Description ==

BG Couriers puts Bulgaria's couriers inside WooCommerce: your customer chooses where the parcel goes and sees what that delivery costs, and you print the label and follow the parcel without leaving WordPress.

**At the checkout** every courier you switch on shows its own price for the basket, live from that courier's API. Your customer picks how the parcel is delivered - to an office, to an address, or to a locker/APS - and finds the office by typing the town, or by pointing at it on one map that carries every courier's offices and lockers at once, each with its own price. The map can also say which pickup point is closest to them.

**In the admin** one click issues the waybill and the label - one order, or fifty of them into a single PDF (A6 labels or an A4 sheet). The plugin then keeps each shipment's status up to date by itself, and your customer sees the waybill and a tracking link on their order and in the e-mails you already send them.

**It is free**, GPL, and stays that way: every courier, every feature, no paid tier. Deliveries are within Bulgaria.

**Couriers**

* **Speedy** - office / address / APS. Live rates, labels, tracking.
* **Econt** - office / address / Econtomat. Live rates, labels, tracking, and **cash on delivery (наложен платеж)** with an itemised packing list.
* **Pigeon Express** - office / address / locker. Live rates, labels, tracking.
* **Sameday** - office / address / easyBox. Live rates, labels, tracking.
* **BOX NOW** - lockers (APM) only, picked by town and locker like every other courier (the towns are read off its lockers). Flat rate.
* **Express One** - office / address / EXOBOX locker. Live rates, labels, tracking. The address is chosen from Express One's own street list, which is what its waybills require.

**Setting it up**

You need your own account with each courier you want to offer: the prices your customers see and the labels you print are your own contract's, and nothing is resold through this plugin. Once you have the API credentials, a courier takes a couple of minutes:

1. Paste the credentials on that courier's tab and switch the courier on - it checks them with the courier there and then, and says so plainly if they are refused.
2. Sync its towns and offices - one button.
3. Add it to your **Bulgaria** shipping zone - and to the zone of any other country you deliver to.

Everything else already has a working default: the prices, the map, the checkout fields and the label size all work as they ship.

**Also included**

* **Cash on delivery (наложен платеж)**, with the choice of who pays the delivery - and the amount to collect follows that choice.
* Free-shipping thresholds, per courier and per delivery type.
* Several parcels in one shipment, and insurance for a value you set (Speedy, Sameday).
* A delivery estimate on the cart page, before the customer reaches the checkout.
* Labels issued automatically when an order reaches a status you choose - or per courier, or not at all.
* Works on both the classic and the block checkout.
* Fully translated to Bulgarian.

== External services ==

The plugin uses the online API of each courier **you enable**, to price a delivery, to create a label and to track a parcel. Nothing is sent to a courier you have not configured.

**What is sent, and when**

* **Price quote** (cart / checkout) - the parcel weight, the destination town or office, and the delivery type. When the customer views the shipping options.
* **Label** (admin) - the recipient's name, phone, e-mail, the chosen address or office/locker, the parcel weight, and, with cash on delivery, the amount to collect and the item list. When you generate the label.
* **Tracking** - the waybill number. When tracking is opened or refreshed.

**Courier APIs**

* **Speedy** - api.speedy.bg. Terms: https://www.speedy.bg/en/terms-and-conditions · Privacy: https://www.speedy.bg/en/gdpr
* **Econt** - ee.econt.com. Terms: https://www.econt.com/en/terms · Privacy: https://www.econt.com/en/privacy-policy
* **Pigeon Express** - api.pigeonexpress.com (api-demo.pigeonexpress.com in test mode). Terms: https://pigeonexpress.com/terms · Privacy: https://pigeonexpress.com/privacy
* **Sameday** - api.sameday.bg (sameday-api-bg.demo.zitec.com in test mode). Terms: https://sameday.bg/terms-and-conditions-delivery-courier-services-bg/ · Privacy: https://sameday.bg/politika-za-poveritelnost/
* **Express One** - system.expressone.bg. Terms: https://expressone.bg/bg/terms · Privacy: https://expressone.bg/bg/privacy-policy
* **BOX NOW** - api-production.boxnow.bg (api-stage.boxnow.bg in test mode). Terms: https://boxnow.bg/terms-of-use-for-shipping-services · Privacy: https://boxnow.bg/personal-data-processing-notice

**Maps and address lookup**

* **OpenStreetMap tiles** - tile.openstreetmap.org. Map tiles only, loaded when the customer opens a map. Tile policy: https://operations.osmfoundation.org/policies/tiles/ · Privacy: https://wiki.osmfoundation.org/wiki/Privacy_Policy
* **OpenStreetMap Nominatim** - nominatim.openstreetmap.org. One set of coordinates, turned into an address or a town name. It happens in two cases only: the customer drops a pin on the **address map picker** (a setting, **off by default**), or presses "find me" on the map before naming a town, so the town can be filled in for them. Nominatim policy: https://operations.osmfoundation.org/policies/nominatim/ · Privacy: https://wiki.osmfoundation.org/wiki/Privacy_Policy
* **Google Maps Geocoding** - maps.googleapis.com. Takes over those same lookups, and only if you set a Google Maps API key in the settings (optional; OpenStreetMap is used if the key is empty or Google does not answer). It receives the coordinates and nothing else. Terms: https://cloud.google.com/maps-platform/terms · Privacy: https://policies.google.com/privacy

The map's "closest to you" (on by default, switchable off) works out the distances in the customer's own browser. Their position is never stored on the site and is forgotten when the page is closed.

No data is sent to any service you have not configured, and the plugin sends nothing to its author.

== Contributing ==

The plugin is developed in the open. Bugs, ideas and pull requests are welcome:
https://github.com/dangoriaynov/bg-couriers/issues

== Installation ==

1. Upload the plugin to `/wp-content/plugins/bg-couriers` (or install via the Plugins screen) and activate it. WooCommerce must be active.
2. Go to **WooCommerce → Settings → BG Couriers**.
3. Open a courier's tab, enter its API credentials, click **Validate**, then **Sync** its cities/offices.
4. Add the courier's shipping method to your **Bulgaria** shipping zone (WooCommerce → Settings → Shipping), and to the zone of any other country you deliver to.

== Frequently Asked Questions ==

= Which countries are supported? =
Bulgaria, by six Bulgarian networks: **Speedy**, **Econt**, **Pigeon Express**, **Sameday** and **Express One** deliver to an office, to a street address or to a locker, and **BOX NOW** delivers to its lockers (APM). Delivery outside Bulgaria is not offered.

= How can I support the development? =
The plugin is free, GPL, and stays that way - every courier, every feature, no paid tier. If it has saved you work and you would like to put something behind it: https://revolut.me/danq6lus. It is entirely voluntary and changes nothing about the support you get. Reporting a bug in the [support forum](https://wordpress.org/support/plugin/bg-couriers/), or leaving a review, helps just as much.

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

= 0.4.8 =
* Changed: **BOX NOW has the same checkout block as every other courier.** Its own map widget (a separate window, centred on Greece, asking the browser for a location, unable to take the town already chosen) is gone: BOX NOW's towns are read off its lockers, so the customer picks a town and a locker, the town carries over from the other couriers and the combined map, BOX NOW's lockers appear on that map, and the order editor uses the same town and locker fields.
* Changed: a plugin update refreshes the courier nomenclature once, a minute after it lands (so BOX NOW's towns are there at once, not at the next weekly sync).
* Fixed: a sync now retires every town's cached office list (it could go stale for six hours, sync or no sync).
* Fixed: **the X on the town now clears the office too, and the checkout knows about it.** WooCommerce's select box fires no "clear" event, so clearing a town left the office in its field, the price quoted for the old town, and the next save carried an office with no town - which then rendered as a single greyed-out office with no list. The clear is bound to the event the box does fire, an office without a town is never rendered, and a point chosen on the map saves the town and the delivery type together.
* Added: **the automatic waybill can wait for the day the order ships.** Orders carrying a dispatch day (Order Delivery Date's "ship on" date) had their waybill issued the moment they were paid - measured on a live shop: four shipments registered at Speedy three to four weeks before their parcels existed. A new General setting holds the waybill for the morning of that day; the order says so, a moved day moves it, and an order cancelled or completed in the meantime ships nothing. On for new installs; existing shops keep issuing at once until they tick it.
* Fixed: a BOX NOW refusal is said in words (its bare codes are documented: P411 is an account not allowed to collect cash on delivery, P410 an order number already used).
* Fixed: tracking is checked for every parcel in flight, not the oldest forty.
* Fixed: a refused cancel, and a refused credential check, now say what the courier said.
* Fixed: "Sync now" reported a refused login as a success.
* Fixed: "Request the courier" never sent one.
* Fixed: a notice on every request on sites with debugging on.
* Fixed: "Request a courier" offered tomorrow while the courier still came today.
* Fixed: a waybill the courier accepted but did not fully apply is now flagged on the order screen.
* Fixed: a BOX NOW parcel's progress never reached the orders list.
* Fixed: an order whose waybill was cancelled went on showing as "registered" in the orders list.
* Fixed: an order could end up with two shipments booked at the courier.
* Fixed: one courier with a faulty connector could stop tracking updates for every courier.
* Fixed: a courier that failed to answer once could grey out both delivery options for a town for six hours.
* Fixed: a delivery price could be shown in a currency the shop no longer uses.
* Fixed: the delivery price recorded on an order belonged to whichever courier was priced last, not the one the customer chose.
* Fixed: a busy moment could tell a customer their town is not served.
* Fixed: the lookup allowance is given back every minute, not after a minute of silence.
* Fixed: one courier with a sick API no longer makes every customer wait.
* Faster: the weekly courier sync is seconds instead of minutes.
* Fixed: an order is written once when its tracking is checked, not up to five times.
* Fixed: a pickup point could stand alone on the map next to a bubble counting hundreds of others.
* Fixed: choosing a town could tick an office nobody picked.

= 0.4.7 =
* Fixed: the map could open on a town the customer had never chosen
* Fixed: the About tab showed the General tab's settings.
* Fixed: the Econt tab wrote the shop's API username into the page source.
* Fixed: the guard that keeps the browser's password manager out of the credential boxes was not on the boxes.
* Fixed: the checkout accepted a city or an office the courier does not list.
* Fixed: the BOX NOW webhook secret was printed into the settings page.
* Changed: a label is only fetched from a public address.
* Changed: nothing that is a credential can reach the debug log.

Earlier versions, and the full account of every entry above - what each fix was and how it was found - are in docs/CHANGELOG.md in the plugin's repository.

== Upgrade Notice ==

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
