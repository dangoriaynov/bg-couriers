<?php
/**
 * The shipment panel is SCANNED, not read: one word for the stage, a clock and a duration for the age,
 * and the dates of the journey in a column that lines up. It did not start that way - the row read
 * "Непотърсена Непотърсена без промяна от 2 седмици" on a Bulgarian shop (the stage, the courier saying
 * the same thing, and a sentence about the clock), and the timeline's dates each began wherever the
 * stage before them had ended (owner, screenshots, 2026-10-09).
 *
 * Rendered through the real metabox, because what reaches the page goes through wp_kses() - and that is
 * where the <time> element had been dropped all along.
 *
 * @group core
 */
final class PanelStateRowTest extends WP_UnitTestCase {
    public function set_up() {
        parent::set_up();
        BGCouriers_Couriers::register('stateprobe', 'Probe', static function () { return new BGCouriers_State_Probe(); });
    }
    public function tear_down() {
        foreach (['defs', 'built'] as $prop) {
            $r = new ReflectionProperty('BGCouriers_Couriers', $prop);
            $r->setAccessible(true);
            $v = $r->getValue(); unset($v['stateprobe']); $r->setValue(null, $v);
        }
        parent::tear_down();
    }

    private function panel(WC_Order $o): string {
        ob_start();
        (new BGCouriers_Order_Metabox())->render($o);
        return (string) ob_get_clean();
    }

    private function order(array $meta): WC_Order {
        $o = new WC_Order();
        $o->update_meta_data('_bgcouriers_courier', 'stateprobe');
        $o->update_meta_data('_bgcouriers_method', 'office');
        $o->update_meta_data('_bgcouriers_waybill', '63740000100');
        foreach ($meta as $k => $v) { $o->update_meta_data($k, $v); }
        $o->save();
        return $o;
    }

    /** One word, once - and everything it replaced on the hint. */
    public function test_the_state_row_says_the_stage_once_and_briefly(): void {
        $html = $this->panel($this->order([
            '_bgcouriers_track_stage'   => 'unclaimed',
            '_bgcouriers_track_text'    => 'Непотърсена',
            '_bgcouriers_track_updated' => time() - 14 * DAY_IN_SECONDS,
        ]));
        $this->assertStringContainsString('bgc-shipstate', $html, 'the row rendered at all');
        // What is PRINTED, not what is in the attributes: the hints repeat the stage on purpose.
        $shown = trim(wp_strip_all_tags($html));
        $this->assertSame(1, substr_count($shown, BGCouriers_Tracking::stage_label_short('unclaimed')),
            'the stage word is printed once, not once for us and once for the courier');
        $this->assertStringNotContainsString('unchanged for', $shown, 'the sentence about the clock is a hint, not a line');
        $this->assertStringContainsString('bgc-clock-ico', $html, 'the clock replaces it');
        $this->assertStringContainsString('2 weeks', $shown, 'and the duration itself is still shown');
        // The hint carries what the row no longer prints.
        $this->assertMatchesRegularExpression('/data-tip="[^"]*unchanged for 2 weeks[^"]*"/', $html);
    }

    /** A courier sentence that says MORE than the stage is not lost - it moves to the hint. */
    public function test_the_couriers_own_wording_is_on_the_hint(): void {
        $html = $this->panel($this->order([
            '_bgcouriers_track_stage'   => 'unclaimed',
            '_bgcouriers_track_text'    => 'Изтекъл срок на съхранение в офиса',
            '_bgcouriers_track_updated' => time() - 3 * DAY_IN_SECONDS,
        ]));
        $this->assertMatchesRegularExpression('/data-tip="[^"]*Изтекъл срок на съхранение в офиса[^"]*"/', $html,
            'the courier is quoted on the hint');
        $this->assertStringNotContainsString('>Изтекъл срок', $html, 'but not printed in the row');
    }

    /**
     * ONE indicator for a stage, wherever it is drawn. The order screen used to draw a coloured dot and
     * the orders list its glyph, so the same shipment looked like two different things depending on
     * which screen you were on (owner, 2026-10-09).
     */
    public function test_the_stage_icon_is_the_one_the_orders_list_draws(): void {
        $o = $this->order([
            '_bgcouriers_track_stage'   => 'unclaimed',
            '_bgcouriers_track_text'    => 'Непотърсена',
            '_bgcouriers_track_updated' => time() - DAY_IN_SECONDS,
        ]);
        $panel = $this->panel($o);
        $list  = BGCouriers_Order_Columns::status_html($o);
        // The drawing is compared by its path data, not by the whole element: the panel's markup goes
        // through wp_kses(), which lower-cases viewBox and re-spaces the self-closing tags.
        preg_match_all('/ d="([^"]+)"/', BGCouriers_Icons::stage('unclaimed'), $paths);
        $this->assertNotEmpty($paths[1], 'the stage has a glyph to compare');
        foreach ([$panel, $list] as $html) {
            $this->assertStringContainsString('bgc-track-ico bgc-stage-unclaimed', $html, 'same class, same colour rule');
            foreach ($paths[1] as $d) {
                $this->assertStringContainsString($d, $html, 'and the same drawing');
            }
        }
        $this->assertStringNotContainsString('bgc-track-dot', $panel, 'the dot is gone - one indicator, not two');
        // The colours both screens use come from one generator, and the panel now gets them too.
        $this->assertStringContainsString('.bgc-track-ico.bgc-stage-unclaimed{color:'
            . BGCouriers_Order_Columns::STAGE_COLORS['unclaimed'], BGCouriers_Order_Columns::stage_color_css());
    }

    /** The timeline: a stage column and a date column, and the dates survive kses as <time>. */
    public function test_the_timeline_is_a_grid_and_its_dates_are_time_elements(): void {
        $when = time() - 5 * DAY_IN_SECONDS;
        $html = $this->panel($this->order([
            '_bgcouriers_track_stage' => 'ready',
            '_bgcouriers_track_times' => ['registered' => $when - 86400, 'ready' => $when],
        ]));
        $this->assertSame(2, substr_count($html, 'bgc-tl-k'), 'one key cell per stage reached');
        $this->assertSame(2, substr_count($html, 'bgc-tl-v'), 'and one date cell beside each');
        $this->assertStringContainsString('<time class="bgc-tl-v" datetime="', $html,
            'the element itself reaches the page - kses used to drop it');
        $this->assertStringContainsString(gmdate('Y-m-d', $when), $html, 'with the machine-readable date on it');
        // Day and clock time are cells of their own, so the time starts at the same place on every
        // line however long the date beside it is.
        $this->assertSame(2, substr_count($html, 'bgc-tl-d'), 'a day cell per stage');
        $this->assertSame(2, substr_count($html, 'bgc-tl-t'), 'and a time cell beside it');
        $this->assertStringContainsString('<span class="bgc-tl-t">' . date_i18n('H:i', $when) . '</span>', $html);
        // Short labels here too: "Създадена товарителница 24 сеп." is the line this replaced.
        $this->assertStringContainsString('>' . BGCouriers_Tracking::stage_label_short('ready') . '<', $html);
    }

    /** The current stage is the title of a collapsible panel; the journey it opens is newest first. */
    public function test_the_stage_is_a_collapsible_title_over_the_journey_newest_first(): void {
        $t = time() - 3 * DAY_IN_SECONDS;
        $html = $this->panel($this->order([
            '_bgcouriers_track_stage' => 'delivered',
            '_bgcouriers_track_text'  => 'Доставена',
            '_bgcouriers_track_updated' => $t + 7200,
            '_bgcouriers_track_times' => ['registered' => $t, 'transit' => $t + 3600, 'delivered' => $t + 7200],
        ]));
        $this->assertMatchesRegularExpression('/<details class="bgc-shipstate [^"]*"><summary class="bgc-ss-head">/', $html);
        $this->assertStringNotContainsString('<details class="bgc-shipstate bgc-stage-delivered bgc-final" open', $html, 'collapsed by default');
        // The title: icon, name and the date of THIS stage, all inside the summary.
        preg_match('#<summary.*?</summary>#s', $html, $sum);
        $this->assertNotEmpty($sum);
        $this->assertStringContainsString('bgc-stage-delivered', $sum[0]);
        $this->assertStringContainsString(BGCouriers_Tracking::stage_label_short('delivered'), $sum[0]);
        $this->assertStringContainsString(date_i18n('j M', $t + 7200) . ' ' . date_i18n('H:i', $t + 7200), $sum[0]);
        // Inside: newest first.
        preg_match('#<div class="bgc-timeline">.*?</div>#s', $html, $tl);
        preg_match_all('#<span class="bgc-tl-k"[^>]*>.*?<span>([^<]+)</span></span>#s', $tl[0], $names);
        $this->assertSame([
            BGCouriers_Tracking::stage_label_short('delivered'),
            BGCouriers_Tracking::stage_label_short('transit'),
            BGCouriers_Tracking::stage_label_short('registered'),
        ], $names[1], 'reverse chronological order, as the stages changed');
    }

    /** A final stage prints no clock, no lock and no refresh button, and the hint has no "unchanged for". */
    public function test_a_final_stage_has_no_clock_no_refresh_and_no_lock(): void {
        foreach (['delivered', 'cancelled', 'returned'] as $stage) {
            $html = $this->panel($this->order([
                '_bgcouriers_track_stage'   => $stage,
                '_bgcouriers_track_text'    => 'x',
                '_bgcouriers_handover'      => 'yes',
                '_bgcouriers_track_updated' => time() - 3600,
                '_bgcouriers_track_times'   => [$stage => time() - 3600],
            ]));
            $this->assertStringContainsString('bgc-final', $html, $stage);
            $this->assertStringNotContainsString('bgc-ship-refresh', $html, "$stage: nothing to ask the courier");
            $this->assertStringNotContainsString('bgc-clock-ico', $html, "$stage: no age clock");
            $this->assertStringNotContainsString('bgc-shipstate-when', $html, $stage);
            $this->assertStringNotContainsString('bgc-lock dashicons', $html, "$stage: no padlock on the state row");
            $this->assertStringNotContainsString('unchanged for', $html, $stage);
        }
    }

    /** Unclaimed and returning are NOT final: the parcel can still move, so the clock and the button stay. */
    public function test_a_stage_that_can_still_change_keeps_the_clock_and_the_refresh_button(): void {
        foreach (['registered', 'transit', 'ready', 'unclaimed', 'returning'] as $stage) {
            $html = $this->panel($this->order([
                '_bgcouriers_track_stage'   => $stage,
                '_bgcouriers_track_text'    => 'x',
                '_bgcouriers_track_updated' => time() - 3600,
                '_bgcouriers_track_times'   => [$stage => time() - 3600],
            ]));
            $this->assertStringNotContainsString('bgc-final', $html, $stage);
            $this->assertStringContainsString('bgc-ship-refresh', $html, $stage);
            $this->assertStringContainsString('bgc-clock-ico', $html, $stage);
        }
    }

    /** With no dated history there is nothing to open: a plain title row, no <details>. */
    public function test_without_a_journey_the_title_is_not_collapsible(): void {
        $html = $this->panel($this->order([
            '_bgcouriers_track_stage' => 'transit', '_bgcouriers_track_text' => 'x', '_bgcouriers_track_updated' => time() - 60,
        ]));
        $this->assertStringContainsString('bgc-shipstate', $html);
        $this->assertStringNotContainsString('<details', $html);
        $this->assertStringContainsString('bgc-ship-refresh', $html);
    }

    /** A history of one step is the title again: nothing to open. */
    public function test_a_single_step_history_is_not_collapsible(): void {
        $html = $this->panel($this->order([
            '_bgcouriers_track_stage' => 'registered', '_bgcouriers_track_text' => 'x', '_bgcouriers_track_updated' => time() - 60,
            '_bgcouriers_track_times' => ['registered' => time() - 60],
        ]));
        $this->assertStringNotContainsString('<details', $html);
        $this->assertStringNotContainsString('bgc-timeline', $html);
        $this->assertSame(1, substr_count($html, 'bgc-ss-date'), 'the date is still on the title');
    }

    /** What the courier collects at the door is one short chip, not the sentence. */
    public function test_the_door_price_is_one_short_chip(): void {
        $o = new WC_Order();
        $o->update_meta_data('_bgcouriers_courier', 'stateprobe');
        $o->update_meta_data('_bgcouriers_method', 'office');
        $o->update_meta_data('_bgcouriers_waybill', '63740000100');
        $item = new WC_Order_Item_Shipping();
        $item->set_method_id('bgcouriers_stateprobe');
        $item->set_total(0);
        $item->update_meta_data('_bgcouriers_info_price', 3.0);
        $o->add_item($item);
        $o->save();
        $html = $this->panel($o);
        preg_match('#<div class="bgc-door-price".*?</div>#s', $html, $m);
        $this->assertNotEmpty($m, 'the chip is there');
        $shown = trim(wp_strip_all_tags($m[0]));
        $this->assertStringContainsString('~', $shown);
        $this->assertStringContainsString('3', $shown);
        $this->assertStringContainsString(__('At the door', 'bg-couriers'), $shown);
        $this->assertStringNotContainsString('paid to the courier', $shown, 'the sentence is on the hint, not on the line');
        $this->assertLessThan(24, mb_strlen($shown), 'short enough for one line at any width the panel gets');
        $this->assertMatchesRegularExpression('/data-tip="[^"]*paid to the courier on delivery[^"]*"/', $m[0]);
    }
}

final class BGCouriers_State_Probe extends BGCouriers_Abstract_Courier {
    public function id(): string { return 'stateprobe'; }
    public function label(): string { return 'Probe'; }
    public function capabilities(): array { return ['office', 'address']; }
    public function check_credentials(): bool { return true; }
    public function fetch_cities(): array { return []; }
    public function fetch_offices(int $c, string $country = ''): array { return []; }
    public function quote(array $s): BGCouriers_Quote { return new BGCouriers_Quote(1.0, 0.0, 'EUR', 'fixed'); }
    public function create_label(\WC_Order $o): BGCouriers_Label { return new BGCouriers_Label(''); }
    public function label_formats(): array { return []; }
    public function get_label_pdf(string $w, string $f = ''): string { return ''; }
    public function cancel_label(string $w): bool { return true; }
    public function track(string $w): BGCouriers_Tracking { return new BGCouriers_Tracking($w, '', []); }
    public function tracking_url(string $w): string { return ''; }
}
