<?php
use PHPUnit\Framework\TestCase;

/**
 * The street dropdown shows the first twenty of what the courier answers, and a courier's own order
 * is not a ranking. Measured on 2026-09-13: Pigeon answers "Витоша" for Sofia with thirty-one numbered
 * streets - "улица 600-НА (ВИТОША)", "улица 603-ТА (ВИТОША)"... - before "улица Витоша" and "булевард
 * ВИТОША", which put both past the twenty the box shows; a customer typing the street's whole name
 * did not find it. Ranked the way the town search ranks: begins with the term, then a word does, then
 * the term is somewhere inside; the courier's order within each.
 *
 * @group core
 */
final class StreetRankTest extends TestCase {
    private static function row(int $id, string $type, string $name): array {
        return ['id' => $id, 'name' => $name, 'type' => $type, 'label' => trim($type . ' ' . $name)];
    }

    public function test_the_street_named_exactly_that_comes_before_the_numbered_ones_that_mention_it(): void {
        $rows = [
            self::row(434325, 'улица', '600-НА (ВИТОША)'),
            self::row(434326, 'улица', '603-ТА (ВИТОША)'),
            self::row(434327, 'улица', '639-ТА (ОВЧА КУПЕЛ ВИТОША)'),
            self::row(434390, 'улица', 'Витоша'),
            self::row(434391, 'булевард', 'ВИТОША'),
            self::row(434392, 'улица', 'Иван Радоев - кв.Витоша'),
            self::row(434393, 'улица', 'ЯВОР (ВИТОША)'),
        ];
        $out = array_map(static function ($r) { return $r['id']; }, BGCouriers_Ajax::rank_streets($rows, 'Витоша'));
        $this->assertSame([434390, 434391, 434325, 434326, 434327, 434392, 434393], $out,
            'the two named Витоша first, in the courier\'s order; then every row where a word begins with it, in the courier\'s order');
    }

    public function test_a_term_inside_a_word_ranks_last_among_the_matches(): void {
        $rows = [
            self::row(1, 'ул.', 'ПРЕВИТОШКА'),      // "витош" inside a word
            self::row(2, 'ул.', 'СТАРА ВИТОШКА'),   // a word begins with it
            self::row(3, 'бул.', 'ВИТОШКО ЛАЛЕ'),   // begins with it
        ];
        $out = array_map(static function ($r) { return $r['id']; }, BGCouriers_Ajax::rank_streets($rows, 'витош'));
        $this->assertSame([3, 2, 1], $out);
    }

    public function test_case_and_spacing_are_not_part_of_the_match(): void {
        $rows = [self::row(1, 'ул.', '600-НА (ВИТОША)'), self::row(2, 'ул.', 'витоша')];
        $out = BGCouriers_Ajax::rank_streets($rows, '  ВИТОША ');
        $this->assertSame(2, $out[0]['id']);
    }

    public function test_an_empty_term_and_a_row_without_the_term_keep_their_place(): void {
        $rows = [self::row(1, 'ул.', 'ШИПКА'), self::row(2, 'ул.', 'ВИТОША')];
        $this->assertSame($rows, BGCouriers_Ajax::rank_streets($rows, ''));
        $out = BGCouriers_Ajax::rank_streets($rows, 'ВИТ');
        $this->assertSame([2, 1], array_map(static function ($r) { return $r['id']; }, $out), 'a row the courier returned without the term in its name goes last, not away');
    }

    /** What the courier's list looks like when it already ranks - Speedy's ВИТОША - is left as it is. */
    public function test_a_list_that_is_already_ranked_is_unchanged(): void {
        $rows = [self::row(26, 'бул.', 'ВИТОША'), self::row(1314, 'ул.', 'ВИТОША'), self::row(9001, 'ул.', 'ВИТОШКА')];
        $this->assertSame($rows, BGCouriers_Ajax::rank_streets($rows, 'ВИТОША'));
    }
}
