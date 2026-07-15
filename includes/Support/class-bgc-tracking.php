<?php
defined('ABSPATH') || exit;

class BGC_Tracking {
    public string $waybill;
    public string $status;
    public array $events;

    public function __construct(string $waybill, string $status, array $events = []) {
        $this->waybill = $waybill;
        $this->status  = $status;
        $this->events  = $events;
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
     * Normalise a courier status string to a lifecycle stage: 'delivered' / 'cancelled' / 'returned' /
     * 'transit'. Keyword-based (Bulgarian + English) so it works across couriers - Speedy operation names now
     * come back in Bulgarian, and Econt/Pigeon/Sameday statuses are already Bulgarian.
     */
    public static function classify(string $status): string {
        $s = function_exists('mb_strtolower') ? mb_strtolower($status) : strtolower($status);
        foreach (['отказ', 'анулир', 'cancel'] as $k) { if (strpos($s, $k) !== false) { return 'cancelled'; } }
        foreach (['върн', 'връщ', 'return'] as $k) { if (strpos($s, $k) !== false) { return 'returned'; } }
        foreach (['достав', 'deliver'] as $k) { if (strpos($s, $k) !== false) { return 'delivered'; } }
        return 'transit';
    }
}
