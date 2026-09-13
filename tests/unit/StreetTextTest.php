<?php
use PHPUnit\Framework\TestCase;

/**
 * A street written as text - off the map, or typed - against a courier's list.
 *
 * @group core
 */
final class StreetTextTest extends TestCase {
    private static function row(int $id, string $type, string $name): array {
        return ['id' => $id, 'name' => $name, 'type' => $type, 'label' => trim($type . ' ' . $name)];
    }

    public function test_the_type_in_front_of_the_name_is_split_off(): void {
        $this->assertSame(['type' => 'бул.', 'name' => 'Княз Александър Дондуков'], BGCouriers_Street::split('бул. Княз Александър Дондуков'));
        $this->assertSame(['type' => 'улица', 'name' => 'Шипка'], BGCouriers_Street::split('улица Шипка'));
        $this->assertSame(['type' => 'ул.', 'name' => 'Витоша'], BGCouriers_Street::split('ул.Витоша'), 'no space after the dot');
        $this->assertSame(['type' => 'ж.к.', 'name' => 'Младост 1'], BGCouriers_Street::split('ж.к. Младост 1'));
        $this->assertSame(['type' => 'булевард', 'name' => 'Витоша'], BGCouriers_Street::split('булевард „Витоша“'), 'the quotes a geocoder puts around a name are dropped');
    }

    public function test_a_name_with_no_type_is_left_whole(): void {
        $this->assertSame(['type' => '', 'name' => 'Кърниградска'], BGCouriers_Street::split('Кърниградска'));
        $this->assertSame(['type' => '', 'name' => 'Княз Борис I'], BGCouriers_Street::split('Княз Борис I'));
        $this->assertSame(['type' => '', 'name' => '600-НА (ВИТОША)'], BGCouriers_Street::split('600-НА (ВИТОША)'));
        $this->assertSame(['type' => '', 'name' => 'Ал. Стамболийски'], BGCouriers_Street::split('Ал. Стамболийски'), 'Ал. is Александър on a street sign, not алея');
        $this->assertSame(['type' => 'алея', 'name' => 'Яворов'], BGCouriers_Street::split('алея Яворов'), 'spelled out, it is the type');
        $this->assertTrue(BGCouriers_Street::same_type('АЛ.', 'алея'), 'a list that says АЛ. means алея');
        $this->assertSame(['type' => '', 'name' => ''], BGCouriers_Street::split('   '));
    }

    public function test_every_spelling_of_a_type_is_the_same_type(): void {
        $this->assertTrue(BGCouriers_Street::same_type('бул.', 'булевард'));
        $this->assertTrue(BGCouriers_Street::same_type('БУЛ.', 'бул'));
        $this->assertTrue(BGCouriers_Street::same_type('ж.к.', 'жк'));
        $this->assertTrue(BGCouriers_Street::same_type('улица', 'УЛ.'));
        $this->assertFalse(BGCouriers_Street::same_type('ул.', 'бул.'));
        $this->assertTrue(BGCouriers_Street::same_type('местност', 'МЕСТНОСТ.'), 'a type nobody lists still equals itself');
        $this->assertFalse(BGCouriers_Street::same_type('местност', 'ул.'));
    }

    /** The measured Sofia lists: Speedy's two ВИТОША, and the map's typed name against them. */
    public function test_the_rows_that_are_the_street_written(): void {
        $rows = [self::row(26, 'бул.', 'ВИТОША'), self::row(1314, 'ул.', 'ВИТОША'), self::row(9001, 'ул.', 'ВИТОШКА'), self::row(64, 'бул.', 'КНЯЗ АЛЕКСАНДЪР ДОНДУКОВ')];
        $this->assertSame([26, 1314], array_column(BGCouriers_Street::exact($rows, 'Витоша'), 'id'), 'a bare name: both of that name, in the list\'s order');
        $this->assertSame([1314], array_column(BGCouriers_Street::exact($rows, 'ул. Витоша'), 'id'), 'the type in the text picks');
        $this->assertSame([26], array_column(BGCouriers_Street::exact($rows, 'булевард „Витоша“'), 'id'), 'in any spelling');
        $this->assertSame([64], array_column(BGCouriers_Street::exact($rows, 'бул. Княз Александър Дондуков'), 'id'), 'what the map hands over');
        $this->assertSame([64], array_column(BGCouriers_Street::exact($rows, 'Княз Александър Дондуков'), 'id'));
        $this->assertSame([], BGCouriers_Street::exact($rows, 'Шипка'));
        $this->assertSame([], BGCouriers_Street::exact($rows, ''));
    }

    public function test_a_type_neither_row_has_leaves_both(): void {
        $rows = [self::row(26, 'бул.', 'ВИТОША'), self::row(1314, 'ул.', 'ВИТОША')];
        $this->assertSame([26, 1314], array_column(BGCouriers_Street::exact($rows, 'пл. Витоша'), 'id'));
    }

    /** Pigeon spells the types out and Европът leaves the dot off - the same street all the same. */
    public function test_the_other_couriers_spellings(): void {
        $pigeon  = [self::row(1, 'улица', 'Витоша'), self::row(2, 'булевард', 'ВИТОША')];
        $this->assertSame([2], array_column(BGCouriers_Street::exact($pigeon, 'бул. Витоша'), 'id'));
        $evropat = [['id' => 36141, 'name' => 'витоша', 'type' => 'бул', 'label' => 'бул. витоша'], ['id' => 38842, 'name' => 'витоша', 'type' => 'ул', 'label' => 'ул. витоша']];
        $this->assertSame([38842], array_column(BGCouriers_Street::exact($evropat, 'улица Витоша'), 'id'));
    }
}
