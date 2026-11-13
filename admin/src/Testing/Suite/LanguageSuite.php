<?php

/**
 * @package     Google Auth Tests
 * @license     GNU General Public License version 2 or later
 */

namespace TommiLin\Component\GoogleAuthTests\Administrator\Testing\Suite;

\defined('_JEXEC') or die;

use TommiLin\Component\GoogleAuthTests\Administrator\Testing\Harness;
use TommiLin\Component\GoogleAuthTests\Administrator\Testing\TestContext;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\LanguageHelper;

final class LanguageSuite
{
    public const GROUP_ID = 'language';
    public const GROUP_TITLE = 'Языковые файлы';

    private const LANGS = ['en-GB', 'ru-RU', 'uk-UA'];

    public static function tests(): array
    {
        return [
            ['id' => 'lang.syntax', 'method' => 'syntax', 'title' => 'Синтаксис .ini', 'desc' => 'Каждая строка — KEY="значение"; нет слипшихся пар, неэкранированных кавычек и BOM.'],
            ['id' => 'lang.duplicates', 'method' => 'duplicates', 'title' => 'Нет повторяющихся ключей', 'desc' => 'Один ключ — одна строка в каждом файле.'],
            ['id' => 'lang.parity', 'method' => 'parity', 'title' => 'Одинаковый набор ключей во всех языках', 'desc' => 'en-GB, ru-RU и uk-UA содержат одни и те же ключи (и .ini, и .sys.ini).'],
            ['id' => 'lang.xml_keys', 'method' => 'xmlKeys', 'title' => 'Все подписи из манифеста переведены', 'desc' => 'Каждый label/description/опция из googleauth.xml есть в языковых файлах.'],
            ['id' => 'lang.php_keys', 'method' => 'phpKeys', 'title' => 'Все ключи из PHP-кода переведены', 'desc' => 'Каждый PLG_SYSTEM_GOOGLEAUTH_* из кода плагина есть во всех языках.'],
            ['id' => 'lang.placeholders', 'method' => 'placeholders', 'title' => 'Плейсхолдеры %s / %d совпадают', 'desc' => 'Строки с подстановками одинаковы по количеству и типам во всех языках.'],
            ['id' => 'lang.loader', 'method' => 'loader', 'title' => 'Joomla читает языковые файлы', 'desc' => 'Парсер Joomla находит столько же ключей, сколько и тест, ключи переводятся.'],
        ];
    }

    private function path(string $lang, bool $sys): string
    {
        return Harness::pluginDir() . '/language/' . $lang . '/plg_system_googleauth' . ($sys ? '.sys' : '') . '.ini';
    }

    /** @return array{keys:array<string,string>,dups:array<int,string>,errors:array<int,string>} */
    private function parse(string $path): array
    {
        $result = ['keys' => [], 'dups' => [], 'errors' => []];

        if (!is_file($path)) {
            $result['errors'][] = 'файл не найден';

            return $result;
        }

        $content = (string) file_get_contents($path);

        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $result['errors'][] = 'файл начинается с BOM (UTF-8 signature)';
        }

        if (!mb_check_encoding($content, 'UTF-8')) {
            $result['errors'][] = 'файл не в кодировке UTF-8';
        }

        foreach (preg_split('/\r\n|\n|\r/', $content) as $index => $line) {
            $line = trim($line);
            $number = $index + 1;

            if ($line === '' || $line[0] === ';' || $line[0] === '#' || $line[0] === '[') {
                continue;
            }

            if (!preg_match('/^([A-Za-z][A-Za-z0-9_]*)="(.*)"$/s', $line, $m)) {
                $result['errors'][] = 'строка ' . $number . ': не вида KEY="значение"';
                continue;
            }

            [, $key, $value] = $m;

            if (preg_match('/"[A-Z][A-Z0-9_]{6,}="/', $value)) {
                $result['errors'][] = 'строка ' . $number . ' (' . $key . '): две пары KEY="…" слиплись в одну строку';
            }

            if (str_contains(str_replace('"_QQ_"', '', $value), '"')) {
                $result['errors'][] = 'строка ' . $number . ' (' . $key . '): неэкранированная кавычка в значении (используйте "_QQ_")';
            }

            if (isset($result['keys'][$key])) {
                $result['dups'][] = $key . ' (строка ' . $number . ')';
            }

            $result['keys'][$key] = $value;
        }

        return $result;
    }

    public function syntax(TestContext $t): void
    {
        foreach (self::LANGS as $lang) {
            foreach ([false, true] as $sys) {
                $name = $lang . '/' . basename($this->path($lang, $sys));
                $parsed = $this->parse($this->path($lang, $sys));

                if ($parsed['errors'] === []) {
                    $t->ok(true, $name . ': ' . count($parsed['keys']) . ' ключей');
                } else {
                    foreach ($parsed['errors'] as $error) {
                        $t->fail($name . ': ' . $error);
                    }
                }
            }
        }
    }

    public function duplicates(TestContext $t): void
    {
        foreach (self::LANGS as $lang) {
            foreach ([false, true] as $sys) {
                $parsed = $this->parse($this->path($lang, $sys));
                $name = $lang . '/' . basename($this->path($lang, $sys));
                $t->ok($parsed['dups'] === [], $name . ': повторов нет', $name . ': повторяются ' . implode(', ', array_slice($parsed['dups'], 0, 8)));
            }
        }
    }

    public function parity(TestContext $t): void
    {
        foreach ([false, true] as $sys) {
            $reference = array_keys($this->parse($this->path('en-GB', $sys))['keys']);

            foreach (['ru-RU', 'uk-UA'] as $lang) {
                $keys = array_keys($this->parse($this->path($lang, $sys))['keys']);
                $missing = array_diff($reference, $keys);
                $extra = array_diff($keys, $reference);
                $name = $lang . ($sys ? ' (.sys.ini)' : ' (.ini)');
                $t->ok($missing === [], $name . ': не хватает ключей нет', $name . ': не хватает ' . count($missing) . ' ключей: ' . implode(', ', array_slice($missing, 0, 6)));

                if ($extra !== []) {
                    $t->warn($name . ': лишние ключи (нет в en-GB): ' . implode(', ', array_slice($extra, 0, 6)));
                }
            }
        }
    }

    public function xmlKeys(TestContext $t): void
    {
        $xml = Harness::readXml();

        if ($xml === null) {
            $t->skip('googleauth.xml не найден.');
        }

        $keys = [];

        foreach ($xml->xpath('//@label | //@description') ?: [] as $attr) {
            $keys[(string) $attr] = true;
        }

        foreach ($xml->xpath('//option') ?: [] as $option) {
            $keys[trim((string) $option)] = true;
        }

        $keys = array_filter(array_keys($keys), static fn ($k) => str_starts_with($k, 'PLG_SYSTEM_GOOGLEAUTH'));
        $sysKeys = [trim((string) $xml->name), trim((string) $xml->description)];

        foreach (self::LANGS as $lang) {
            $main = $this->parse($this->path($lang, false))['keys'];
            $sys = $this->parse($this->path($lang, true))['keys'];
            $missing = array_values(array_filter($keys, static fn ($k) => !isset($main[$k]) && !in_array($k, $sysKeys, true)));
            $t->ok($missing === [], $lang . ': все ' . count($keys) . ' подписей манифеста переведены', $lang . ': нет переводов: ' . implode(', ', array_slice($missing, 0, 8)));

            foreach (array_filter($sysKeys) as $key) {
                $t->ok(isset($sys[$key]) || isset($main[$key]), $lang . ': есть название/описание ' . $key, $lang . ': нет ' . $key . ' в .sys.ini (название плагина в списке расширений будет «сырым»)');
            }
        }
    }

    public function phpKeys(TestContext $t): void
    {
        $file = Harness::pluginDir() . '/src/Extension/Googleauth.php';

        if (!is_file($file)) {
            $t->skip('Файл плагина не найден.');
        }

        preg_match_all('/[\'"](PLG_SYSTEM_GOOGLEAUTH_[A-Z0-9_]+)[\'"]/', (string) file_get_contents($file), $m);
        $used = array_values(array_unique($m[1]));
        $t->info('Ключей в коде плагина: ' . count($used));

        foreach (self::LANGS as $lang) {
            $main = $this->parse($this->path($lang, false))['keys'];
            $missing = array_values(array_filter($used, static fn ($k) => !isset($main[$k])));
            $t->ok($missing === [], $lang . ': все ключи из кода переведены', $lang . ': нет переводов для ' . implode(', ', array_slice($missing, 0, 8)));
        }

        $xmlText = (string) @file_get_contents(Harness::pluginDir() . '/googleauth.xml');
        $main = $this->parse($this->path('en-GB', false))['keys'];
        $unused = array_values(array_filter(array_keys($main), static fn ($k) => !in_array($k, $used, true) && !str_contains($xmlText, $k)));

        if ($unused !== []) {
            $t->info('Ключи en-GB, которые не используются ни в коде, ни в манифесте (' . count($unused) . '): ' . implode(', ', array_slice($unused, 0, 8)));
        }
    }

    public function placeholders(TestContext $t): void
    {
        $reference = $this->parse($this->path('en-GB', false))['keys'];
        $checked = 0;

        foreach ($reference as $key => $value) {
            preg_match_all('/%(?:\d+\$)?[sdf]/', $value, $m);
            $expected = array_map(static fn ($p) => substr($p, -1), $m[0]);
            sort($expected);

            if ($expected === []) {
                continue;
            }

            $checked++;

            foreach (['ru-RU', 'uk-UA'] as $lang) {
                $other = $this->parse($this->path($lang, false))['keys'][$key] ?? null;

                if ($other === null) {
                    continue;
                }

                preg_match_all('/%(?:\d+\$)?[sdf]/', $other, $mm);
                $actual = array_map(static fn ($p) => substr($p, -1), $mm[0]);
                sort($actual);
                $t->ok($actual === $expected, $lang . ' ' . $key . ': плейсхолдеры совпадают', $lang . ' ' . $key . ': плейсхолдеры (' . implode('', $actual) . ') не совпадают с en-GB (' . implode('', $expected) . ')');
            }
        }

        $t->info('Строк с плейсхолдерами: ' . $checked);
    }

    public function loader(TestContext $t): void
    {
        foreach (self::LANGS as $lang) {
            $path = $this->path($lang, false);
            $mine = $this->parse($path)['keys'];
            $joomla = LanguageHelper::parseIniFile($path, false);
            $t->ok(count($joomla) === count($mine), $lang . ': парсер Joomla прочитал ' . count($joomla) . ' ключей', $lang . ': парсер Joomla прочитал ' . count($joomla) . ' ключей, а в файле ' . count($mine) . ' (файл содержит строки, которые Joomla отбрасывает)');
        }

        try {
            $factory = Factory::getContainer()->get(\Joomla\CMS\Language\LanguageFactoryInterface::class);

            foreach (self::LANGS as $lang) {
                $language = $factory->createLanguage($lang, false);
                $language->load('plg_system_googleauth', Harness::pluginDir(), $lang, true);
                $untranslated = [];

                foreach (['PLG_SYSTEM_GOOGLEAUTH_LOGIN_BUTTON', 'PLG_SYSTEM_GOOGLEAUTH_BUTTON_THEME_LABEL', 'PLG_SYSTEM_GOOGLEAUTH_REDIRECT_LOGIN_LABEL'] as $key) {
                    if ($language->_($key) === $key) {
                        $untranslated[] = $key;
                    }
                }

                $t->ok($untranslated === [], $lang . ': ключи переводятся через загрузчик Joomla', $lang . ': не переведены ' . implode(', ', $untranslated));
            }
        } catch (\Throwable $e) {
            $t->warn('Проверка через загрузчик Joomla пропущена: ' . $e->getMessage());
        }
    }
}
