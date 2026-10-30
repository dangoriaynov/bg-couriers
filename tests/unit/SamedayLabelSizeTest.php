<?php
use Brain\Monkey\Functions;

/**
 * @group sameday
 */
final class SamedayLabelSizeTest extends BGCouriers_Unit_TestCase {
    /** @return array<string,array{string,string}> */
    public static function sizes(): array {
        return [
            'A6 sticker'     => ['A6', 'https://api.sameday.bg/api/awb/download/1CJ123/A6'],
            'A4 sheet'       => ['A4', 'https://api.sameday.bg/api/awb/download/1CJ123/A4'],
            'account default' => ['', 'https://api.sameday.bg/api/awb/download/1CJ123'],
        ];
    }

    /** @dataProvider sizes */
    public function test_the_label_size_is_asked_for_in_the_path(string $format, string $url): void {
        $this->options();
        Functions\when('wp_parse_url')->alias(static fn($u, $c = -1) => parse_url($u, $c));
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_body')->alias(static fn($r) => $r['body']);
        $sameday = new class(['username' => 'u']) extends BGCouriers_Sameday {
            public array $asked = [];
            protected function auth_token(): string { return 'tok'; }
            protected function http_get(string $url, array $headers = [], int $timeout = 40) { $this->asked[] = $url; return ['body' => '%PDF-1.7']; }
        };

        $this->assertSame('%PDF-1.7', $sameday->get_label_pdf('1CJ123', $format));
        $this->assertSame([$url], $sameday->asked);
    }
}
