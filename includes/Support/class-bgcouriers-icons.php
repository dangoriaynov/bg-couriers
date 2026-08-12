<?php
defined('ABSPATH') || exit;

/**
 * One shared set of delivery-type glyphs (office / address / automat) so the SAME icon is used everywhere:
 * icon-only (with a hover tooltip) in the admin order panel and Orders list, and icon + text label at
 * checkout / cart. Inline SVG (currentColor) so it renders identically in admin and on the storefront.
 */
class BGCouriers_Icons {
    private const PATHS = [
        'office'  => '<path d="M3 9l1-5h16l1 5"/><path d="M4 9v11h16V9"/><path d="M9 20v-6h6v6"/>',
        'address' => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
        'automat' => '<rect x="4" y="3" width="16" height="18" rx="1"/><line x1="4" y1="9" x2="20" y2="9"/><line x1="4" y1="15" x2="20" y2="15"/><line x1="12" y1="3" x2="12" y2="21"/>',
    ];

    /**
     * One glyph per shipment stage, drawn in the same line style as the delivery types above.
     *
     * Each has to be recognisable at 15px and, more importantly, tellable APART from the others at a
     * glance - the whole point is that a merchant scans a column of them instead of reading a sentence
     * per row. So the pairs that mean similar things are drawn as opposites: the parcel arrives (an
     * arrow down into a tray) versus it comes back (an arrow travelling left), delivered is a tick and
     * cancelled a cross in the same circle.
     *
     * @var array<string,string>
     */
    private const STAGE_PATHS = [
        // A printed label and nothing more: the courier has the data, the parcel is still on the desk.
        'registered' => '<rect x="3" y="6" width="18" height="12" rx="2"/><line x1="7" y1="10" x2="7" y2="14"/>'
                      . '<line x1="10" y1="10" x2="10" y2="14"/><line x1="13" y1="10" x2="13" y2="14"/><line x1="16" y1="10" x2="16" y2="14"/>',
        // On the road.
        'transit'    => '<rect x="1" y="6" width="13" height="10" rx="1"/><path d="M14 9h4l3 3v4h-7z"/>'
                      . '<circle cx="6" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
        // Arrived at the office or locker and waiting for the customer: dropped INTO something.
        'ready'      => '<path d="M4 13v5a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-5"/><path d="M12 3v10"/><path d="M8 9l4 4 4-4"/>',
        'delivered'  => '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l3 3 5-6"/>',
        // Travelling the other way - the U-turn is still a road.
        'returning'  => '<path d="M9 15l-5-5 5-5"/><path d="M4 10h10a5 5 0 0 1 0 10h-4"/>',
        // All the way back: the arrow has reached the wall it started from.
        'returned'   => '<path d="M21 12H8"/><path d="M13 7l-5 5 5 5"/><path d="M4 5v14"/>',
        'cancelled'  => '<circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6"/><path d="M9 9l6 6"/>',
    ];

    /** Inline SVG for a delivery method (office/address/automat); '' for anything else. */
    public static function method(string $m, int $size = 16): string {
        if (!isset(self::PATHS[$m])) { return ''; }
        return self::svg('bgc-mtype-ico', self::PATHS[$m], $size);
    }

    /** Inline SVG for a shipment stage (registered/transit/ready/...); '' for anything else. */
    public static function stage(string $stage, int $size = 15): string {
        if (!isset(self::STAGE_PATHS[$stage])) { return ''; }
        return self::svg('bgc-stage-ico', self::STAGE_PATHS[$stage], $size);
    }

    /** The one SVG wrapper both sets use, so a change to the drawing style lands on every glyph. */
    private static function svg(string $class, string $paths, int $size): string {
        return '<svg class="' . esc_attr($class) . '" viewBox="0 0 24 24" width="' . (int) $size . '" height="' . (int) $size
            . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . $paths . '</svg>';
    }

    /** Human label for a delivery method. */
    public static function method_label(string $m): string {
        switch ($m) {
            case 'address': return __('To address', 'bg-couriers');
            case 'automat': return __('To APS', 'bg-couriers');
            case 'office':  return __('To office', 'bg-couriers');
        }
        return $m;
    }

    /** method => inline SVG, for handing the icons to JS (checkout tabs). */
    public static function map(): array {
        $out = [];
        foreach (array_keys(self::PATHS) as $m) { $out[$m] = self::method($m); }
        return $out;
    }
}
