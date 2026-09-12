=== BG Couriers for WooCommerce ===
Contributors: winter2007d
Donate link: https://revolut.me/danq6lus
Tags: speedy, econt, boxnow, sameday, bulgaria
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.7
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
* **BOX NOW** - lockers (APM) only, picked on BOX NOW's own map. Flat rate.
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

* **Speedy** - api.speedy.bg. Terms: https://www.speedy.bg/en/terms-and-conditions · Privacy: https://www.speedy.bg/en/privacy-policy
* **Econt** - ee.econt.com. Terms: https://www.econt.com/en/terms · Privacy: https://www.econt.com/en/privacy-policy
* **Pigeon Express** - api.pigeonexpress.com (api-demo.pigeonexpress.com in test mode). Terms: https://pigeonexpress.com/terms · Privacy: https://pigeonexpress.com/privacy
* **Sameday** - api.sameday.bg (sameday-api-bg.demo.zitec.com in test mode). Terms: https://sameday.bg/terms-and-conditions-delivery-courier-services-bg/ · Privacy: https://sameday.bg/politika-za-poveritelnost/
* **Express One** - system.expressone.bg. Terms: https://expressone.bg/bg/terms · Privacy: https://expressone.bg/bg/privacy-policy
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
4. Add the courier's shipping method to your **Bulgaria** shipping zone (WooCommerce → Settings → Shipping), and to the zone of any other country you deliver to.

== Frequently Asked Questions ==

= Which countries are supported? =
Bulgaria, by six Bulgarian networks: **Speedy**, **Econt**, **Pigeon Express**, **Sameday** and **Express One** deliver to an office, to a street address or to a locker, and **BOX NOW** delivers to its lockers (APM). Delivery outside Bulgaria is not offered.

= How can I support the development? =
The plugin is free, GPL, and stays that way - every courier, every feature, no paid tier. If it has saved you work and you would like to put something behind it: https://revolut.me/danq6lus. It is entirely voluntary and changes nothing about the support you get. Reporting a bug in the [support forum](https://wordpress.org/support/plugin/bg-couriers/), or leaving a review, helps just as much.

= When is the waybill created, and when should it be? =
Either when you choose, or by itself. **Auto-generate labels** (BG Couriers -> General) issues the waybill the moment an order reaches the status you pick; each courier's own tab can overrule that for itself. With it off, an order shows a **Generate** button instead, and the bulk action **Print waybills A4/A6** creates any that are missing and hands you one PDF - so you print at the packing table and the waybill is made at that moment.

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
* Fixed: **one courier with a faulty connector could stop tracking updates for every courier.** Tracking is checked in batches of forty orders, oldest first, and a connector that broke on one order's answer ended the whole batch - every order behind it, whichever courier it was with, went unchecked. Since that order was never marked finished it was at the front of every batch, so tracking for the whole shop simply went quiet. The same fault could end the weekly courier sync for the couriers behind it, and on the checkout it could show an error page instead of the fallback price. A faulty connector is now noted in the log and skipped, and the rest carry on.
* Fixed: **a courier that failed to answer once could grey out both delivery options for a town for six hours.** The checkout asks two things about a chosen town - which offices it has, for the dropdown, and which kinds of delivery it offers, for the tabs - and asked the courier twice for the same list, keeping two separate copies. Only the first had been taught not to remember a failed answer. The tabs are now read off the very same list as the dropdown, so a town is asked about once, the two can never disagree, and a courier that is down for a moment is simply asked again next time.
* Fixed: **a delivery price could be shown in a currency the shop no longer uses.** Prices are remembered for a few hours so the checkout stays quick, and what was remembered was the number without the currency it was quoted in - so a shop changing from lev to euro went on asking for the lev figure in euros, nearly twice the real cost, until each remembered price ran out. The daily reference price behind the courier list had the same gap, and it lasted until the next sync. Every remembered price now carries its currency, and one quoted in a currency the shop has stopped using is not used at all - the price the merchant configured is shown instead.
* Fixed: **the delivery price recorded on an order belonged to whichever courier was priced last, not the one the customer chose.** WooCommerce prices every courier in the zone on every recalculation and they all wrote to one place. It is kept per courier now, so the order carries what its own courier quoted and whether that was a live price or a fallback.
* Fixed: **a busy moment could tell a customer their town is not served.** The shop limits how many lookups one address may make a minute, and when it hit that limit it answered with an empty result - which reads exactly like "this courier has no office here". The checkout believed it, greyed out both delivery options and remembered that for the rest of the visit. The map did worse: an empty answer is a town with nothing in it, and the map now drops those, so a busy moment could throw away the place the customer had chosen. A refusal says it is a refusal now, and nothing remembers it.
* Fixed: **the lookup allowance is given back every minute, not after a minute of silence.** Two customers on one office or mobile network kept each other's allowance from ever resetting.
* Fixed: **one courier with a sick API no longer makes every customer wait.** Prices are asked for one courier after another while the checkout loads, and a courier that stops answering was costing each shopper up to forty seconds of staring at a spinner - for a price the shop had already configured a fallback for. A courier that takes its time failing is now left alone for five minutes and the fallback price is shown at once - for the whole shop, not only for the customer who found it: a courier's API being ill is a fact about the courier, and asking it again for every visitor would make each of them wait to learn the same thing. The first live price that does come back ends the rest early. A courier that refuses instantly, or that answers, is unaffected.
* Faster: **the weekly courier sync is seconds instead of minutes.** Every town and every office was saved to the database one at a time - 6,627 separate writes for Speedy alone, which took 18 seconds before any of the other couriers had started. They go in batches now: 36 writes, under a second, the same towns and the same offices.
* Fixed: **an order is written once when its tracking is checked, not up to five times.** Each thing the courier told us was saved separately, and every save wakes every other plugin on the shop that watches orders. One answer from the courier is one save now.
* Fixed: **a pickup point could stand alone on the map next to a bubble counting hundreds of others.** The pins are folded together on a grid, and a grid has edges: a point a few pixels from a thousand others could fall the other side of one and never join them. It joins the crowd it is standing in now.
* Fixed: **choosing a town could tick an office nobody picked.** A town with a single counter needs no list, and that is still true - but a town with one counter and two lockers was having the counter chosen for the customer, because it was the only counter. It is left to the customer whenever the town has more than one pickup point to choose from.

= 0.4.7 =
* Fixed: **the map could open on a town the customer had never chosen**, with no pickup points in it and the whole region on screen. The map remembers the last town this browser looked at, and that memory had no expiry: it outlived a town the couriers stopped listing, and every town of a country a shop had stopped delivering to. A remembered town that comes back with nothing in it is now dropped and the map asks its one question again. A town the customer has just named, or the one their courier box is already set to, is untouched.
* Fixed: **the About tab showed the General tab's settings.** Everything that was not a courier fell through to one branch that printed the General fields whatever the tab was, so About printed 38 settings rows instead of what it is there for. Every tab prints the fields built for it now.
* Fixed: **the Econt tab wrote the shop's API username into the page source.** Every courier's credential boxes are declared empty - the values are stored encrypted, and a box left blank means "keep what is saved" - and Econt's username was the one that was not declared that way, so WooCommerce printed the stored username into the HTML of the page on every load. The box on the screen looked like the others (the lock empties it), the source did not. It is declared empty now like the rest, and nothing was lost: the stored username is untouched.
* Fixed: **the guard that keeps the browser's password manager out of the credential boxes was not on the boxes.** It was put on the fields the settings are SAVED from, not on the fields that are drawn, and the courier tabs draw their own - so not one credential box on a courier tab ever carried it. A password manager filling a blank "API username" with the merchant's own e-mail, and a Save then writing that over the real credentials, is how a live courier account was lost once. Everything the plugin draws carries the guard now.
* Fixed: **the checkout accepted a city or an office the courier does not list.** Both arrive from the page as plain numbers, and the order was refused only if they were missing altogether - so a tab left open while the courier's towns were resynced could place an order whose shipping address came out empty, and whose waybill the courier refused hours later. They are now checked against the courier's own synced list, and the refusal points at the box to fix, like every other one. A shop whose list has not been synced yet is never refused on the strength of it.
* Fixed: **the BOX NOW webhook secret was printed into the settings page.** It is the key the incoming tracking messages are checked with, and it was the one credential on these screens drawn with its value in it. It is empty now like the rest, and saving the page with the box left blank keeps what is stored - nothing to re-enter.
* Changed: **a label is only fetched from a public address.** Two couriers answer with a link that this server then downloads. A link pointing back inside the shop's own network is refused before the request is made.
* Changed: **nothing that is a credential can reach the debug log.** It used to strip four field names, which were the spellings two of the seven couriers use, and only at the top level of an entry. Every courier's own names are covered now, at any depth. Nothing was writing one there today; this closes the door before something does.

= 0.4.6 =
* Fixed: **a BOX NOW order was refused with "Please choose a BOX NOW locker" over a locker that was on the screen.** Since 0.2.21 the checkout saves the chosen courier's delivery details once more the moment the order is sent, so a street typed a second earlier is not lost - and for BOX NOW that save read the locker through the town and office boxes BOX NOW's block does not have, and saved "no locker" over the one just picked. Every BOX NOW order placed since then had been refused this way. The locker is now saved from where the widget put it.
* Fixed: **on some themes the chosen courier's delivery fields sat BESIDE its name instead of under it** - the name and price floating in the left half of the card, the delivery tabs and the town and office boxes squeezed into the right half, the price and the "Town" label run together. Themes commonly lay each courier row out as a flex row so the radio and the label share a line, and the plugin's fields only asked for the full width and hoped the row was a block. The row is now the plugin's own column, on every theme. The price also stays on the courier's line where a theme (Botiga) pins it in the card's top corner.
* Fixed: **on a phone, the hint beside a courier's price was cut off by the left edge of the screen.** It is the same bubble the admin screens use now - one that measures the screen and stays inside it - and a tap opens it and keeps it open until the next tap somewhere else. Tapping it no longer changes the chosen courier, either.
* Fixed: **on a phone, the address picker's "Use this address" button was off the right edge of the screen**, and on a short screen below the bottom edge. Its footer stacks now, and the map gives way before the buttons do.
* Changed: **on a narrow screen the chosen office is shown in full**, on two lines, rather than cut to "СОФИЯ - СОМАТ - гр. СОФ...".
* Changed: **on a narrow screen the map pin sits beside the street, and the house number under it** - it used to sit beside the number, a hand's width of nothing between the two. The address rows also keep the same spacing as the fields above them.
* Changed: **three more things are big enough for a thumb**: the map opener above the courier list, BOX NOW's "choose a locker" button, and the pin beside the office list.
* Changed: **the map folds pins that stand on top of each other into a count.** A city like Sofia put a thousand identical dots on the screen; they are now bubbles saying how many pickup points are there, in the courier's colour (a pie of colours where several couriers share one), and a tap on a bubble zooms in on what it holds. From street level in, every point stands alone again.
* Changed: **"Please choose an office" now points at the field.** The refusal at the top of the page is a link to the box it means, and the box is outlined in red until it is filled in - the same red WooCommerce puts on its own required fields. Until now the sentence was at the top and the empty box a screen further down, with nothing to say which one.
* Changed: **the address picker opens on the town the customer has already named**, not on the whole country, while the browser is still asking whether it may use their position - or after they said no.

= 0.4.5 =
* Fixed: **on a phone the courier menu was cut off at the edge of the screen, and the street field was a few characters wide.** Both had one cause. The delivery fields sit in a cell of WooCommerce's order table, and such a cell is never narrower than the widest thing in it that cannot shrink - the town and office boxes draw the chosen value on a single unbroken line, so the cell insisted on 350px, more than a 360px phone has to give once the theme keeps a label column beside it. The table then grew wider than the screen. With "to address" open the office row is hidden, the table fits again, and the same arithmetic spent what was left on the house-number field and left the street around 70px. The cell now asks for 143px, and on a narrow screen the room goes to the street name rather than the number.
* Fixed: **the checkout could be scrolled sideways on a phone.** The office list's hidden `<select>` - the one the search box replaces - was being stretched back to the width of its longest office name by one of the plugin's own rules. It is out of flow, so nothing looked out of place; it simply reached past the right edge and gave the whole page a horizontal scrollbar (a 320px screen scrolled to 419px).
* Changed: **the delivery fields now lay themselves out for the space they are given, not for the size of the screen.** How much room they get is the theme's decision - the same phone leaves them 171px on one shop and 280px on another - so in a narrow column the street name takes a line of its own, block/entrance/floor/apartment go two and two, and the Map button beside the office list becomes the same pin the address row uses. Wide columns are unchanged.
* Changed: **the courier box takes the whole row on a phone.** It shared it with the theme's label column, which kept about 130px of a 320px screen for a heading this row does not have.
* Changed: **everything you tap is big enough to tap.** Fields, delivery tabs and the map buttons are at least 44px on a touch screen, the (i) that says who the delivery is paid to is no longer a 15px dot, and every field is set at 16px - below that, iOS zooms the page in the moment a field is focused and the customer has to pinch it back.
* Fixed: **the street box answered in English.** "Please enter 2 or more characters" and the rest of the search box's own messages were never translated, because they come from the dropdown library rather than from the plugin. They go through the plugin's own translations now.

= 0.4.4 =
* Changed: **a courier can be switched on before it is set up.** Enabling one used to be refused until its credentials were saved and validated - which was impossible for two of them, whose "send parcels from" address can only be picked off a list their own API returns once the credentials work. The switch is yours now; underneath it the tab lists what is still missing, and the checkout is what withholds the courier until the list is empty.
* Fixed: **a courier with missing credentials, or credentials marked as needing re-validation, is no longer offered at the checkout.** The ✕ beside a credential field marks it for re-entry and deliberately keeps the stored value, so the courier went on quoting and pricing with credentials the shop had just called into question - and could not print a label for any of it.
* Fixed: **a courier with every delivery option switched off was still offered**, priced as a delivery to an office nobody had left enabled.
* New: **the tab says when a courier is not in any shipping zone.** It is the usual reason a courier that is on, validated and fully configured never appears at the checkout, and nothing anywhere used to mention it.

= 0.4.3 =
* Fixed: **switching a courier on saved it off.** The toggle on a courier's tab disabled its own checkbox while it saved, and a disabled field is not part of the form that gets sent - so the setting arrived empty and was stored as "off". This affected every courier and had been there since the toggle was added: one you had just enabled came back disabled, and one you were setting up for the first time could never be switched on at all.
* Fixed: **a settings save that failed said nothing.** It looked exactly like one that worked - the switch turned green and nothing was written. A failure is now shown, with the error the server gave, and a courier is no longer enabled on the strength of a save that did not happen.

= 0.4.2 =
* New: **Европът, a seventh courier** - to its offices and to an address, with live prices for every destination, labels, tracking and cancellation. Which end the parcel leaves from is a setting rather than an assumption: Европът prices the whole journey, and the same parcel costs 4.59 counter-to-counter against 6.52 door-to-door, so a shop that hands its parcels over at an office would otherwise be quoted for a collection it never asks for.
* Fixed: **the price shown for a delivery paid at the door was short by the courier's VAT.** That row followed the shop's own "show prices with tax" setting - but the courier charges its VAT whatever a shop displays, so the customer was told 2.20 and handed the courier 2.64. Measured on a live shop for every courier; each was short by exactly its own VAT.
* Fixed: **four couriers quote a price that already includes VAT, and the shop was adding it a second time.** Pigeon, Econt, Express One and Европът all return a total that looks exactly like a net one, and only their printed waybills say otherwise. A shop charging the delivery with the order billed 3.11 for a delivery Pigeon collects 2.59 for.
* Fixed: **a shop that does not calculate tax was charging its customers less for delivery than the courier invoices it.** The delivery price is handed to WooCommerce for the shipping tax to be added on top, and a shop with tax calculation switched off adds none - while the courier invoices its VAT all the same. Sameday quoted 1.37 for a locker parcel and invoiced 1.66 for it.
* Changed: the fixed and fallback delivery prices you type in, and BOX NOW's flat rate, now say that they are without VAT - they are used as the shipping rate's cost, which is what that means.

= 0.4.1 =
* Fixed: **a courier you had switched off was still offered at the checkout.** Everything else respected the switch - the cart estimate, the map, the office lookups - but the shipping method itself never asked, so a courier left in a shipping zone kept pricing and kept being shown while its own settings tab said it was off.
* New: **automatic labels can now be decided per courier**, not once for the whole shop. Each courier's tab has its own answer: follow the general setting, on, or off.
* Changed: **for a courier that comes for the parcel as soon as a waybill exists, automatic labels now start off.** Most couriers are asked to collect in a separate request, so issuing the waybill early costs nothing; Sameday has no such request - creating the shipment is what puts it in that day's collection list, and the courier arrives within a couple of hours. A waybill issued the moment an order is paid therefore sends a van to a parcel nobody has packed. Shops that were already running keep whatever they had.

= 0.4.0 =
* New: **Express One, a sixth courier** - to its offices, to an address, and to its EXOBOX lockers, with live prices for each destination, labels, tracking, cancellation and a courier request. Its street list is its own: Express One refuses an address it was not given a street id for, so the checkout offers only streets it knows rather than letting one be typed and refused hours later at the packing table.
* New: **Express One carries no cash on delivery to a locker** (the courier's own rule), so the checkout stops offering наложен платеж the moment a locker is chosen, says why, and prices the delivery without a collection fee it will not charge. A waybill that would collect nothing is refused before it is printed.
* Fixed: **a parcel nobody weighed was quoted at 100 g and posted at a kilo** - every courier. The checkout priced a shipment lighter than the one the label went out with, and the difference came out of the shop on every order for a product with no weight on it.
* Fixed: **"Re-issue waybill" could be stopped by the waybill it was replacing.** If the courier had already voided the shipment itself - a collection it refused, a parcel it never took - clearing the dead number was reported as a failure, and the shop was left unable to issue a new label for a parcel nobody was coming for.
* Fixed: the phone number is now required on the block checkout too. It was required on the classic one; on the block checkout WooCommerce's own setting decided, and a shop that had it optional sent orders to the courier with no number to call.
* Changed: **a new shop no longer charges the delivery inside the order total.** Collecting a fee and paying it straight out again puts turnover through the books that the shop never keeps, so the customer now pays the courier at the door and the price is shown for information. A shop that was already running keeps exactly what it had.

= 0.3.8 =
* New: **the checkout can insist on an e-mail address** - a setting, off by default. The plugin makes the field optional (a waybill is built with the phone number), and until now a shop that needed the address had to edit its theme.
* Fixed: **a Pigeon Express parcel that came back never moved the order.** The journey home travels under a second waybill the order was never told about.
* Fixed: "Request a courier" now names every ticked order it leaves out, instead of quietly shrinking the list you are about to confirm.
* Fixed: an address the shop cannot deliver to now says so, and offers the way back - it used to end in WooCommerce's "check your address" about an address with nothing wrong with it.
* Changed: the map's town field opens its whole list when you press it; it used to answer only to typing.

= 0.3.7 =
* New: **Request a courier** - tick the orders, pick a day, the courier comes for those parcels (Speedy, Econt).
* New: Pigeon Express can collect from your address instead of you dropping parcels at its office.
* Fixed: cancelling an already-cancelled Sameday shipment reported a failure and left the waybill on the order.
* Changed: BOX NOW is no longer told what a prepaid parcel is worth, unless you turn it on.

= 0.3.6 =
* Fixed: **cash on delivery was never priced.** The courier's fee for collecting the money was missing from every quote - on 50 EUR: Econt +0.78, Pigeon +0.75, Sameday +0.50, Speedy +0.40.
* Fixed: deleting the plugin now removes its settings, tables and label PDFs.
* Fixed: a Speedy cancel could report failure when the courier had cancelled.
* New: Econt partial delivery (частична доставка), off by default.

= 0.3.5 =
* Fixed: delivery was quoted about 20% high until a town was chosen - the pre-town price carried its VAT and was then taxed again.
* Fixed: the interactive map and the checkout could show different prices for the same office.

Older entries: https://github.com/dangoriaynov/bg-couriers/blob/main/docs/CHANGELOG.md

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
