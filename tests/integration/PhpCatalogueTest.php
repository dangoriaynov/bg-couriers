<?php
/**
 * Каталогът пътува и във формата, който WordPress чете ПЪРВИ.
 *
 * От 6.5 насам ядрото иска `.l10n.php` преди `.mo` (`translation_file_format` в l10n.php връща 'php').
 * Плъгин, който носи само .mo, е с един файл назад: всеки остарял `.l10n.php` с по-висок приоритет -
 * папката на Loco Translate, „почистване на преводи" от хостинг - отговаря преди неговия. Така един
 * български магазин четеше чекаута на английски с пълен каталог, седящ вътре в плъгина (2026-09-30).
 *
 * @group core
 */
final class PhpCatalogueTest extends WP_UnitTestCase {

    private function php_file(): string {
        return BGCOURIERS_PATH . 'languages/bg-couriers-bg_BG.l10n.php';
    }

    public function test_the_php_catalogue_ships_beside_the_mo(): void {
        $this->assertFileExists($this->php_file());
        $this->assertFileExists(BGCOURIERS_PATH . 'languages/bg-couriers-bg_BG.mo');
    }

    /** Файлът е това, което ядрото очаква: масив с 'messages', а не какъвто и да е PHP. */
    public function test_it_is_the_array_wordpress_expects(): void {
        $data = include $this->php_file();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('messages', $data);
        $this->assertIsArray($data['messages']);
        $this->assertGreaterThan(600, count($data['messages']), 'каталогът е подозрително празен');
        $this->assertSame('bg_BG', $data['language'] ?? '');
    }

    /** И казва същото като .mo - иначе двата формата биха се разминали тихо. */
    public function test_it_says_what_the_mo_says(): void {
        $data = include $this->php_file();
        foreach (['To office' => 'До офис', 'To address' => 'До адрес', 'City' => 'Град', 'Office' => 'Офис'] as $en => $bg) {
            $this->assertSame($bg, $data['messages'][$en] ?? null, "„$en" . '" липсва или е различен');
        }
    }

    /** Ядрото го чете без да се оплаче - проверено с неговия собствен четец, не с наш. */
    public function test_wordpress_can_read_it(): void {
        if (!class_exists('WP_Translation_File')) {
            $this->markTestSkipped('WP_Translation_File идва с WordPress 6.5');
        }
        $file = WP_Translation_File::create($this->php_file());
        $this->assertNotFalse($file, 'WordPress не разпозна файла');
        $this->assertNull($file->error(), (string) $file->error());
        $this->assertSame('До офис', $file->translate('To office'));
    }
}
