<?php
defined('ABSPATH') || exit;

/**
 * The plugin's settings, read. Flat WC options (see feedback-settings-architecture); prices are always
 * in the store's currency (no per-method currency; no dual-currency display in this plugin).
 *
 * Only readers live here, on purpose: this class is asked on every checkout request - which couriers
 * are on, which delivery kinds, who pays, whether cash on delivery is allowed - and PHP has to parse
 * the whole file to answer. The admin side of the same settings (the WooCommerce tab, its custom
 * field renderers, the credential hints and the AJAX behind Validate / Sync / Save) is
 * BGCouriers_Settings_Admin, loaded in wp-admin only. The tab itself is BGCouriers_WC_Settings.
 */
class BGCouriers_Settings {

    const METHODS = ['office', 'address', 'automat'];
    // ---- data accessors ----

    public static function get(string $group, string $key, $default = '') {
        $name = $group === 'global' ? 'bgcouriers_' . $key : 'bgcouriers_' . $group . '_' . $key;
        return get_option($name, $default);
    }
    /**
     * What this courier needs to TALK to its API - and nothing about whether the shop is offering it.
     *
     * These two were one question, and that is what deadlocked every new install. Saving a username
     * sets `_validated = no`; a courier may not be ENABLED until it is validated; validating asked for
     * courier_config(), which returned null while the courier was disabled - and answered "No
     * credentials saved" about credentials that were saved and locked on the screen in front of you.
     * So: you could not enable without validating, could not validate without enabling, and the one
     * message you were given pointed at the wrong thing entirely. Reported by a merchant who installed
     * the plugin from WordPress.org and simply could not get past the settings screen.
     *
     * Credentials also outlive the toggle in a second way: a courier switched off still has orders that
     * were placed with it, and printing or tracking those must keep working.
     */
    public static function courier_credentials(string $courier): ?array {
        if (!array_key_exists($courier, BGCouriers_Couriers::all())) { return null; }
        return [
            'username' => get_option('bgcouriers_' . $courier . '_username', ''),
            'password' => BGCouriers_Encryption::decrypt(get_option('bgcouriers_' . $courier . '_password', '')),
        ];
    }
    /**
     * The credentials, but only while the courier is switched on - i.e. "should the shop be using this
     * courier at all". Every caller asking that question keeps calling this; the ones that only need to
     * reach the API (validate, sync, labels for orders already placed) use courier_credentials().
     */
    public static function courier_config(string $courier): ?array {
        if (get_option('bgcouriers_' . $courier . '_enabled', 'no') !== 'yes') { return null; }
        // Switched on is an INTENTION; credentials are what make it possible. Enabling a courier no
        // longer waits for them (see enable_problems() - a merchant may switch a courier on and set it
        // up afterwards), so the question moved here, where it belongs: without credentials there is no
        // live price to quote and no waybill to print, and a courier offered on a fallback price it can
        // never turn into a label is worse than one that is simply not offered yet.
        //
        // This also closes a hole that was already open: the ✕ beside a credential field marks it as
        // needing re-validation and does NOT clear the stored value, so `creds_present()` stayed true and
        // the courier went on quoting with credentials the shop had just called into question.
        if (!self::creds_present($courier)) { return null; }
        if (get_option('bgcouriers_' . $courier . '_validated', 'yes') !== 'yes') { return null; }
        return self::courier_credentials($courier);
    }
    /**
     * Everything that has to be true before the CHECKOUT may offer this courier at all.
     *
     * The gates, in the order a merchant meets them: switched on, credentials saved and validated
     * (courier_config()), and at least one delivery option left on. The zone is WooCommerce's own gate -
     * it never calls a shipping method that is not in a matching zone - and the courier's own
     * configuration (a PPP it cannot do, a country it does not serve) is decided per package, later.
     *
     * The last one used to be missing: selection_for() falls back to 'office' when nothing is enabled,
     * so a courier with every delivery option switched off quoted an office delivery anyway. Measured on
     * dev 2026-09-03 - Express One had all three toggles off and was still being offered.
     */
    public static function courier_offerable(string $courier): bool {
        return self::courier_config($courier) !== null && self::enabled_methods($courier) !== [];
    }
    /**
     * Is this courier's shipping method in a shipping zone, switched on?
     *
     * Not a gate this plugin enforces - WooCommerce simply never calls a method outside a matching zone,
     * which is the right behaviour and needs no help. It is a DIAGNOSIS: a courier that is on, validated
     * and fully configured and still does not appear has usually never been added to a zone, and nothing
     * anywhere said so. Answers true when it cannot tell, so it never cries wolf.
     */
    public static function in_a_shipping_zone(string $courier): bool {
        if (!class_exists('WC_Shipping_Zones')) { return true; }
        $id = 'bgcouriers_' . $courier;
        $zones = WC_Shipping_Zones::get_zones();
        $zones[] = ['id' => 0]; // "Locations not covered by your other zones" is a zone like any other
        foreach ($zones as $z) {
            $zone = WC_Shipping_Zones::get_zone((int) ($z['id'] ?? 0));
            if (!$zone) { continue; }
            foreach ($zone->get_shipping_methods(true) as $m) {
                if (isset($m->id) && $m->id === $id) { return true; }
            }
        }
        return false;
    }
    /** @return array<string,string> id => label of registered couriers. */
    public static function couriers(): array { return BGCouriers_Couriers::all(); }
    /**
     * Per courier+method delivery-price mode:
     *  - 'live'     : live API only (cached/reference before an address is chosen); no fixed default.
     *  - 'fallback' : live API, fall back to the fixed price if the API is unavailable.
     *  - 'fixed'    : always the fixed price; no live API calls at checkout.
     */
    public static function price_mode(string $courier, string $method): string {
        $m = (string) get_option('bgcouriers_' . $courier . '_' . $method . '_price_mode', 'fallback');
        if (!in_array($m, ['live', 'fallback', 'fixed'], true)) { $m = 'fallback'; }
        // A courier with no price endpoint can only ever be fixed, whatever is stored - a mode saved
        // before the courier's capabilities were known would otherwise send checkout looking for a live
        // price on every request and quietly fall back on each one.
        $c = BGCouriers_Couriers::get($courier);
        if ($c && !in_array('live_quote', $c->capabilities(), true)) { return 'fixed'; }
        return $m;
    }
    /** Per delivery-method config (default price in store currency, free-shipping threshold). */
    public static function method_config(string $courier, string $method): array {
        $p = 'bgcouriers_' . $courier . '_' . $method . '_';
        return [
            'enabled' => get_option($p . 'enabled', 'yes') === 'yes',
            'price'   => (float) get_option($p . 'price', 0),
        ];
    }
    /** Default number of results in the checkout city / street search. */
    const DROPDOWN_LIMIT = 20;
    /**
     * How many results a checkout search returns. This bounds the CITY search (only when the city lists
     * are not preloaded - with preloading the search is local and shows everything) and the STREET search.
     * Office lists are never limited: they are fetched whole for the chosen city.
     *
     * Not unlimited on purpose. A two-letter term matches hundreds of streets in a big city and thousands
     * of villages nationwide; sending and rendering that on every keystroke is slow exactly on the phones
     * where it hurts most. 20 is far past "my town is missing" while staying small.
     */
    public static function dropdown_limit(): int {
        $raw = get_option('bgcouriers_dropdown_limit', self::DROPDOWN_LIMIT);
        if ($raw === '' || (int) $raw <= 0) { return self::DROPDOWN_LIMIT; }
        return (int) $raw;
    }
    /**
     * Method-level free shipping (the merchant absorbs it) over a goods-total threshold.
     * Auto-enabled by a positive threshold - there is no separate on/off flag.
     */
    /**
     * Does THIS order qualify for merchant-absorbed free delivery?
     *
     * Kept separate from free_shipping() because the answer has to be identical in two places that see
     * different objects: the checkout rate (a cart) and the waybill (an order). It is also what decides
     * WHO the courier bills - a free delivery is the shop's own cost, so the label must go out
     * sender-paid even for a courier otherwise set to "the recipient pays".
     *
     * @param \WC_Order $order   The order.
     * @param string    $courier Courier id.
     * @return bool
     */
    public static function free_for_order(\WC_Order $order, string $courier): bool {
        $method = (string) $order->get_meta('_bgcouriers_method');
        $cfg    = self::free_shipping($courier, $method);
        if (empty($cfg['enabled'])) { return false; }
        // Goods only, exactly as the cart rule reads it: the delivery itself never counts towards the
        // threshold, or a big enough delivery charge would qualify an order on its own.
        $goods = (float) $order->get_subtotal();
        return $goods >= (float) $cfg['threshold'];
    }
    /**
     * Free-shipping config for a courier (+optionally one of its delivery methods). Precedence:
     * a COURIER-level threshold applies to every delivery option (the per-option fields are inactive
     * in the UI while it is set); only when it is empty do the per-option thresholds take over.
     */
    public static function free_shipping(string $courier, string $method = ''): array {
        $threshold = (float) get_option('bgcouriers_' . $courier . '_free_threshold', 0);
        if ($threshold <= 0 && $method !== '') {
            $threshold = (float) get_option('bgcouriers_' . $courier . '_' . $method . '_free_threshold', 0);
        }
        return [
            'enabled'   => $threshold > 0,
            'threshold' => $threshold,
        ];
    }
    /**
     * Default parcel dimensions in cm - one set for ALL couriers whose APIs take a parcel size
     * (a locker parcel must fit its box). Falls back to the old per-Pigeon options on installs
     * that configured those before the fields moved to General.
     */
    public static function box_dims(): array {
        // Default 10x10x2 cm - the shape this shop actually ships (sachets and small bottles), and small
        // enough to pass every courier's locker (APS) compartment validation out of the box. A too-large
        // default is not free: Speedy rejected automat shipments outright at the old 40cm.
        $g = static function (string $k, int $default): int {
            $v = (int) get_option('bgcouriers_box_' . $k, 0);
            if ($v <= 0) { $v = (int) get_option('bgcouriers_pigeon_box_' . $k, $default); } // pre-move installs
            return max(1, $v);
        };
        return ['length' => $g('length', 10), 'width' => $g('width', 10), 'height' => $g('height', 2)];
    }
    /**
     * Default parcel weight in kg, declared on a waybill when the order's products carry no weight of
     * their own. One value for ALL couriers - a per-courier fallback made the same order weigh different
     * amounts depending on who shipped it. Clamped to the 0.1 kg floor every courier API enforces.
     */
    public static function default_weight_kg(): float {
        return max(0.1, round((float) get_option('bgcouriers_default_weight_kg', 1.0), 3));
    }
    /** One contents description for every courier's waybill (moved to General from per-courier fields). */
    public static function shipment_contents(): string {
        $v = trim((string) get_option('bgcouriers_shipment_contents', ''));
        if ($v === '') { // pre-move installs configured these per courier
            $v = trim((string) get_option('bgcouriers_speedy_contents', ''))
                ?: trim((string) get_option('bgcouriers_econt_shipment_description', ''));
        }
        return $v !== '' ? $v : 'Goods';
    }
    /** @return string[] delivery methods enabled for the courier (drives checkout options). */
    public static function enabled_methods(string $courier): array {
        $out = [];
        foreach (self::METHODS as $m) {
            if (get_option('bgcouriers_' . $courier . '_' . $m . '_enabled', 'yes') === 'yes') { $out[] = $m; }
        }
        // Prune to what the courier can actually do AND has synced points for - so an option the courier
        // does not offer (e.g. Pigeon "to APS", which has no lockers) never reaches checkout, even if the
        // toggle option still reads 'yes'. Falls back to raw toggles if the registry isn't loaded yet.
        if (class_exists('BGCouriers_Couriers')) {
            $co = BGCouriers_Couriers::get($courier);
            if ($co) { $out = array_values(array_intersect($out, $co->available_methods())); }
        }
        return $out;
    }
    /**
     * The country the shop ships FROM.
     *
     * Every courier in this plugin is a Bulgarian network and every account's sender address is
     * Bulgarian, so this is BG - it is a named thing rather than a literal because "is this destination
     * abroad" is now asked in the pricing, the payer, the service, the checkout and the label, and a
     * question asked in five places must be answered in one.
     */
    public static function home_country(): string { return 'BG'; }
    /**
     * Is delivery outside the shop's own country offered at all?
     *
     * No, and deliberately: the pieces are built and one real BG->RO waybill was created, printed and
     * cancelled on a live account, but the feature is not finished - see docs/international-shipping.md
     * for what is proven and what is still missing. Until it is, no shop gets half of it by accident,
     * so this is one switch that answers for the whole plugin rather than a setting on a page.
     *
     * A site that wants the unfinished thing anyway says so and takes what comes with it:
     *
     *     add_filter('bgcouriers_intl_enabled', '__return_true');
     *
     * Nothing is thrown away while it is off. A merchant's chosen countries stay in their option and
     * come back exactly as they were the day this returns true.
     */
    public static function intl_enabled(): bool {
        return (bool) apply_filters('bgcouriers_intl_enabled', false);
    }
    /**
     * Countries this courier is configured to deliver to besides home, as ISO alpha-2.
     *
     * Empty unless international delivery is switched on AND the merchant has chosen some AND the
     * courier still offers them: an option left behind by a courier that later dropped a country must
     * not keep quoting it. Empty is the answer for every shop as the plugin ships, which is the whole
     * point - this is the one place the rest of the plugin asks where a parcel may go, so answering
     * "home only" here is what keeps a foreign address out of the pricing, the checkout, the sync and
     * the label, even on a shop that has put this courier's method in a foreign country's zone.
     *
     * @param string $courier Courier id.
     * @param object|null $co  The courier itself, when the caller already holds it - it is the authority
     *                         on what it can do, and passing it keeps this answerable without the
     *                         registry (which is not up in every context that asks).
     * @return string[]
     */
    public static function intl_countries(string $courier, $co = null): array {
        if (!self::intl_enabled()) { return []; }
        $saved = get_option('bgcouriers_' . $courier . '_intl_countries', []);
        if (!is_array($saved) || !$saved) { return []; }
        if (!$co && class_exists('BGCouriers_Couriers')) { $co = BGCouriers_Couriers::get($courier); }
        $can = ($co && method_exists($co, 'intl_countries')) ? $co->intl_countries() : [];
        $want = array_map('strtoupper', array_map('strval', $saved));
        return array_values(array_intersect($want, $can));
    }
    /** Can this courier take a parcel to this country at all - home, or one the merchant switched on? */
    public static function ships_to(string $courier, string $country, $co = null): bool {
        $c = strtoupper(trim($country));
        return $c === '' || $c === self::home_country() || in_array($c, self::intl_countries($courier, $co), true);
    }
    /**
     * Is this destination outside the shop's own country - the question the service id, the payer and the
     * whole "no quiet fallback" rule turn on. '' is home: an order with no country yet is not abroad.
     */
    public static function is_intl(string $country): bool {
        $c = strtoupper(trim($country));
        return $c !== '' && $c !== self::home_country();
    }
    /**
     * Where an order is actually going, as ISO alpha-2.
     *
     * The plugin's own meta first: it is what the customer picked in the delivery box, and it is the
     * country the town and office ids were looked up in. WooCommerce's shipping country is the fallback
     * for every order made before this existed (and for one edited by hand), and the shop's own country
     * is the last word - an order with nothing at all is domestic, as every order was until now.
     */
    public static function order_country(\WC_Order $order): string {
        $c = strtoupper(trim((string) $order->get_meta('_bgcouriers_country')));
        if ($c === '') { $c = strtoupper(trim((string) $order->get_shipping_country())); }
        if ($c === '') { $c = strtoupper(trim((string) $order->get_billing_country())); }
        return $c !== '' ? $c : self::home_country();
    }
    /** Every country this courier delivers to, home first - the checkout's country dropdown. */
    public static function delivery_countries(string $courier, $co = null): array {
        return array_merge([self::home_country()], self::intl_countries($courier, $co));
    }
    /** Auto-generate labels when an order reaches a status. */
    public static function autolabel(): array {
        return [
            'enabled' => get_option('bgcouriers_autolabel_enabled', 'no') === 'yes',
            'status'  => get_option('bgcouriers_autolabel_status', 'wc-processing'),
        ];
    }
    /**
     * Does the automatic waybill wait for the day the order says it ships?
     *
     * A shop that shows the customer a dispatch day (a delivery-date plugin stamps it on the order) and
     * issues the waybill the moment the order is paid has a live shipment at the courier for as long
     * as the gap is: Sameday sent its courier the same day for a parcel going out the next (2026-08-26),
     * and four Speedy waybills sat "information received" for a month of closure (2026-09-12). Waiting
     * is the default for a shop being set up today. An install that already existed keeps what it had -
     * BGCouriers_Plugin::pin_autolabel_wait() writes that down once - because the same shop shipped
     * one order straight through its closure, and when the day is honoured is the merchant's to say.
     */
    public static function autolabel_wait(): bool {
        return get_option('bgcouriers_autolabel_wait', 'yes') === 'yes';
    }
    /**
     * Should an order with THIS courier get its waybill by itself?
     *
     * Not one answer for the whole shop, because creating a waybill does not mean the same thing to
     * every courier. To Sameday it means "the parcel exists, come and get it": a waybill issued four
     * seconds after checkout brought its courier to the door the same morning, for a parcel the shop
     * was not sending until the next day, and the courier voided it on the spot (2026-08-26). Speedy
     * and Econt are asked to come in a separate request, so an early waybill costs them nothing.
     *
     * '' (the default) follows the general setting; 'yes'/'no' on a courier's own tab overrule it.
     */
    public static function autolabel_for(string $courier): bool {
        $own = (string) get_option('bgcouriers_' . $courier . '_autolabel', '');
        if ($own === 'yes' || $own === 'no') { return $own === 'yes'; }
        // Nobody has said. A courier that comes for the parcel as soon as a waybill exists starts OFF,
        // whatever the general setting says: the shop that has not thought about this yet is exactly the
        // one that would otherwise lose a collection to it, and every shop would lose the same one.
        // Nothing changes for an install that already existed - BGCouriers_Plugin::pin_autolabel()
        // writes its current answer down before this line is ever reached on it.
        $co = class_exists('BGCouriers_Couriers') ? BGCouriers_Couriers::get($courier) : null;
        if ($co && method_exists($co, 'books_pickup_on_create') && $co->books_pickup_on_create()) { return false; }
        return self::autolabel()['enabled'];
    }
    /** Whether the customer's e-mail may be sent to the courier when generating a label. */
    public static function send_email(): bool {
        return get_option('bgcouriers_send_email', 'no') === 'yes';
    }
    /**
     * Whether the checkout insists on an e-mail address.
     *
     * OFF by default, which is what this plugin has always done: it takes WooCommerce's required e-mail
     * field and makes it optional, because a courier label needs a phone and not an address to write to.
     * A shop that sends order e-mails, or issues invoices, needs the address after all - and until now
     * the only way back was a snippet in functions.php. The phone is not part of this choice: every
     * courier's waybill is built with it.
     */
    public static function require_email(): bool {
        return get_option('bgcouriers_require_email', 'no') === 'yes';
    }
    /**
     * Whether the plugin's own delivery fields replace WooCommerce's address fields at checkout.
     *
     * ON by default, because that is how the plugin is meant to be used: the courier's city, office or
     * automat IS the delivery address, and leaving WooCommerce's Address/City/Postcode next to it asks
     * the customer for the same thing twice and lets the two disagree. A store that also ships some
     * other way - its own van, pickup, a courier this plugin does not cover - turns it off and keeps
     * WooCommerce's fields.
     */
    public static function own_address_fields(): bool {
        return get_option('bgcouriers_own_address_fields', 'yes') === 'yes';
    }
    /**
     * Where the destination picker (town, office, locker, street) is shown at checkout.
     *
     * 'rate' - under the courier's own shipping rate, where WooCommerce keeps the rates: the order-review
     * table, at the bottom of the page. That is where it has always been, and it stays the default because
     * the picker belongs to a rate and reads as part of it.
     *
     * 'details' - under the customer's own details, right after the phone. On a shop with checkout add-ons
     * ("extras"), gift options or a long totals table, the rates sit below all of it, so the one field the
     * customer has to fill in for the parcel to arrive is the last thing they reach - after everything
     * optional. This puts it with the rest of what the order needs from them; the rate radios and the
     * price stay in the table.
     *
     * Classic checkout only. The block checkout renders in React over the Store API and fires none of
     * WooCommerce's form hooks (see BGCouriers_Blocks), so there is nothing to print the host into and it
     * keeps the picker with its rate - which is what the setting's own description says on the screen.
     */
    public static function picker_position(): string {
        $v = (string) get_option('bgcouriers_picker_position', 'rate');
        return in_array($v, ['rate', 'details'], true) ? $v : 'rate';
    }
    /**
     * Whether WooCommerce's cart shipping calculator (Country / Region / City / Postcode) is hidden.
     *
     * ON by default for the same reason: it prices a delivery to a postcode, while every rate here is
     * priced to a courier office, an automat or a street address chosen at checkout - so the number it
     * shows is not the number the customer will pay. A store that wants it back unticks this.
     */
    public static function hide_shipping_calculator(): bool {
        return get_option('bgcouriers_hide_shipping_calc', 'yes') === 'yes';
    }
    /** The e-mail to pass to a courier for this order: the customer's, only if enabled and non-empty. */
    public static function label_email(\WC_Order $order): string {
        return self::send_email() ? (string) $order->get_billing_email() : '';
    }
    /** Label paper size setting (A6 or A4), per courier. */
    public static function label_paper_size(string $courier = 'speedy'): string {
        $v = (string) get_option('bgcouriers_' . $courier . '_label_paper_size', 'A6');
        return in_array($v, ['A6', 'A4'], true) ? $v : 'A6';
    }
    public static function free_shipping_label(): string {
        return (string) get_option('bgcouriers_free_shipping_label', '');
    }
    /**
     * How the merchant fiscalises cash the courier collects on delivery:
     *  - 'cash_register' (default): the merchant issues the receipt themselves - COD works with any courier;
     *  - 'ppp': the merchant relies on the courier paying out via postal money order (PPP), which is only
     *    legal with couriers that actually offer PPP.
     */
    public static function cod_fiscalization(): string {
        return get_option('bgcouriers_cod_fiscalization', 'cash_register') === 'ppp' ? 'ppp' : 'cash_register';
    }
    /** Whether THIS courier pays collected COD out to the merchant via PPP (postal money order). */
    /**
     * Whether the recipient may open - and possibly test - the parcel before paying.
     *
     * 'no' | 'open' | 'test'. One setting for the whole shop rather than one per courier: it is a
     * promise made to the customer, and a shop that lets you check the box with one courier and not
     * with another is telling two different stories about the same order.
     *
     * Not every courier can do it. Speedy has the obpd service (OPEN/TEST) and Econt has payAfterAccept
     * and payAfterTest; Pigeon and Sameday publish no such field, and BOX NOW is lockers, where there is
     * no courier standing there to supervise an inspection.
     *
     * @return string
     */
    /**
     * Does this courier ship a prepaid return waybill with every parcel?
     *
     * Off by default because the courier charges for it - a plugin that quietly added a paid service to
     * every shipment would be spending the merchant's money for them, the same reasoning as insurance.
     */
    public static function return_voucher(string $courier): bool {
        return get_option('bgcouriers_' . $courier . '_return_voucher', 'no') === 'yes';
    }
    public static function open_before_pay(): string {
        $v = (string) get_option('bgcouriers_open_before_pay', '');
        if ($v === '') {
            // Installs configured before this became one setting: keep doing exactly what they did.
            $legacy = (string) get_option('bgcouriers_speedy_open_before_pay', '');
            if ($legacy === '') {
                $legacy = get_option('bgcouriers_econt_pay_after_accept', 'no') === 'yes' ? 'open' : 'no';
            }
            $v = $legacy;
        }
        return in_array($v, ['no', 'open', 'test'], true) ? $v : 'no';
    }
    public static function courier_ppp_payout(string $courier): bool {
        // Per-courier toggle. The default is the courier's own answer (Speedy and Econt do it as
        // standard); the merchant flips it either way the day their contract says so - no code change.
        $co      = class_exists('BGCouriers_Couriers') ? BGCouriers_Couriers::get($courier) : null;
        $default = ($co && method_exists($co, 'ppp_payout_by_default') && $co->ppp_payout_by_default()) ? 'yes' : 'no';
        return get_option('bgcouriers_' . $courier . '_ppp_payout', $default) === 'yes';
    }
    /**
     * Does this courier's PPP payout reach this destination?
     *
     * PPP is a Bulgarian postal money transfer, and it stops at the border. Speedy refuses it outright for
     * a foreign address - sla.cod.moneyTransfer.cod_sub_service_validator.money-transfer-not-allowed-for-
     * foreign-countries, measured 2026-08-19 - and the whole price calculation is refused with it, so a
     * checkout that kept asking for one simply stopped offering the courier at all. Abroad the money can
     * only be collected as plain CASH.
     *
     * Which matters twice over. It decides the processingType the shipment is created with, and it decides
     * whether cash-on-delivery may be offered at all: a shop whose COD is legal only BECAUSE the courier
     * does the PPP has no such arrangement abroad, so an international order there has to be prepaid.
     */
    public static function ppp_payout_reaches(string $courier, string $country): bool {
        return self::courier_ppp_payout($courier) && !self::is_intl($country);
    }
    /**
     * Delivery kinds this courier cannot collect cash on - see BGCouriers_Abstract_Courier::no_cod_methods().
     *
     * Filtered rather than hardcoded here on purpose. The courier told us "at the moment", which is a
     * rule with an expiry date on it, and a shop that hears from Express One before we do can lift it
     * (or a shop whose own contract differs can add one) without waiting for a release.
     *
     * @return string[]
     */
    public static function no_cod_methods(string $courier): array {
        $co   = class_exists('BGCouriers_Couriers') ? BGCouriers_Couriers::get($courier) : null;
        $list = ($co && method_exists($co, 'no_cod_methods')) ? (array) $co->no_cod_methods() : [];
        return array_values(array_filter((array) apply_filters('bgcouriers_no_cod_methods', $list, $courier), 'is_string'));
    }
    /**
     * Whether cash on delivery may be used - with this courier, and for this kind of delivery.
     *
     * Two independent reasons it may not be. The merchant's own: a shop with no cash register fiscalises
     * through the courier's PPP, so a courier that does not do one cannot legally take the money. And the
     * courier's own: Express One collects nothing at an EXOBOX locker. Both answer the same question, so
     * they are answered in one place - the checkout, the quote and the waybill all ask it here.
     *
     * @param string $method '' when the delivery kind is not known (or not being asked about).
     */
    public static function cod_allowed_for(string $courier, string $method = ''): bool {
        if ($method !== '' && in_array($method, self::no_cod_methods($courier), true)) { return false; }
        return self::cod_fiscalization() === 'cash_register' || self::courier_ppp_payout($courier);
    }
    /**
     * Whether this courier's delivery price is charged with the order at checkout (default) or only shown
     * for information while the customer pays the courier's own fee on delivery. Drives BOTH the checkout
     * rate cost (0 when off) and the waybill payer/COD amount (service_payer(): off = recipient pays,
     * COD collects goods only). A courier with no recipient-pays field in its API - BOX NOW - is always
     * charged with the order; each courier answers that for itself (recipient_can_pay_delivery()).
     */
    public static function ship_in_total(string $courier): bool {
        // Asked of the courier first: BOX NOW cannot bill the recipient, so for it the toggle does not
        // apply and delivery is always charged with the order.
        $co = class_exists('BGCouriers_Couriers') ? BGCouriers_Couriers::get($courier) : null;
        if ($co && method_exists($co, 'recipient_can_pay_delivery') && !$co->recipient_can_pay_delivery()) { return true; }
        $v = (string) get_option('bgcouriers_' . $courier . '_ship_in_total', '');
        if ($v === '') {
            // Unset means a shop that has never opened the setting, and it now means OFF: the customer
            // pays the courier at the door and the shop's books carry the goods, not a delivery fee it
            // collects and pays straight out again. A shop that was already running when this changed
            // keeps what it had - BGCouriers_Plugin::pin_who_pays_delivery() writes its answer down
            // before this line is ever reached, so this default only ever meets a new install.
            // The pre-toggle "Who pays delivery" select is still honoured where one was saved.
            return get_option('bgcouriers_' . $courier . '_service_payer', 'recipient') !== 'recipient';
        }
        return $v !== 'no';
    }
    public static function is_cod_gateway(string $gid, $gw): bool {
        return $gid === 'cod' || (is_object($gw) && is_a($gw, 'WC_Gateway_COD'));
    }
    /** Whether the shop has at least one enabled NON-COD (prepaid / card / bank transfer) payment gateway. */
    public static function has_prepaid_gateway(): bool {
        // WC() itself can be null before WooCommerce has finished booting - dereferencing it straight
        // away made this fatal rather than fall back.
        $wc = function_exists('WC') ? WC() : null;
        if (!$wc || !$wc->payment_gateways()) { return true; }   // can't tell -> assume yes (don't over-restrict)
        foreach ($wc->payment_gateways()->payment_gateways() as $gid => $gw) {
            if (is_object($gw) && $gw->enabled === 'yes' && !self::is_cod_gateway((string) $gid, $gw)) { return true; }
        }
        return false;
    }
    /**
     * Warning to show on a courier's settings tab when it can't be fully used under the current COD setup:
     * only when fiscalisation = PPP and this courier does NOT do PPP. Returns ['level'=>'error'|'warning',
     * 'msg'=>string] or null when there's nothing to warn about.
     *
     * @return array{level:string,msg:string}|null
     */
    /**
     * Why this courier cannot be used as currently configured, if there is a reason. Drives both the red
     * tab tint and the notice on the courier's own settings page, so a courier that will fail is visible
     * before an order runs into it rather than after.
     *
     * @param string $courier Courier id.
     * @return array{level:string,msg:string}|null
     */
    public static function courier_blocker(string $courier): ?array {
        // Sameday refuses recipient-paid delivery unless the contract covers it, and when it refuses no
        // waybill is created AT ALL - every order with this courier fails. We only know once Sameday has
        // said so (there is no way to ask up front), so this reads the flag that its rejection sets.
        if ($courier === 'sameday'
            && get_option(BGCouriers_Sameday::NO_RECIPIENT_PAY, '') === 'yes'
            && !self::ship_in_total('sameday')) {
            return ['level' => 'error', 'msg' => __('Sameday does not support “the recipient pays the delivery” on this account, so NO waybill can be created while it is set that way. Turn on “Delivery in the order total” below, or ask Sameday to allow recipient payment on your contract.', 'bg-couriers')];
        }
        // Econt collecting cash on delivery under an agreement that does not match how the shop says it
        // is paid out: the money comes back as an ordinary transfer while the shop believes it is a PPP,
        // which is the difference between having fiscalisation covered and not.
        if ($courier === 'econt' && get_option('bgcouriers_econt_cod_enabled', 'no') === 'yes') {
            $c = BGCouriers_Couriers::get('econt');
            if ($c && method_exists($c, 'payout_problems')) {
                foreach ($c->payout_problems() as $p) {
                    return ['level' => 'error', 'msg' => $p['msg'] . ' ' . $p['fix']];
                }
            }
        }
        // Anything a courier itself declares as missing. Until now only the two hand-written cases above
        // produced a banner, so a courier could sit there enabled and green while lacking a setting it
        // cannot create a single waybill without - BOX NOW with no sender phone answered every order
        // with {"code":"P405"}, at label time, one order at a time. enable_problems() is the list each
        // courier already keeps of what it needs; showing it here means every courier's required
        // settings are covered by the one mechanism, including couriers added later.
        // "This courier cannot appear at checkout at all" outranks "this courier is missing a setting":
        // the first tells the merchant nothing will ever reach it, the second is a gap to fill in on a
        // courier that would otherwise work. Reported the other way round, the banner named a missing
        // sender phone on a BOX NOW that no customer could pick in the first place.
        $ppp = self::ppp_courier_notice($courier);
        if ($ppp && $ppp['level'] === 'error') { return $ppp; }

        $c = BGCouriers_Couriers::get($courier);
        if ($c && method_exists($c, 'enable_problems')) {
            $problems = $c->enable_problems();
            if ($problems) {
                $first = reset($problems);
                return ['level' => 'error', 'msg' => trim(
                    /* translators: 1: what is missing, 2: how to fix it */
                    sprintf(__('%1$s %2$s Until this is resolved the courier cannot create waybills.', 'bg-couriers'),
                        (string) ($first['msg'] ?? ''), (string) ($first['fix'] ?? ''))
                )];
            }
        }
        return $ppp;   // the amber "prepaid orders only" warning, or nothing at all
    }
    public static function ppp_courier_notice(string $courier): ?array {
        if ($courier === '' || self::cod_fiscalization() !== 'ppp' || self::courier_ppp_payout($courier)) {
            return null;
        }
        if (self::has_prepaid_gateway()) {
            return ['level' => 'warning', 'msg' => __('This courier does not offer ППП. Because your Cash on delivery setting relies on the courier\'s ППП, it can be used here only for PREPAID orders - cash-on-delivery is turned off for it at checkout.', 'bg-couriers')];
        }
        return ['level' => 'error', 'msg' => __('This courier does not offer ППП and your shop has no prepaid (card / bank transfer) payment method, so it cannot take cash-on-delivery and will NOT appear at checkout. Add a prepaid payment method, or set Cash on delivery to "I have a cash register".', 'bg-couriers')];
    }
    public static function hidden_fields(): string {
        return (string) get_option('bgcouriers_hidden_fields', '');
    }
    /**
     * The merchant's "hide these checkout fields" setting, reduced to a CSS selector list that is safe to
     * print into a stylesheet. This is the escaping gate for that option: it ends up inside a stylesheet,
     * where the danger is a value that closes the selector and opens its own rule.
     *
     * Each entry is validated on its own and DROPPED if it is not a plain selector - deliberately not
     * "stripped clean", because silently rewriting someone's selector into a different one that happens to
     * match other elements is worse than ignoring it. Anything that could terminate the selector or start a
     * declaration ({ } ; @ \ / < > and friends) simply fails the pattern.
     *
     * @return string comma-joined selectors, or '' when nothing usable remains
     */
    public static function hidden_field_selectors(): string {
        $out = [];
        foreach (explode(',', wp_strip_all_tags(self::hidden_fields())) as $sel) {
            $sel = trim($sel);
            if ($sel === '' || strlen($sel) > 200) { continue; }
            // tag / .class / #id / * to start, then selector syntax only: [attr="v"], :pseudo, combinators.
            if (!preg_match('/^[A-Za-z0-9_\-#.*:\[][A-Za-z0-9_\-#.*\[\]="\':()\s>+~]*$/', $sel)) { continue; }
            $out[] = $sel;
        }
        return implode(',', $out);
    }
    /** Emergency help shown after repeated checkout failures. */
    public static function emergency(): array {
        return [
            'phone'   => (string) get_option('bgcouriers_emergency_phone', ''),
            'message' => (string) get_option('bgcouriers_emergency_message', ''),
        ];
    }
    /** Configured order of delivery methods at checkout (all methods, default order). */
    public static function method_order(string $courier): array {
        $raw = (string) get_option('bgcouriers_' . $courier . '_method_order', '');
        $order = $raw !== '' ? array_values(array_filter(array_map('trim', explode(',', $raw)))) : [];
        foreach (self::METHODS as $m) { if (!in_array($m, $order, true)) { $order[] = $m; } }
        return array_values(array_intersect($order, self::METHODS));
    }
    /** Configured order couriers appear at checkout (registered couriers, default registration order). */
    public static function courier_order(): array {
        $all = array_keys(BGCouriers_Couriers::all());
        $raw = (string) get_option('bgcouriers_courier_order', '');
        $order = $raw !== '' ? array_values(array_filter(array_map('trim', explode(',', $raw)))) : [];
        foreach ($all as $c) { if (!in_array($c, $order, true)) { $order[] = $c; } }
        return array_values(array_intersect($order, $all));
    }
    /**
     * Are this courier's credentials SAVED? Deliberately says nothing about whether it is switched on.
     *
     * The enable toggle used to be part of this test, which got the relationship backwards in three
     * places. enable_problems() answers "why can I not turn this courier on" and would reply "API
     * credentials are missing" for a courier whose only problem was being off - the very thing it was
     * being asked about. The Econt section skipped loading its CD/sender lists. Worst of all,
     * render_actions() left the username and password rendered blank and EDITABLE, and a browser will
     * happily autofill the merchant's own e-mail and site password into an empty "Client ID" - one Save
     * away from overwriting real courier credentials with a login. Stored credentials lock themselves
     * behind the ✕ now whether or not the courier is enabled.
     */
    public static function creds_present(string $courier = 'speedy'): bool {
        foreach (self::credential_fields($courier) as $f) {
            if (get_option('bgcouriers_' . $courier . '_' . $f, '') === '') { return false; }
        }
        return true;
    }
    /**
     * The credential fields THIS courier issues - both halves for all but Evropat, which issues one key.
     *
     * Asked of the courier rather than assumed, so a shop is never refused for leaving blank a field its
     * courier never gave it. Falls back to the pair when the courier is not registered (the settings
     * screen can be reached before the registry is built).
     *
     * @return string[]
     */
    public static function credential_fields(string $courier): array {
        $c = BGCouriers_Couriers::get($courier);
        return ($c && method_exists($c, 'credential_fields')) ? $c->credential_fields() : ['username', 'password'];
    }
}
