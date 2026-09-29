<?php
/**
 * Преводът трябва да се зарежда и когато just-in-time не проработи.
 *
 * Два магазина съобщиха едно и също: сайтът е на български - WordPress, WooCommerce и темата всички са
 * преведени - а полетата на куриера стоят на английски („To office", „City", „Office"). На dobavki.club
 * всичко се зарежда както трябва, тоест файлът и каталогът са наред; счупва се РЕГИСТЪРЪТ, от който
 * зависи автоматичното зареждане: преименувана папка при ръчно качване, стар кеш на плъгините, заявка
 * за низ преди `init` при WP 6.7+ (собственик, със снимка на чужд магазин, 2026-09-28).
 *
 * load_plugin_textdomain() не пита регистъра - тръгва от самия файл на плъгина. Тестът иска точно това:
 * домейнът да е зареден след `init` и низът да излиза на български.
 *
 * @group core
 */
final class TranslationsLoadTest extends WP_UnitTestCase {

    private string $previous = '';

    public function set_up() {
        parent::set_up();
        $this->previous = get_locale();
        add_filter('locale', [$this, 'force_bulgarian']);
        // load_plugin_textdomain() пита точно този филтър, не само `locale`.
        add_filter('plugin_locale', [$this, 'force_bulgarian']);
        unload_textdomain('bg-couriers');
    }

    public function tear_down() {
        remove_filter('locale', [$this, 'force_bulgarian']);
        remove_filter('plugin_locale', [$this, 'force_bulgarian']);
        unload_textdomain('bg-couriers');
        parent::tear_down();
    }

    public function force_bulgarian(): string {
        return 'bg_BG';
    }

    /** Каталогът пътува с плъгина: без файла няма какво да се зарежда. */
    public function test_the_bulgarian_catalogue_ships_with_the_plugin(): void {
        $mo = BGCOURIERS_PATH . 'languages/bg-couriers-bg_BG.mo';
        $this->assertFileExists($mo);
        $this->assertGreaterThan(1000, (int) filesize($mo), 'каталогът е подозрително малък');
    }

    /**
     * Зареждането НЕ зависи от регистъра на пътищата: точно той липсва на магазина, който съобщи за
     * английските полета.
     *
     * Съди се по върнатата стойност и по самия превод, а НЕ по is_textdomain_loaded(): от WP 6.7
     * каталогът се разчита лениво, при първия поискан низ, и тази функция връща false дори когато
     * файлът е зареден и преводът работи. Проверено тук: load_plugin_textdomain() дава true,
     * is_textdomain_loaded() дава false, а __() връща българския текст.
     */
    public function test_the_domain_loads_from_the_plugin_folder(): void {
        $rel = dirname(plugin_basename(BGCOURIERS_FILE)) . '/languages';
        $this->assertFileExists(WP_PLUGIN_DIR . '/' . $rel . '/bg-couriers-bg_BG.mo');
        $this->assertTrue(
            load_plugin_textdomain('bg-couriers', false, $rel),
            'load_plugin_textdomain върна false - преводът няма откъде да дойде'
        );
    }

    /** И най-важното: низовете от чекаута излизат на български, а не както са написани в кода. */
    public function test_checkout_labels_come_out_in_bulgarian(): void {
        load_plugin_textdomain('bg-couriers', false, dirname(plugin_basename(BGCOURIERS_FILE)) . '/languages');
        $this->assertSame('До офис', __('To office', 'bg-couriers'));
        $this->assertSame('До адрес', __('To address', 'bg-couriers'));
        $this->assertSame('Град', __('City', 'bg-couriers'));
        $this->assertSame('Офис', __('Office', 'bg-couriers'));
    }

    /** Плъгинът закача зареждането сам - иначе редът по-горе би бил само тест на теста. */
    public function test_the_plugin_hooks_the_load_itself(): void {
        $this->assertNotFalse(
            strpos((string) file_get_contents(BGCOURIERS_FILE), "load_plugin_textdomain('bg-couriers'"),
            'главният файл вече не зарежда домейна явно'
        );
    }
}
