<?php
defined('ABSPATH') || exit;

/** A street written as text, split into type and name and matched against a courier's list. */
class BGCouriers_Street {

    const TYPES = [
        'ul'  => ['ул', 'улица', 'ul', 'ulitsa', 'ulica', 'str'],
        'bul' => ['бул', 'булевард', 'bul', 'bulevard', 'blvd'],
        'pl'  => ['пл', 'площад', 'pl', 'ploshtad'],
        'al'  => ['ал', 'алея', 'al', 'aleya'],
        'zhk' => ['жк', 'ж.к', 'ж. к', 'zhk', 'zh.k', 'j.k'],
        'kv'  => ['кв', 'квартал', 'kv', 'kvartal'],
    ];

    /** @return array{type:string,name:string} */
    public static function split(string $text): array {
        $t = trim((string) preg_replace('/\s+/u', ' ', str_replace(['„', '“', '"', '”', '«', '»'], '', $text)));
        if (preg_match('/^([^\s]+\.?)\s+(.+)$/u', $t, $m) || preg_match('/^([\p{L}]+\.)(\S.*)$/u', $t, $m)) {
            // "Ал." before a name is Александър far more often than алея, so in free text it is part of the name.
            $word = rtrim(self::fold($m[1]), '.');
            if (!in_array($word, ['ал', 'al'], true) && self::canon($word) !== '') { return ['type' => $m[1], 'name' => trim($m[2])]; }
        }
        return ['type' => '', 'name' => $t];
    }

    public static function canon(string $type): string {
        $t = str_replace(' ', '', rtrim(self::fold($type), '.'));
        foreach (self::TYPES as $key => $spellings) {
            foreach ($spellings as $sp) {
                if ($t === str_replace(' ', '', rtrim($sp, '.'))) { return $key; }
            }
        }
        return '';
    }

    public static function same_type(string $a, string $b): bool {
        $ca = self::canon($a); $cb = self::canon($b);
        if ($ca !== '' || $cb !== '') { return $ca === $cb; }
        return rtrim(self::fold($a), '.') === rtrim(self::fold($b), '.');
    }

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
        return $hits;
    }

    public static function fold(string $s): string {
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }
}
