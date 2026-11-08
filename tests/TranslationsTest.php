<?php
declare(strict_types=1);

namespace Anoofy\Woo\Tests;

use PHPUnit\Framework\TestCase;

/** Every string passed to a gettext function must have an Arabic translation with the same placeholders. */
final class TranslationsTest extends TestCase
{
    private const RX = "/\\b(?:__|esc_html__|esc_attr__|_e|esc_html_e|esc_attr_e)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*,\\s*'anoofy-woo'/";

    /** @return list<string> */
    private function sourceStrings(): array
    {
        $root = dirname(__DIR__);
        $files = [$root . '/anoofy-woo.php'];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src'));
        foreach ($it as $f) {
            if (str_ends_with((string) $f, '.php')) {
                $files[] = (string) $f;
            }
        }
        $out = [];
        foreach ($files as $file) {
            preg_match_all(self::RX, (string) file_get_contents($file), $m);
            foreach ($m[1] as $raw) {
                $out[str_replace(["\\'", '\\\\'], ["'", '\\'], $raw)] = true;
            }
        }

        return array_keys($out);
    }

    /** @return array<string, string> msgid => msgstr */
    private function arabic(): array
    {
        $entries = [];
        $id = null;
        $str = null;
        $current = null;
        foreach (explode("\n", (string) file_get_contents(dirname(__DIR__) . '/languages/anoofy-woo-ar.po')) as $line) {
            if (str_starts_with($line, 'msgid ')) {
                if ($id !== null && $id !== '') {
                    $entries[$id] = (string) $str;
                }
                $id = stripcslashes(substr($line, 7, -1));
                $str = null;
                $current = 'id';
            } elseif (str_starts_with($line, 'msgstr ')) {
                $str = stripcslashes(substr($line, 8, -1));
                $current = 'str';
            } elseif (str_starts_with($line, '"') && $current === 'id') {
                $id .= stripcslashes(substr($line, 1, -1));
            } elseif (str_starts_with($line, '"') && $current === 'str') {
                $str .= stripcslashes(substr($line, 1, -1));
            }
        }
        if ($id !== null && $id !== '') {
            $entries[$id] = (string) $str;
        }

        return $entries;
    }

    public function testEveryStringHasAnArabicTranslation(): void
    {
        $ar = $this->arabic();
        foreach ($this->sourceStrings() as $msgid) {
            self::assertArrayHasKey($msgid, $ar, 'Missing in languages/anoofy-woo-ar.po (run tools/i18n.py): ' . $msgid);
            self::assertTrue(trim($ar[$msgid]) !== '', 'Empty translation: ' . $msgid);
        }
    }

    public function testPlaceholdersSurviveTranslation(): void
    {
        foreach ($this->arabic() as $msgid => $msgstr) {
            preg_match_all('/%(?:\d+\$)?[sdf]/', $msgid, $a);
            preg_match_all('/%(?:\d+\$)?[sdf]/', $msgstr, $b);
            self::assertSame($a[0], $b[0], $msgid);
        }
    }

    public function testCompiledCatalogExistsAndIsAValidMoFile(): void
    {
        $file = dirname(__DIR__) . '/languages/anoofy-woo-ar.mo';
        self::assertTrue(is_file($file), 'Run tools/i18n.py to compile the .mo file');
        // Little-endian gettext magic number 0x950412de.
        self::assertSame("\xde\x12\x04\x95", substr((string) file_get_contents($file), 0, 4));
    }
}
