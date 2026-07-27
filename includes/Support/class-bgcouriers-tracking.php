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
        // 'достав' is also inside phrasings that mean the parcel is still MOVING - "Предадена за доставка",
        // "Опит за доставка", "Готова за доставка", "out for delivery". Reading those as delivered would set
        // _bgcouriers_track_done for good (the poller never clears it) and, with auto-status on, complete the order
        // on a failed delivery attempt. The delivered forms are the participle ("Доставена"), never "за
        // доставка", so guard the noun phrasings out first.
        foreach (['за доставка', 'опит', 'for delivery', 'attempt'] as $k) {
            if (strpos($s, $k) !== false) { return 'transit'; }
        }
        foreach (['достав', 'deliver'] as $k) { if (strpos($s, $k) !== false) { return 'delivered'; } }
        return 'transit';
    }
}
