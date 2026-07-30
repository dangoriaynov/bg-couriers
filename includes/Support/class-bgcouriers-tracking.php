<?php
defined('ABSPATH') || exit;

class BGCouriers_Tracking {
    /** Speedy trackPhase values that really do end a shipment. @var array<string,string> */
    private const PHASES = [
        'DELIVERED'                => 'delivered',
        'RETURN_TO_SENDER'         => 'returned',
        'DELIVERED_BACK_TO_SENDER' => 'returned',
    ];

    public string $waybill;
    public string $status;
    public array $events;
    /** Courier-supplied lifecycle phase, when the API has one ('' for couriers that don't). */
    public string $phase;

    public function __construct(string $waybill, string $status, array $events = [], string $phase = '') {
        $this->waybill = $waybill;
        $this->status  = $status;
        $this->events  = $events;
        $this->phase   = $phase;
    }

    /** A human-readable status: the last event's name if available, else the raw status (a code for Speedy). */
    public function human(): string {
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
        if ($this->phase === '') { return self::classify($this->human()); }
        if (isset(self::PHASES[$this->phase])) { return self::PHASES[$this->phase]; }
        return self::classify($this->human()) === 'cancelled' ? 'cancelled' : 'transit';
    }

    /**
     * Normalise a courier status string to a lifecycle stage: 'delivered' / 'cancelled' / 'returned' /
     * 'transit'. Keyword-based (Bulgarian + English) so it works across couriers - Speedy operation names now
     * come back in Bulgarian, and Econt/Pigeon/Sameday statuses are already Bulgarian.
     */
    public static function classify(string $status): string {
        $s = function_exists('mb_strtolower') ? mb_strtolower($status) : strtolower($status);
        foreach (['отказ', 'анулир', 'cancel'] as $k) { if (strpos($s, $k) !== false) { return 'cancelled'; } }
        foreach (['върн', 'връщ', 'return'] as $k) { if (strpos($s, $k) !== false) { return 'returned'; } }
        // Explicit negations, before anything else can match the positive form.
        foreach (['недостав', 'not delivered', 'undelivered', 'unsuccessful', 'неуспешн'] as $k) {
            if (strpos($s, $k) !== false) { return 'transit'; }
        }
        // ONLY the participle means delivered: "Доставена пратка", "Доставено", "Delivered to office".
        // Every courier also emits statuses where delivery is a NOUN and the parcel has not been handed
        // over yet or is still moving - Econt's very first event is literally "Awaiting delivery to
        // Econt" / "Очаква предаване към Еконт", and there are "Предадена за доставка", "Опит за
        // доставка", "out for delivery". A substring test for 'достав'/'deliver' matched all of those:
        // that is what completed a freshly created order and set _bgcouriers_track_done (which is never
        // cleared, so the parcel was never polled again). Match the participle, never the noun.
        if (preg_match('/достав(ен|ена|ено|ени)/u', $s) === 1) { return 'delivered'; }
        if (preg_match('/\bdelivered\b/', $s) === 1) { return 'delivered'; }
        return 'transit';
    }
}
