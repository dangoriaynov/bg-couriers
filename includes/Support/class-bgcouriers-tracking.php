<?php
defined('ABSPATH') || exit;

class BGCouriers_Tracking {
    /**
     * Courier lifecycle phases whose meaning we know, mapped to our stages. A phase is a machine value
     * the courier itself publishes, so where there is one it beats reading the status text.
     *
     * Only the phases that our text rules would get WRONG need listing - an unrecognised phase falls back
     * to 'transit' (see stage()), which is the safe verdict: it never ends tracking and never completes an
     * order. Pigeon's codes are its own, from GET /v1/shipment-statuses.
     *
     * @var array<string,string>
     */
    private const PHASES = [
        // Speedy trackPhase values that really do end a shipment.
        'DELIVERED'                => 'delivered',
        // Couriers that state the outcome outright rather than in prose (Sameday's expeditionSummary
        // has delivered and canceled as flags), so the verdict does not depend on reading Bulgarian.
        'CANCELLED'                => 'cancelled',
        'RETURN_TO_SENDER'         => 'returned',
        'DELIVERED_BACK_TO_SENDER' => 'returned',
        // BOX NOW's parcelState, from its webhook and its /parcels endpoint. Machine values, all of
        // them, and until they were here the webhook wrote them to a key of their own that nothing read
        // - so a BOX NOW order showed a blank where its tracking belonged and was never advanced on
        // delivery. "lost" and "missing" are deliberately absent: neither is a stage this plugin has,
        // and a word in the note is more honest than pretending either is "in transit".
        'new'                  => 'registered',
        'in-transit'           => 'transit',
        'in-final-destination' => 'ready',
        'delivered'            => 'delivered',
        'returned'             => 'returned',
        'expired-return'       => 'returned',
        'canceled'             => 'cancelled',
        // Pigeon. Still on the merchant's desk - the label exists, the parcel has not been collected.
        // Pigeon words the second of these "awaiting loading by the courier", which the text rules read
        // as movement (the Bulgarian for "awaiting" is in the in-flight list below), so the code is the
        // only thing that keeps a printed label from announcing itself shipped.
        'shipment_registered'              => 'registered',
        'shipment_awaiting_courier_pickup' => 'registered',
        // Arrived at the pickup point and waiting for the customer. Pigeon words the first of these
        // "delivered to an office/locker", which our text rules read as DELIVERED - that completes the order and,
        // because delivered is terminal, stops the shipment ever being polled again while it is still
        // sitting in a locker with nobody having touched it.
        'shipment_delivered_to_office'  => 'ready',
        'shipment_left_in_locker'       => 'ready',
        'shipment_held_by_sender'       => 'ready',
        // Nobody came for it. The parcel is still at the office, so it is not moving and not returned
        // either - keeping it non-terminal is what lets us see it turn into a return.
        'shipment_untracked'            => 'ready',
        'shipment_locker_time_expired'  => 'ready',
        'shipment_storage_expired'      => 'ready',
        'shipment_delivered_to_recipient' => 'delivered',
        'shipment_cancelled'              => 'cancelled',
        'shipment_returning_to_sender'    => 'returning',
        'shipment_returned'               => 'returned',
        // Express One's are numbers, so they are prefixed with the courier - "6" on its own would be
        // anybody's. Read off 32 shipments on the test account 2026-08-25; the names are its own words.
        'expressone_0'  => 'registered',   // waybill created - the label exists, the parcel does not move
        'expressone_1'  => 'registered',   // order created
        'expressone_2'  => 'transit',      // accepted by the courier
        'expressone_3'  => 'ready',        // arrived at an office - waiting for the customer, not delivered
        'expressone_5'  => 'transit',      // handed to a courier
        'expressone_6'  => 'delivered',    // delivered
        'expressone_7'  => 'cancelled',    // cancelled
        'expressone_8'  => 'transit',      // failed round - an attempt that failed; the parcel is still out
        'expressone_12' => 'returned',     // returned to the sender
        // Evropat's are numbers too, and its history endpoint does not even send them - it sends the
        // status NAME, which the adapter resolves back to an id against /shipment-statuses-nomenclature
        // (41 statuses, read 2026-08-31). Only the ones our text rules would get WRONG are listed;
        // everything else falls to 'transit', which never ends tracking and never completes an order.
        'evropat_1'  => 'registered',   // created - the waybill exists, the parcel has not moved
        'evropat_2'  => 'registered',   // printed - and still on the merchant's desk
        'evropat_64' => 'ready',        // assigned to an office
        'evropat_82' => 'ready',        // unclaimed - at the office, nobody has come for it
        'evropat_86' => 'ready',        // arrived at an office/depot - the Bulgarian reads as arrival, not delivery
        'evropat_19' => 'delivered',    // delivered on the round
        'evropat_83' => 'returning',    // on its way back to the sender
        'evropat_10' => 'returned',     // returned to the sender
        'evropat_18' => 'cancelled',    // cancelled
        // A refusal is not a cancellation: the parcel is still out and on its way back, and letting the
        // text rules read "refused" as cancelled would close the order on a shipment still in the van.
        'evropat_6'  => 'transit',      // refused
        'evropat_68' => 'transit',      // refused without payment
        'evropat_9'  => 'transit',      // cannot pay the delivery charge, the COD or the PPP
        // 10, the accounting close, is deliberately absent. It says nothing about
        // where the parcel is - it was observed both after a delivery and before a cancellation - so it
        // falls through to 'transit', which never ends a shipment and never completes an order.
    ];

    /**
     * What a courier's own lifecycle phase means to us, or '' when it is not one we know.
     *
     * @param string $phase The courier's phase/status code.
     * @return string One of our stages, or ''.
     */
    public static function phase_stage(string $phase): string {
        return self::PHASES[$phase] ?? '';
    }

    public string $waybill;
    public string $status;
    public array $events;
    /** Courier-supplied lifecycle phase, when the API has one ('' for couriers that don't). */
    public string $phase;
    /**
     * Has the courier physically taken the parcel? true/false when the API answers that outright
     * (Econt's sendTime, Speedy's acceptance operations), null when it does not and the caller has to
     * guess from the event history.
     */
    public ?bool $handover;
    /**
     * Is `status` itself a sentence a merchant can read? Econt, Pigeon and Sameday publish a proper
     * status line, while Speedy's is an operation code ("-14"). It matters because Econt's EVENTS are
     * not statuses at all - they are place names and, on the last one, the recipient's name, so an
     * Econt order was showing a person's name where its status belonged.
     */
    public bool $status_is_human;

    public function __construct(string $waybill, string $status, array $events = [], string $phase = '', ?bool $handover = null, bool $status_is_human = false) {
        $this->waybill         = $waybill;
        $this->status          = $status;
        $this->events          = $events;
        $this->phase           = $phase;
        $this->handover        = $handover;
        $this->status_is_human = $status_is_human;
    }

    /** A readable status: the courier's own status line when it publishes one, else the last event's name. */
    public function human(): string {
        if ($this->status_is_human && trim($this->status) !== '') { return $this->status; }
        if (!empty($this->events)) {
            $last = end($this->events);
            $name = is_array($last) ? (string) ($last['name'] ?? '') : '';
            if ($name !== '') { return $name; }
        }
        return $this->status;
    }

    /**
     * The shipment's lifecycle stage. Prefers the courier's own phase field, which is unambiguous, and falls
     * back to reading the status text. Speedy publishes trackPhase in its schema but does NOT always send it
     * (absent on every parcel we have observed), so the text path has to stay correct on its own.
     * Cancellation has no trackPhase of its own, so that one verdict still comes from the text.
     */
    public function stage(): string {
        if ($this->phase === '') {
            $verdict = self::classify($this->human());
            if ($verdict === 'transit') {
                // The last event's code is a machine value where the courier publishes one; Speedy's
                // 134/1134 mean "waiting at the office/locker" however the description is worded. Checked
                // FIRST: a parcel sitting in an office plainly did leave our hands.
                $last = $this->events ? end($this->events) : [];
                if (in_array((string) ($last['code'] ?? ''), self::READY_CODES, true)) { return 'ready'; }
                // Nothing has moved yet. Creating a waybill only hands the courier the DATA - the parcel
                // is still on the merchant's desk, and every courier reports that state as an event of
                // its own ("shipment information received", "Awaiting delivery to Econt"). Calling that
                // "on its way" on an order placed minutes ago is simply wrong, and it is the state most
                // orders sit in.
                if (!$this->collected()) { return 'registered'; }
            }
            return $verdict;
        }
        $known = self::phase_stage($this->phase);
        if ($known !== '') { return $known; }
        return self::classify($this->human()) === 'cancelled' ? 'cancelled' : 'transit';
    }

    /**
     * Normalise a courier status string to a lifecycle stage: 'delivered' / 'cancelled' / 'returned' /
     * 'transit'. Keyword-based (Bulgarian + English) so it works across couriers - Speedy operation names now
     * come back in Bulgarian, and Econt/Pigeon/Sameday statuses are already Bulgarian.
     */
    /**
     * Statuses that are still in flight even though they contain a word that looks terminal. Every one of
     * these is a string a courier really sent us, not a hypothetical:
     *   "Awaiting delivery to Econt"   Econt, first event - the parcel is still on the merchant's desk
     *   "arranging the delivery"       Speedy 136
     *   "being prepared for handover"  Speedy 134
     *   "inspection/testing refused"   Speedy 195 - the customer declined to INSPECT it; this fires one
     *                                  second before the actual delivery, so reading the Bulgarian for
     *                                  "refusal" in it as a cancellation would end tracking right at the
     *                                  finish line. That word is the first entry in the list below.
     * @var string[]
     */
    private const IN_FLIGHT = [
        'отказ от преглед', 'очаква', 'awaiting', 'prepared',
        'за доставка', 'опит', 'for delivery', 'attempt',
        'недостав', 'not delivered', 'undelivered', 'unsuccessful', 'неуспешн',
    ];

    /**
     * Wordings that really do mean the receiver has the parcel. Couriers disagree on how to say it, and
     * none of them says it the way the others do:
     *   "delivery to the customer"    Speedy, operation -14
     *   "collected by the recipient"  Pigeon (its API sends no events at all - the text is all there is)
     *   "shipment delivered"          the participle, used by Speedy and Econt's Bulgarian feed
     * @var string[]
     */
    private const DELIVERED_PHRASES = ['доставка на клиент', 'взета от получателя',
        'получена от получателя', 'предадена на получателя'];

    /**
     * Wordings that mean the parcel has ARRIVED and is waiting for the customer to collect it - the step
     * between "on its way" and "handed over". Real strings: Speedy notifies the recipient that the parcel
     * is at the office/locker (operation 1134) after preparing it for handover to the customer at the
     * office (134).
     * @var string[]
     */
    private const READY_PHRASES = ['известие за пратка', 'готова за получаване', 'готова за взимане',
        'подготовка за предаване', 'ready for pickup', 'ready for collection', 'available for pickup'];

    /** Speedy operations that mean the same thing, checked before any text. @var string[] */
    public const READY_CODES = ['134', '1134'];

    /**
     * Does this status line mean the shipment is cancelled?
     *
     * Asked by the couriers' own is_cancelled() as well as by the orders list, and that is the point.
     * Each courier used to keep its own handful of words - Sameday looked for the Bulgarian for
     * "cancelled" and for "cancel", Pigeon also for "refusal" - so the same shipment could read Cancelled
     * in the orders column and NOT cancelled to the code deciding whether a dead waybill may be cleared.
     * Sameday's own "collection from the sender refused" fell in exactly that gap, on a live order
     * (2026-08-26).
     */
    public static function reads_cancelled(string $status): bool {
        return self::classify($status) === 'cancelled';
    }

    public static function classify(string $status): string {
        $s = function_exists('mb_strtolower') ? mb_strtolower($status) : strtolower($status);
        // Guards first: these phrases contain words the rules below would otherwise match.
        foreach (self::IN_FLIGHT as $k) { if (strpos($s, $k) !== false) { return 'transit'; } }
        foreach (['отказ', 'анулир', 'cancel'] as $k) { if (strpos($s, $k) !== false) { return 'cancelled'; } }
        // A return has two distinct moments and they are NOT the same thing to a shop: the parcel is on
        // its way back (Speedy 111, "returning to the sender"), and the parcel is BACK - handed over to
        // the sender (124, "handed back to the sender"). Calling the second one "coming back" told a
        // merchant a box was still travelling when it was already sitting on their counter.
        foreach (['обратно на подател', 'обратно към подател', 'върната пратка', 'предадена на подателя',
                  'returned to sender', 'back with sender'] as $k) {
            if (strpos($s, $k) !== false) { return 'returned'; }
        }
        foreach (['върн', 'връщ', 'return', 'към подателя', 'back to sender'] as $k) {
            if (strpos($s, $k) !== false) { return 'returning'; }
        }
        foreach (self::DELIVERED_PHRASES as $k) { if (strpos($s, $k) !== false) { return 'delivered'; } }
        // The Bulgarian participle ("delivered") and the English one, never the noun. A substring test
        // for the stem alone also matched the noun "delivery" in both languages, which is how an order was
        // completed while its parcel had not left the shop.
        if (preg_match('/достав(ен|ена|ено|ени)/u', $s) === 1) { return 'delivered'; }
        if (preg_match('/\bdelivered\b/', $s) === 1) { return 'delivered'; }
        // Arrived and waiting to be collected - checked after the terminal verdicts so a delivered or
        // cancelled parcel can never be reported as merely waiting.
        foreach (self::READY_PHRASES as $k) { if (strpos($s, $k) !== false) { return 'ready'; } }
        return 'transit';
    }

    /**
     * Has the courier physically taken the parcel? Uses the courier's own answer where there is one, and
     * otherwise falls back to "the history has grown past the single registration event".
     */
    private function collected(): bool {
        if ($this->handover !== null) { return $this->handover; }
        return count($this->events) > 1;
    }

    /**
     * What to call a stage in the admin. The raw verdicts are internal; these are what the merchant reads
     * on the order and in the orders list.
     *
     * @param string $stage One of registered|transit|ready|delivered|returning|returned|cancelled.
     * @return string Translated label.
     */
    public static function stage_label(string $stage): string {
        switch ($stage) {
            case 'registered': return __('Label created', 'bg-couriers');
            case 'returning': return __('On its way back', 'bg-couriers');
            case 'ready':     return __('Ready for collection', 'bg-couriers');
            case 'delivered': return __('Delivered', 'bg-couriers');
            case 'returned':  return __('Back with you', 'bg-couriers');
            case 'cancelled': return __('Cancelled', 'bg-couriers');
            default:          return __('On its way', 'bg-couriers');
        }
    }
}
