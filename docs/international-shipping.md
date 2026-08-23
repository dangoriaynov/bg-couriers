# Delivery to another country - built, measured, NOT finished

**Status: switched off in the plugin as it ships.** No shop is offered a delivery outside Bulgaria,
and no setting turns one on. The code is all here and the parts that exist were measured against a
live account - but the feature is not finished, and half a feature that books real waybills and takes
real money is worse than none. This file says what is done, what is not, and how to run it anyway.

## How it is switched off

One switch, `BGCouriers_Settings::intl_enabled()`, answers for the whole plugin:

```php
public static function intl_enabled(): bool {
    return (bool) apply_filters('bgcouriers_intl_enabled', false);
}
```

`BGCouriers_Settings::intl_countries()` returns an empty list while it is false. That one list is
where the rest of the plugin asks where a parcel may go, so "home only" there is what keeps a foreign
address out of everything downstream:

- `ships_to()` is false for any country but Bulgaria, and **every** shipping method asks it before it
  quotes - so a merchant who puts this plugin's method into a Romanian shipping zone still gets no
  rate. The checkout then prints "No courier can deliver to Romania at the moment" and a link back to
  a Bulgarian delivery, which is a plain refusal rather than an empty screen.
- The delivery box's Country dropdown is not rendered at all: it appears only when a courier has more
  than one country, and it now has one.
- The AJAX handlers refuse a foreign country, the nomenclature sync fetches only Bulgarian towns and
  offices, and the price cache is asked nothing about abroad.
- The **"Also deliver to"** setting is left out of the settings page entirely rather than rendered
  empty. WooCommerce writes every field a page declares, and an empty multiselect posts nothing - so
  showing it would wipe a merchant's saved countries on their next Save. Nothing is thrown away: the
  `bgcouriers_<courier>_intl_countries` option is untouched and comes back as it was.

Orders that already carry a foreign country are not affected - their waybills, labels and tracking go
on working. The switch decides what may be *offered*, not what has already been sold.

## How to switch it on

On the site under test:

```php
add_filter('bgcouriers_intl_enabled', '__return_true');
```

Then the "Also deliver to" picker appears on the Speedy tab, the merchant names the countries, the
next Sync fetches their towns and offices, and the country must also be in a WooCommerce shipping
zone that carries this plugin's method. Two tests are gated on the same filter and one e2e spec is
skipped by it - `e2e/tests/intl-allmap-ro.spec.js`, which is the only watch on the foreign path.

## What is built, and proven

Measured against Speedy's live account on 2026-08-19, and against the API - not from the docs:

- **Speedy, Romania, and nothing else.** `BGCouriers_Speedy::intl_countries()` returns `['RO']`
  because that is what has been proven. The other four couriers offer no country at all.
- **Service 202** (SPEEDY CEE ECONOMY) abroad against **505** at home. They are disjoint: 202 to a
  Bulgarian office and 505 to a Romanian one are both refused with
  `sla.serviceId.allowed_service_validator.service-not-allowed`. Country id 642 for RO, 100 for BG.
- **The payer is the SENDER abroad.** 202 refuses `RECIPIENT` outright.
- **The postal money transfer (ППП) does not cross the border.** Asked for one, 202 answers with no
  price for the whole shipment - so a shop that receipts its cash on delivery through the courier's
  ППП has cash on delivery removed abroad, along with the gateways that depend on it. With no prepaid
  gateway left, the checkout says so instead of showing an empty payment box.
- **No fallback price abroad, deliberately.** Every price the plugin holds that is not a live quote is
  a Bulgarian one, so a courier that cannot quote a foreign address is not offered at all rather than
  guessing a number the shop would then have to honour.
- **The destination country reaches every nomenclature lookup** - checkout, order editor, settings
  pickers, all four AJAX handlers - and is part of the office/town cache key. Without it a Romanian
  town answered with an empty office list, which reads exactly like a small town with no offices.
- **Return waybill and "open before payment" are never sent abroad**: both are forbidden additional
  services on 202.
- **One real BG->RO parcel** was checked out on dev, priced live, booked (waybill 63712538954),
  printed and voided again. That spec is tagged `@books-real-waybill` and stays out of the default run.

## What is missing - why it is off

1. **One courier, one country.** A checkout abroad is a Speedy checkout; Econt, Pigeon, BOX NOW and
   Sameday were never measured against a foreign address and offer none. A shop that switches this on
   loses its courier choice at the border without being told why.
2. **Only office delivery was carried end to end.** Delivery to a Romanian street address and to a
   Romanian automat are built and priced but were never booked, and Speedy's street nomenclature
   abroad has not been looked at.
3. **Cash on delivery abroad has never been collected.** A shop with its own cash register is allowed
   to offer it, and 202 accepts the amount - but no parcel has come back with the money, so the
   pay-out and its fee are unverified.
4. **Nothing outside the EU can work.** There is no customs data anywhere in the plugin - no contents
   declaration, no value for customs, no HS codes, no invoice. Romania needs none; the next country
   might.
5. **Tracking abroad is unproven.** The one international waybill was cancelled, not delivered, so no
   foreign status code has ever been read back - and status codes are what move an order.
6. **The free-shipping threshold is a domestic number** and is deliberately not applied abroad. That
   is the safe answer, not the finished one: a shop that wants free delivery to a country has no way
   to say so.
7. **VAT is the shop's problem and the plugin says nothing about it.** Selling into another country
   is not only a shipping question.

## What finishing it looks like

In the order the questions have to be answered: a second courier abroad (Econt is the obvious one),
address and automat carried end to end, cash on delivery collected once for real, tracking read back
from a delivered foreign parcel, and a decision about customs before any non-EU country is offered.
Then the picker comes back, the e2e spec is unskipped, and the filter goes away.
