<?php
defined('ABSPATH') || exit;

class BGCouriers_Tracking {
    /** @var array<string,string> Courier lifecycle phase -> our stage; only phases the text rules would get wrong. Unknown falls to 'transit'. */
    private const PHASES = [
        // Speedy trackPhase values that end a shipment.
        'DELIVERED'                => 'delivered',
        // Sameday's expeditionSummary flags.
        'CANCELLED'                => 'cancelled',
        'RETURN_TO_SENDER'         => 'returned',
        'DELIVERED_BACK_TO_SENDER' => 'returned',
        // BOX NOW parcelState (webhook and /parcels), prefixed so a plain word from another courier cannot land here.
        'boxnow_new'                  => 'registered',
        'boxnow_in-transit'           => 'transit',
        'boxnow_in-final-destination' => 'ready',
        'boxnow_delivered'            => 'delivered',
        'boxnow_returned'             => 'returned',
        'boxnow_expired-return'       => 'returned',
        'boxnow_canceled'             => 'cancelled',
        // BOX NOW webhook event vocabulary.
        'boxnow_final-destination'    => 'ready',
        'boxnow_expired'              => 'returned',
        'boxnow_in-depot'             => 'transit',
        'boxnow_accepted-to-locker'   => 'transit',
        'boxnow_accepted-for-return'  => 'returning',
        'boxnow_cancelled'            => 'cancelled',
        // Pigeon: label exists, parcel not yet collected; its wording reads as movement to the text rules.
        'shipment_registered'              => 'registered',
        'shipment_awaiting_courier_pickup' => 'registered',
        // Pigeon: at the pickup point; its wording reads as delivered to the text rules.
        'shipment_delivered_to_office'  => 'ready',
        'shipment_left_in_locker'       => 'ready',
        'shipment_held_by_sender'       => 'ready',
        // Pigeon: nobody came; non-terminal so it can turn into a return.
        'shipment_untracked'            => 'unclaimed',
        'shipment_locker_time_expired'  => 'unclaimed',
        'shipment_storage_expired'      => 'unclaimed',
        'shipment_delivered_to_recipient' => 'delivered',
        'shipment_cancelled'              => 'cancelled',
        'shipment_returning_to_sender'    => 'returning',
        'shipment_returned'               => 'returned',
        // Express One's are numbers, prefixed with the courier.
        'expressone_0'  => 'registered', // waybill created
        'expressone_1'  => 'registered',   // order created
        'expressone_2'  => 'transit',      // accepted by the courier
        'expressone_3'  => 'ready', // arrived at an office
        'expressone_5'  => 'transit',      // handed to a courier
        'expressone_6'  => 'delivered',    // delivered
        'expressone_7'  => 'cancelled',    // cancelled
        'expressone_8'  => 'transit', // failed delivery attempt
        'expressone_12' => 'returned',     // returned to the sender
        // Evropat's are numbers too (the adapter resolves the status name to an id); only phases the text rules would get wrong.
        'evropat_1'  => 'registered', // created
        'evropat_2'  => 'registered', // printed
        'evropat_64' => 'ready',        // assigned to an office
        'evropat_82' => 'unclaimed', // unclaimed
        'evropat_86' => 'ready', // arrived at an office/depot
        'evropat_19' => 'delivered',    // delivered on the round
        'evropat_83' => 'returning',    // on its way back to the sender
        'evropat_10' => 'returned',     // returned to the sender
        'evropat_18' => 'cancelled',    // cancelled
        // A refusal is not a cancellation: the parcel is still out and on its way back.
        'evropat_6'  => 'transit',      // refused
        'evropat_68' => 'transit',      // refused without payment
        'evropat_9'  => 'transit',      // cannot pay the delivery charge, the COD or the PPP
        // 10 (accounting close) is deliberately absent: it says nothing about where the parcel is.
    ];

    public static function phase_stage(string $phase): string {
        return self::PHASES[$phase] ?? '';
    }

    public string $waybill;
    public string $status;
    public array $events;
    public string $phase;
    /** true/false when the API says outright, null when the caller must guess from the history. */
    public ?bool $handover;
    /** Whether `status` is a readable sentence; Speedy's is an operation code, Econt's events are place names. */
    public bool $status_is_human;

    public function __construct(string $waybill, string $status, array $events = [], string $phase = '', ?bool $handover = null, bool $status_is_human = false) {
        $this->waybill         = $waybill;
        $this->status          = $status;
        $this->events          = $events;
        $this->phase           = $phase;
        $this->handover        = $handover;
        $this->status_is_human = $status_is_human;
    }

    public function human(): string {
        if ($this->status_is_human && trim($this->status) !== '') { return $this->status; }
        if (!empty($this->events)) {
            $last = end($this->events);
            $name = is_array($last) ? (string) ($last['name'] ?? '') : '';
            if ($name !== '') { return $name; }
        }
        return $this->status;
    }

    /** Lifecycle stage: the courier's phase when present, else the status text (Speedy does not always send trackPhase). */
    public function stage(): string {
        if ($this->phase === '') {
            $verdict = self::classify($this->human());
            if ($verdict === 'transit') {
                // Speedy's 134/1134 mean waiting at the office however worded; checked first.
                $last = $this->events ? end($this->events) : [];
                if (in_array((string) ($last['code'] ?? ''), self::READY_CODES, true)) { return 'ready'; }
                // Nothing has moved yet: a waybill only hands the courier the data.
                if (!$this->collected()) { return 'registered'; }
            }
            return $verdict;
        }
        $known = self::phase_stage($this->phase);
        if ($known !== '') { return $known; }
        return self::classify($this->human()) === 'cancelled' ? 'cancelled' : 'transit';
    }

    /** @var string[] Still in flight despite a terminal-looking word: Econt's first event, Speedy 134/136/195 (inspection refused fires just before delivery). */
    private const IN_FLIGHT = [
        'отказ от преглед', 'очаква', 'awaiting', 'prepared',
        'за доставка', 'опит', 'for delivery', 'attempt',
        'недостав', 'not delivered', 'undelivered', 'unsuccessful', 'неуспешн',
    ];

    /** @var string[] The receiver has the parcel: Speedy -14, Pigeon "collected by the recipient", the participle "shipment delivered". */
    private const DELIVERED_PHRASES = ['доставка на клиент', 'взета от получателя',
        'получена от получателя', 'предадена на получателя'];

    /** @var string[] Arrived and waiting for the customer: Speedy 1134 / 134. */
    private const READY_PHRASES = ['известие за пратка', 'готова за получаване', 'готова за взимане',
        'подготовка за предаване', 'ready for pickup', 'ready for collection', 'available for pickup',
        // Sameday locker: "loaded into the OOH" is when the parcel starts waiting.
        'заредена в ooh'];

    /** Speedy operations that mean the same thing, checked before any text. @var string[] */
    public const READY_CODES = ['134', '1134'];

    /** @var string[] Arrived, waited, nobody came: Pigeon and Evropat wording; no delivered/cancelled word inside. */
    private const UNCLAIMED_PHRASES = ['непотърсен', 'изтекъл срок на съхранение', 'изтекъл престой',
        'unclaimed', 'not collected in time'];

    /** Sameday says the same thing with the stems the other way round. */
    private const UNCLAIMED_PATTERN = '/изтек\S*\s[^.]*съхран|съхран[^.]*изтек/u';

    /** Is this status line a cancellation? Shared by the couriers' is_cancelled() and the orders list so they agree. */
    public static function reads_cancelled(string $status): bool {
        return self::classify($status) === 'cancelled';
    }

    public static function classify(string $status): string {
        $s = function_exists('mb_strtolower') ? mb_strtolower($status) : strtolower($status);
        // Guards first: these phrases contain words the rules below would otherwise match.
        foreach (self::IN_FLIGHT as $k) { if (strpos($s, $k) !== false) { return 'transit'; } }
        foreach (['отказ', 'анулир', 'cancel'] as $k) { if (strpos($s, $k) !== false) { return 'cancelled'; } }
        // Returning (Speedy 111) and returned (Speedy 124, Sameday "Върната на подател") are different news to a shop.
        foreach (['обратно на подател', 'обратно към подател', 'върната пратка', 'предадена на подателя',
                  'върната на подател', 'returned to sender', 'back with sender'] as $k) {
            if (strpos($s, $k) !== false) { return 'returned'; }
        }
        foreach (['върн', 'връщ', 'return', 'към подателя', 'back to sender'] as $k) {
            if (strpos($s, $k) !== false) { return 'returning'; }
        }
        foreach (self::DELIVERED_PHRASES as $k) { if (strpos($s, $k) !== false) { return 'delivered'; } }
        // The participle only, never the noun: the stem alone matched "delivery".
        if (preg_match('/достав(ен|ена|ено|ени)/u', $s) === 1) { return 'delivered'; }
        if (preg_match('/\bdelivered\b/', $s) === 1) { return 'delivered'; }
        // Before the plain waiting rules: an expired parcel is still in the same office.
        foreach (self::UNCLAIMED_PHRASES as $k) { if (strpos($s, $k) !== false) { return 'unclaimed'; } }
        if (preg_match(self::UNCLAIMED_PATTERN, $s) === 1) { return 'unclaimed'; }
        // After the terminal verdicts so a delivered or cancelled parcel is never merely waiting.
        foreach (self::READY_PHRASES as $k) { if (strpos($s, $k) !== false) { return 'ready'; } }
        return 'transit';
    }

    /** The courier's own answer where there is one, else whether the history grew past the single registration event. */
    private function collected(): bool {
        if ($this->handover !== null) { return $this->handover; }
        return count($this->events) > 1;
    }

    private static function fold(string $s): string {
        $s = trim($s);
        $s = function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
        return trim($s, " \t\n\r\0\x0B.!?:;,-");
    }

    /**
     * [headline, courier's own words or '']; the words go when they repeat the stage label (either contains the other).
     * @return array{0:string,1:string}
     */
    public static function stage_headline(string $stage, string $human): array {
        $label = self::stage_label($stage);
        $human = trim($human);
        // "UNKNOWN" was written by older polls; it is not a status, so no screen prints it.
        if ($human === '' || strcasecmp($human, 'UNKNOWN') === 0) { return [$label, '']; }
        $h   = self::fold($human);
        $len = static function (string $s): int { return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s); };
        foreach ([$label, self::stage_label($stage), self::stage_label_short($stage)] as $cand) {
            $c = self::fold($cand);
            if ($c === '') { continue; }
            if ($h === $c) { return [$label, '']; }
            // Only whole words of some length count as repetition, never a two-letter fragment.
            if ($len($c) >= 4 && strpos($h, $c) !== false) { return [$human, '']; }
            if ($len($h) >= 4 && strpos($c, $h) !== false) { return [$label, '']; }
        }
        return [$label, $human];
    }

    /** Order note for a status change; the quote goes when the courier's words already say the stage. */
    public static function status_note(string $courier_label, string $stage, string $human): string {
        [$headline, $detail] = self::stage_headline($stage, $human);
        if ($detail === '') {
            /* translators: 1: courier name, 2: what stage the shipment is at */
            return sprintf(__('%1$s - %2$s', 'bg-couriers'), $courier_label, $headline);
        }
        /* translators: 1: courier name, 2: what stage the shipment is at, 3: the courier's own wording */
        return sprintf(__('%1$s - %2$s: "%3$s"', 'bg-couriers'), $courier_label, $headline, $detail);
    }

    /** Event time as unix timestamp, 0 when unreadable; Econt sends milliseconds, recognised by size. */
    public static function event_time(array $event): int {
        $raw = trim((string) ($event['date'] ?? ''));
        if ($raw === '') { return 0; }
        if (ctype_digit($raw)) {
            $n = (int) $raw;
            return $n > 100000000000 ? (int) round($n / 1000) : $n;
        }
        $ts = strtotime($raw);
        return $ts ? (int) $ts : 0;
    }

    public const STAGE_ORDER = ['registered', 'transit', 'ready', 'unclaimed', 'delivered', 'returning', 'returned', 'cancelled'];

    /** Stages after which nothing changes; 'returning' and 'unclaimed' are deliberately absent. */
    public const FINAL_STAGES = ['delivered', 'cancelled', 'returned'];

    public static function is_final(string $stage): bool {
        return in_array($stage, self::FINAL_STAGES, true);
    }

    /** One-word stage label; separate msgids because the short Bulgarian is not always a shortening of the long one. */
    public static function stage_label_short(string $stage): string {
        switch ($stage) {
            case 'registered': return _x('Created', 'short stage label', 'bg-couriers');
            case 'ready':      return _x('Ready', 'short stage label', 'bg-couriers');
            case 'unclaimed':  return _x('Not collected', 'short stage label', 'bg-couriers');
            case 'delivered':  return _x('Delivered', 'short stage label', 'bg-couriers');
            case 'returning':  return _x('Returning', 'short stage label', 'bg-couriers');
            case 'returned':   return _x('Returned', 'short stage label', 'bg-couriers');
            case 'cancelled':  return _x('Cancelled', 'short stage label', 'bg-couriers');
            default:           return _x('Travelling', 'short stage label', 'bg-couriers');
        }
    }

    /** One-line hover hint (stage, courier's words, time standing still), built here so both screens agree. */
    public static function stage_tip(string $stage, string $human, int $updated = 0): string {
        [$headline, $detail] = self::stage_headline($stage, $human);
        $tip = $headline . ($detail !== '' ? ' - ' . $detail : '');
        if ($updated > 0) {
            /* translators: %s: human-readable time difference, e.g. "2 hours" */
            $tip .= ' - ' . sprintf(__('unchanged for %s', 'bg-couriers'), human_time_diff($updated, time()));
        }
        return $tip;
    }

    public static function stage_label(string $stage): string {
        switch ($stage) {
            case 'registered': return __('Label created', 'bg-couriers');
            case 'returning': return __('On its way back', 'bg-couriers');
            case 'ready':     return __('Ready for collection', 'bg-couriers');
            case 'unclaimed': return __('Not collected', 'bg-couriers');
            case 'delivered': return __('Delivered', 'bg-couriers');
            case 'returned':  return __('Back with you', 'bg-couriers');
            case 'cancelled': return __('Cancelled', 'bg-couriers');
            default:          return __('On its way', 'bg-couriers');
        }
    }
}
