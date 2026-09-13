<?php
defined('ABSPATH') || exit;

/**
 * A street written as text, matched against a courier's list.
 *
 * The couriers list a street as a type and a name - "бул." + "ВИТОША" - and take it back by its id.
 * Text arrives with the type in front of the name: the address map's reverse geocoding hands over
 * "бул. Княз Александър Дондуков" for one point and "Кърниградска" for the next (measured 2026-09-13,
 * Nominatim, Sofia), older orders carry whatever was typed, and Speedy's street search answers a name
 * with the type in it with nothing at all (also measured: "бул. Княз Александър Дондуков" 0 rows,
 * "Княз Александър Дондуков" 1). So a typed street is split into what it says about the type and
 * what the name is, the list is searched by the name, and the type tells two streets of that name
 * apart. Every courier's spelling of a type counts as the same type: "бул.", "БУЛ.", "бул" and
 * "булевард" are one.
 */
class BGCouriers_Street {

    /** Each type's spellings, in the form they are written in front of a name. */
    const TYPES = [
        'ul'  => ['ул', 'улица', 'ul', 'ulitsa', 'ulica', 'str'],
        'bul' => ['бул', 'булевард', 'bul', 'bulevard', 'blvd'],
        'pl'  => ['пл', 'площад', 'pl', 'ploshtad'],
        'al'  => ['ал', 'алея', 'al', 'aleya'],
        'zhk' => ['жк', 'ж.к', 'ж. к', 'zhk', 'zh.k', 'j.k'],
        'kv'  => ['кв', 'квартал', 'kv', 'kvartal'],
    ];

    /**
     * The type a text names in front of the street, and the name without it.
     *
     * "бул. Княз Александър Дондуков" -> type "бул.", name "Княз Александър Дондуков";
     * "Кърниградска" -> type "", name "Кърниградска". Quotes around the name („Витоша“) are dropped.
     *
     * @return array{type:string,name:string}
     */
    public static function split(string $text): array {
        $t = trim((string) preg_replace('/\s+/u', ' ', str_replace(['„', '“', '"', '”', '«', '»'], '', $text)));
        if (preg_match('/^([^\s]+\.?)\s+(.+)$/u', $t, $m) || preg_match('/^([\p{L}]+\.)(\S.*)$/u', $t, $m)) {
            // "Ал." in front of a name is Александър far more often than алея on a Bulgarian street
            // sign (бул. Ал. Стамболийски), so in free text it is part of the name; a courier's own
            // list saying "АЛ." is another matter (see canon()).
            $word = rtrim(self::fold($m[1]), '.');
            if (!in_array($word, ['ал', 'al'], true) && self::canon($word) !== '') { return ['type' => $m[1], 'name' => trim($m[2])]; }
        }
        return ['type' => '', 'name' => $t];
    }

    /** One key per type, whatever the spelling; '' for a word that is not a type. */
    public static function canon(string $type): string {
        $t = str_replace(' ', '', rtrim(self::fold($type), '.'));
        foreach (self::TYPES as $key => $spellings) {
            foreach ($spellings as $sp) {
                if ($t === str_replace(' ', '', rtrim($sp, '.'))) { return $key; }
            }
        }
        return '';
    }

    /** Do two type spellings name the same type? A spelling nobody recognises matches itself only. */
    public static function same_type(string $a, string $b): bool {
        $ca = self::canon($a); $cb = self::canon($b);
        if ($ca !== '' || $cb !== '') { return $ca === $cb; }
        return rtrim(self::fold($a), '.') === rtrim(self::fold($b), '.');
    }

    /**
     * The rows of a courier's list that ARE the street written in $text: the name equal to it, or the
     * label ("бул. ВИТОША") equal to it, or the name equal to what is left after its type is taken off
     * - and, when the text named a type and more than one street of that name is left, the ones of
     * that type. Case and spacing are not part of a street's identity.
     *
     * @param array[] $rows  Parsed street rows ({id,name,type,label}).
     * @return array[]
     */
    public static function exact(array $rows, string $text): array {
        $want  = self::fold($text);
        $split = self::split($text);
        $bare  = self::fold($split['name']);
        if ($want === '') { return []; }
        $hits = [];
        foreach ($rows as $r) {
            $n = self::fold((string) ($r['name'] ?? ''));
            $l = self::fold((string) ($r['label'] ?? ''));
            if ($n === $want || $l === $want || $n === $bare) { $hits[] = $r; }
        }
        if (count($hits) > 1 && $split['type'] !== '') {
            $of_type = array_values(array_filter($hits, static function ($r) use ($split) { return self::same_type((string) ($r['type'] ?? ''), $split['type']); }));
            if ($of_type) { $hits = $of_type; }
        }
        return array_values($hits);
    }

    public static function fold(string $s): string {
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }
}
