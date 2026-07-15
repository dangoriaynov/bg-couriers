<?php
defined('ABSPATH') || exit;

/**
 * One shared set of delivery-type glyphs (office / address / automat) so the SAME icon is used everywhere:
 * icon-only (with a hover tooltip) in the admin order panel and Orders list, and icon + text label at
 * checkout / cart. Inline SVG (currentColor) so it renders identically in admin and on the storefront.
 */
class BGC_Icons {
    private const PATHS = [
        'office'  => '<path d="M3 9l1-5h16l1 5"/><path d="M4 9v11h16V9"/><path d="M9 20v-6h6v6"/>',
        'address' => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
        'automat' => '<rect x="4" y="3" width="16" height="18" rx="1"/><line x1="4" y1="9" x2="20" y2="9"/><line x1="4" y1="15" x2="20" y2="15"/><line x1="12" y1="3" x2="12" y2="21"/>',
    ];

    /** Inline SVG for a delivery method (office/address/automat); '' for anything else. */
    public static function method(string $m, int $size = 16): string {
        if (!isset(self::PATHS[$m])) { return ''; }
        return '<svg class="bgc-mtype-ico" viewBox="0 0 24 24" width="' . (int) $size . '" height="' . (int) $size
            . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . self::PATHS[$m] . '</svg>';
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
