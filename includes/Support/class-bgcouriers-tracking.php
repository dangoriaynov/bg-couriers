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
    /**
     * Has the courier physically taken the parcel? true/false when the API answers that outright
     * (Econt's sendTime, Speedy's acceptance operations), null when it does not and the caller has to
     * guess from the event history.
     */
    public ?bool $handover;

    public function __construct(string $waybill, string $status, array $events = [], string $phase = '', ?bool $handover = null) {
        $this->waybill  = $waybill;
        $this->status   = $status;
        $this->events   = $events;
        $this->phase    = $phase;
        $this->handover = $handover;
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
        if ($this->phase === '') {
            $verdict = self::classify($this->human());
            // The last event's code is a machine value where the courier publishes one; Speedy's 134/1134
            // mean "waiting at the office/locker" regardless of how the description is worded.
            if ($verdict === 'transit' && $this->events) {
                $last = end($this->events);
                if (in_array((string) ($last['code'] ?? ''), self::READY_CODES, true)) { return 'ready'; }
            }
            return $verdict;
        }
        if (isset(self::PHASES[$this->phase])) { return self::PHASES[$this->phase]; }
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
     *   "Awaiting delivery to Econt"        Econt, first event - the parcel is still on the merchant's desk
     *   "Уточняване на доставка"            Speedy 136 - arranging the delivery
     *   "Подготовка за предаване на клиент" Speedy 134 - being prepared for handover
     *   "Отказ от преглед/тестване"         Speedy 195 - the customer declined to INSPECT it; this fires
     *                                       one second before the actual delivery, so reading its "отказ"
     *                                       as a cancellation would end tracking right at the finish line
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
     *   "Доставка на клиент"       Speedy, operation -14
     *   "Взета от получателя"      Pigeon (its API sends no events at all - the text is all there is)
     *   "Доставена пратка"         the participle, used by Speedy and Econt's Bulgarian feed
     * @var string[]
     */
    private const DELIVERED_PHRASES = ['доставка на клиент', 'взета от получателя',
        'получена от получателя', 'предадена на получателя'];

    /**
     * Wordings that mean the parcel has ARRIVED and is waiting for the customer to collect it - the step
     * between "on its way" and "handed over". Real strings: Speedy notifies the recipient with
     * "Изпратено известие за пратка в офис/автомат" (operation 1134) after "Подготовка за предаване на
     * клиент в офис" (134).
     * @var string[]
     */
    private const READY_PHRASES = ['известие за пратка', 'готова за получаване', 'готова за взимане',
        'подготовка за предаване', 'ready for pickup', 'ready for collection', 'available for pickup'];

    /** Speedy operations that mean the same thing, checked before any text. @var string[] */
    public const READY_CODES = ['134', '1134'];

    public static function classify(string $status): string {
        $s = function_exists('mb_strtolower') ? mb_strtolower($status) : strtolower($status);
        // Guards first: these phrases contain words the rules below would otherwise match.
        foreach (self::IN_FLIGHT as $k) { if (strpos($s, $k) !== false) { return 'transit'; } }
        foreach (['отказ', 'анулир', 'cancel'] as $k) { if (strpos($s, $k) !== false) { return 'cancelled'; } }
        // Speedy says a return two ways: "Връщане към подателя" (111) and "Предаване обратно на подател"
        // (124). Only the first matched, so a parcel coming back read as still travelling to the customer.
        foreach (['върн', 'връщ', 'return', 'обратно на подател', 'обратно към подател',
                  'към подателя', 'back to sender'] as $k) {
            if (strpos($s, $k) !== false) { return 'returned'; }
        }
        foreach (self::DELIVERED_PHRASES as $k) { if (strpos($s, $k) !== false) { return 'delivered'; } }
        // The participle - "Доставена", "Доставено", "Delivered to office" - never the noun. A substring
        // test for 'достав'/'deliver' also matched "delivery"/"доставка", which is how an order was
        // completed while its parcel had not left the shop.
        if (preg_match('/достав(ен|ена|ено|ени)/u', $s) === 1) { return 'delivered'; }
        if (preg_match('/\bdelivered\b/', $s) === 1) { return 'delivered'; }
        // Arrived and waiting to be collected - checked after the terminal verdicts so a delivered or
        // cancelled parcel can never be reported as merely waiting.
        foreach (self::READY_PHRASES as $k) { if (strpos($s, $k) !== false) { return 'ready'; } }
        return 'transit';
    }

    /**
     * What to call a stage in the admin. The raw verdicts are internal; these are what the merchant reads
     * on the order and in the orders list.
     *
     * @param string $stage One of transit|ready|delivered|returned|cancelled.
     * @return string Translated label.
     */
    public static function stage_label(string $stage): string {
        switch ($stage) {
            case 'ready':     return __('Ready for collection', 'bg-couriers');
            case 'delivered': return __('Delivered', 'bg-couriers');
            case 'returned':  return __('Being returned', 'bg-couriers');
            case 'cancelled': return __('Cancelled', 'bg-couriers');
            default:          return __('On its way', 'bg-couriers');
        }
    }
}
