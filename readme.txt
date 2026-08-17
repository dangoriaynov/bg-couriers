=== BG Couriers for WooCommerce ===
Contributors: winter2007d
Donate link: https://revolut.me/danq6lus
Tags: speedy, econt, boxnow, sameday, bulgaria
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.3.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shipping for Bulgarian stores: Speedy, Econt, BOX NOW, Pigeon Express, Sameday - office/address/locker delivery, live rates, labels, tracking.

== Description ==

BG Couriers puts Bulgaria's couriers inside WooCommerce: your customer chooses where the parcel goes and sees what that delivery costs, and you print the label and follow the parcel without leaving WordPress.

**At the checkout** every courier you switch on shows its own price for the basket, live from that courier's API. Your customer picks how the parcel is delivered - to an office, to an address, or to a locker/APS - and finds the office by typing the town, or by pointing at it on one map that carries every courier's offices and lockers at once, each with its own price. The map can also say which pickup point is closest to them.

**In the admin** one click issues the waybill and the label - one order, or fifty of them into a single PDF (A6 labels or an A4 sheet). The plugin then keeps each shipment's status up to date by itself, and your customer sees the waybill and a tracking link on their order and in the e-mails you already send them.

**It is free**, GPL, and stays that way: every courier, every feature, no paid tier. Deliveries are Bulgaria-only.

**Couriers**

* **Speedy** - office / address / APS. Live rates, labels, tracking.
* **Econt** - office / address / Econtomat. Live rates, labels, tracking, and **cash on delivery (наложен платеж)** with an itemised packing list.
* **Pigeon Express** - office / address / locker. Live rates, labels, tracking.
* **Sameday** - office / address / easyBox. Live rates, labels, tracking.
* **BOX NOW** - lockers (APM) only, picked on BOX NOW's own map. Flat rate.

**Setting it up**

You need your own account with each courier you want to offer: the prices your customers see and the labels you print are your own contract's, and nothing is resold through this plugin. Once you have the API credentials, a courier takes a couple of minutes:

1. Paste the credentials on that courier's tab and switch the courier on - it checks them with the courier there and then, and says so plainly if they are refused.
2. Sync its towns and offices - one button.
3. Add it to your **Bulgaria** shipping zone.

Everything else already has a working default: the prices, the map, the checkout fields and the label size all work as they ship.

**Also included**

* **Cash on delivery (наложен платеж)**, with the choice of who pays the delivery - and the amount to collect follows that choice.
* Free-shipping thresholds, per courier and per delivery type.
* Several parcels in one shipment, and insurance for a value you set (Speedy, Sameday).
* A delivery estimate on the cart page, before the customer reaches the checkout.
* Works on both the classic and the block checkout.
* Fully translated to Bulgarian.

== External services ==

The plugin uses the online API of each courier **you enable**, to price a delivery, to create a label and to track a parcel. Nothing is sent to a courier you have not configured.

**What is sent, and when**

* **Price quote** (cart / checkout) - the parcel weight, the destination town or office, and the delivery type. When the customer views the shipping options.
* **Label** (admin) - the recipient's name, phone, e-mail, the chosen address or office/locker, the parcel weight, and, with cash on delivery, the amount to collect and the item list. When you generate the label.
* **Tracking** - the waybill number. When tracking is opened or refreshed.

**Courier APIs**

* **Speedy** - api.speedy.bg. Terms: https://www.speedy.bg/en/terms-and-conditions · Privacy: https://www.speedy.bg/en/privacy-policy
* **Econt** - ee.econt.com. Terms: https://www.econt.com/en/terms · Privacy: https://www.econt.com/en/privacy-policy
* **Pigeon Express** - api.pigeonexpress.com (api-demo.pigeonexpress.com in test mode). Terms: https://pigeonexpress.com/terms · Privacy: https://pigeonexpress.com/privacy
* **Sameday** - api.sameday.bg (sameday-api-bg.demo.zitec.com in test mode). Terms: https://sameday.bg/terms-and-conditions-delivery-courier-services-bg/ · Privacy: https://sameday.bg/politika-za-poveritelnost/
* **BOX NOW** - api-production.boxnow.bg (api-stage.boxnow.bg in test mode), plus its locker-picker widget map.boxnow.bg, loaded in an iframe when the customer opens that picker. Terms: https://boxnow.bg/terms-of-use-for-shipping-services · Privacy: https://boxnow.bg/personal-data-processing-notice

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
4. Add the courier's shipping method to your **Bulgaria** shipping zone (WooCommerce → Settings → Shipping).

== Frequently Asked Questions ==

= Which countries are supported? =
Bulgaria only, and every courier here is a Bulgarian network: **Speedy**, **Econt**, **Pigeon Express** and **Sameday** deliver to an office, to a street address or to a locker, and **BOX NOW** delivers to its lockers (APM). There is no international shipping and no other country's couriers - the plugin talks to these five and nothing else.

= How can I support the development? =
The plugin is free, GPL, and stays that way - every courier, every feature, no paid tier. If it has saved you work and you would like to put something behind it: https://revolut.me/danq6lus. It is entirely voluntary and changes nothing about the support you get. Reporting a bug in the [support forum](https://wordpress.org/support/plugin/bg-couriers/), or leaving a review, helps just as much.

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

= 0.3.5 =
* Fixed: **the delivery was quoted about 20% too high until the customer chose a town, and dropped as soon as they did.** The price shown before a destination is known was the courier's figure WITH its VAT, and it was then handed to WooCommerce as a net cost - which taxes it again. Every price the plugin produces is now net, from the same place.
* Fixed: **the interactive map and the checkout could show different prices for the same office.** They worked out the parcel from two different places - the map from the basket's own total, the checkout from what WooCommerce actually ships - and those disagree over anything that is not shipped, such as the container of a product bundle. Both now price the same parcel, and the map prints it the way the row beside it prints it.

= 0.3.4 =
* Fixed: **a shop that prices in grams was quoted for parcels a thousand times too heavy.** WooCommerce hands the shipping method the weight in the shop's own unit; the couriers quote in kilograms, and the number was being passed across unconverted. A 40 g basket was priced as a 40 kg parcel: one courier charged 39,44 € for a locker delivery, another refused the parcel outright - and its configured fallback price then stood in for a delivery it would never have carried. The label was always built with the right weight, so the parcel that went out never matched the price the customer had paid. A shop priced in kilograms was never affected.
* Fixed: BOX NOW vanished from the checkout of a gram-priced shop for any basket over 20 grams. Its parcel limit was comparing kilograms against grams.
* Fixed: the optional Google Maps API key was written into the page source of every checkout, where anyone could have used it. Nothing in the browser ever needed it - it is used once, on the server, to turn a point picked on the map into an address. The setting now says so, instead of asking for a Maps JavaScript API this plugin does not use.

= 0.3.3 =
* Fixed: an Econt shipment now carries **your order number**, so a parcel in Econt's system can be matched back to the order it came from. Econt was the one courier never told it - and a shipment that is not told shows the waybill number in that field instead, which is the number you already have and cannot look an order up with. Speedy, Pigeon Express and BOX NOW have always carried it.
* Fixed: Sameday's shipment reference was built from WooCommerce's internal order id rather than the order number you are shown. The two are the same on most shops and differ on any shop that numbers its own orders.

= 0.3.2 =
* New: an order can be **more than one parcel**, and can be **insured** for a value you set - both on the order's delivery panel. Supported for Speedy and Sameday; the boxes are hidden for the couriers that ignore them, so a number you type is never quietly dropped.
* New: Speedy can send a **prepaid return waybill** with every shipment, so a customer can send the parcel back without arranging anything. Off by default - Speedy charges for it.
* New: Speedy's **declared value** ("обявена стойност"), off by default, or set to follow the cash-on-delivery amount. Off is deliberate: Speedy charges a premium and pays a claim only against documents most shops cannot produce.
* Faster: the interactive map now opens straight away on cached prices and refines each courier's real price for the chosen town a moment later, instead of making you wait for every courier before anything is drawn. The prices it shows are the ones the checkout will charge.
* Fixed: the settings screen warned about unsaved changes on a courier tab nobody had typed into.
* Fixed: two Speedy settings were drawn outside the settings table, which cost them their (i) hints, their layout and their toggle.

= 0.3.1 =
* **Your customers can now track their own parcel.** The waybill number and a link to the courier's tracking appear on the customer's order page - both after checkout and later in My Account - and in the order emails they receive. Until now only the shop owner could see any of it, so "where is my parcel?" could only be answered by writing to the shop.
* Nothing is shown before a label exists, and the shop owner's own copies of the emails are left alone.

= 0.3.0 =
* **The plugin now works on WooCommerce's block checkout.** It did not, and it failed quietly: the courier and its price appeared, there was nowhere at all to choose a town, an office or a locker, and the order could still be placed - reaching you with a courier and no destination. The block checkout is what WooCommerce gives a new shop by default.
* The same pickers, the same interactive map and the same rules now render inside the block checkout, and an order without a delivery point is refused there with the reason.
* Fixed: a shop that puts the checkout block on a page other than its configured checkout got courier prices with no pickers at all. The pickers now follow the block, not the page setting.

= 0.2.26 =
* The plugin's own settings screen now says where to ask a question, with a link to the support forum - and, once, quietly, that you can support the development if you want to. It is one line, it is not dismissible because there is nothing to dismiss, and it appears nowhere else in the admin.

= 0.2.25 =
* Fixed: setting a courier up could not be completed at all on a fresh install. A courier would not turn on until its API credentials had been validated, and validating them was refused until the courier was on - and the refusal said "no credentials saved" about credentials that were saved. Reported by a merchant who installed the plugin and could not get past the settings screen; with apologies.
* Entering the credentials and switching the courier on is now the whole job: turning it on checks them with the courier for you, and says so plainly if they are refused.
* "Validate credentials" and "Sync now" also work before a courier is switched on, which is when you actually want them.

= 0.2.24 =
* Fixed: on a phone, pressing the closest office could leave the map showing a different part of town with the office's bubble at the edge. Changing the zoom animates, and the bubble was being fitted to the screen while that animation was still running. Picking an office from the list went the same way.

= 0.2.23 =
* Fixed: "show my location" did nothing on the interactive map after you had changed the town.
* Fixed: switching a courier off in the map's legend could take a second and a half on a big town before anything moved. The legend now answers on the click, and the map catches up behind it.
* New: the "closest to you" line is a button - press it and the map goes to the office it means, opens it and marks it in the list. It used to name a courier and a distance without saying which of the points it was talking about.
* New: once you have shown where you are, each point's own bubble says how far it is from you - so the others can be weighed against the closest one.
* Fixed: the map remembered where you were between visits, so re-opening the checkout could show your location and the distance to your nearest office without asking - and kept doing it after you had reset the browser's permission. It now forgets your position when you leave the page. The town you chose is still remembered.
* Fixed: in a point's bubble the directions arrow sat below the line instead of on it, once a distance appeared beside the address.
* The "closest to you" answer now sits in the map's header, directly under the courier badges, instead of in a band of its own - it is more compact, the map is taller for it, and on a phone the whole strip is what you press.
* New setting: "Closest to you" on the map can be switched off in WooCommerce → Settings → Shipping → BG Couriers → General. It is on by default.

= 0.2.22 =
* New: the interactive map can tell you which office or locker is closest to you, and what collecting from it saves against delivery to your door. Press "show my location", or drag the pin to where you actually are; the list then sorts by distance, every point shows how far it is, and each courier's own closest point appears on its badge. Distances are worked out in your browser. Your position is only ever sent anywhere if you press "show my location" before naming a town - then it is looked up once, to fill the town in for you.

= 0.2.21 =
* Fixed: on the order screen the office map opened underneath the page - the order status and customer selects, and the delivery editor's own fields, were drawn straight through it.
* New screenshots and courier names in the plugin's tags, so it can be found by searching for Speedy, Econt, BOX NOW or Sameday.

= 0.2.20 =
* Fixed: placing the order immediately after typing the delivery address could be refused for leaving blank the very fields that were filled in on screen. The delivery details are saved separately from the order form, and the order could be sent before that save had arrived. The order now waits for it.

= 0.2.19 =
* Fixed: a courier forgot which delivery option you had chosen in it as soon as you looked at another one, and its price silently changed to a different delivery's. Choosing a Sameday locker showed 1,30 €, and touching Speedy turned that into 2,87 € - the price of delivery to an address, which had not been asked for. Every courier now remembers its own choice.

= 0.2.18 =
* The town on the interactive map now has a clear (×) button at the right-hand end of the field, and the courier blocks' own city field keeps its × in the same place instead of tucked against the town's name.
* "Find my location" now works before a town is chosen: it works out which town you are in and opens it.
* Choosing a town now shows ALL of its offices and lockers at once. It used to open two steps closer, leaving the outlying ones off the screen entirely.
* Scrolling inside the map dialog no longer scrolls the checkout behind it.

= 0.2.17 =
* The interactive map now opens on the office or locker you already chose, instead of on the middle of the town where it was impossible to find again. It still shows every courier - it just knows which point is yours.
* Fixed: a courier that cannot be used for this order (for example BOX NOW where the shop only takes cash on delivery) no longer shows its logo on the interactive-map button, since it is not in the list of couriers to choose from either.
* Fixed: the reason a delivery option is unavailable never appeared. The explanation was attached to a disabled button, and a disabled button receives no mouse events, so hovering "To locker" showed nothing - it looked as though the courier does not offer lockers at all rather than "not in this town".
* The courier and delivery type in a pin's popup now stay on one line, in one size.

= 0.2.16 =
* Fixed: the map opened zoomed in much too far and re-centred oddly. Introduced in 0.2.12, where the framing began running more than once per view and each pass zoomed two steps further in than the last.
* A pin's popup is now centred, and its address carries a small button that opens Google Maps directions from wherever you are to that office or locker - so you can see how far it is and start navigating.

= 0.2.15 =
* Fixed: the interactive map opened on the town you looked at last time instead of the one currently chosen for the courier, so setting the delivery to another town and opening the map still showed the old one.
* Your own position on the map is now a distinct pin rather than another coloured dot, which was impossible to pick out among the couriers' points.

= 0.2.14 =
* Fixed: on the interactive map every point of a courier was labelled with the price of whichever delivery type that courier was currently set to. With Speedy on "to office" its lockers showed the office price, and switching to a locker made its offices show the locker price. Each point is now priced for the way it is actually collected.
* Fixed: the town was lost when switching courier after choosing a point on the map, so the next courier opened with an empty city.
* A town that has only one office (or only one locker) now arrives with it already chosen, instead of asking you to open a dropdown holding a single row.
* A pin's popup reads in three lines: the courier and how the parcel is collected with the price, then the name of the office or locker, then its address.

= 0.2.13 =
* The map dialog now opens straight onto the map for a place you have looked at before - the points are fetched quietly in the background while you fill in the rest of the checkout, instead of after you click.
* A pin's popup now reads in three lines: the courier and how the parcel is collected (with the price) on the first, the name of the office or locker on the second, and its address on the third.

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

= 0.3.5 =
The delivery was quoted about 20% high until a town was chosen, because the pre-town price carried its VAT and was then taxed again. Also makes the interactive map and the checkout price the same parcel, so the price on the map is the price that is charged.

= 0.3.4 =
Important for any shop whose weight unit is not the kilogram: delivery was being priced for a parcel a thousand times too heavy, and BOX NOW disappeared from the checkout. Also stops writing an optional Google Maps key into the checkout's page source.

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
