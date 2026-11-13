<?php

/**
 * @package     Google Auth Tests
 * @license     GNU General Public License version 2 or later
 */

namespace TommiLin\Component\GoogleAuthTests\Administrator\Testing;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Version;
use Joomla\Registry\Registry as JRegistry;

/** Collects environment information for the report (never secrets). */
final class Environment
{
    /** @return array<int, array{title:string,rows:array<int, array{0:string,1:string,2:?string}>}> */
    public static function collect(): array
    {
        return [
            ['title' => 'Сервер и PHP', 'rows' => self::phpRows()],
            ['title' => 'Joomla', 'rows' => self::joomlaRows()],
            ['title' => 'Плагин Google Auth Login', 'rows' => self::pluginRows()],
            ['title' => 'Community Builder', 'rows' => self::cbRows()],
            ['title' => 'Этот инструмент', 'rows' => [
                self::row('Версия тестов', TestRegistry::TOOL_VERSION),
                self::row('Количество тестов', (string) count(TestRegistry::definitions())),
                self::row('Время на сервере', date('Y-m-d H:i:s T')),
            ]],
        ];
    }

    /** Non-secret plugin settings for the in-browser tests. */
    public static function browserParams(): array
    {
        $row = Harness::extensionRow();
        $params = $row ? new JRegistry((string) $row->params) : new JRegistry();

        return [
            'enabled'          => (bool) ($row->enabled ?? false),
            'offline'          => (bool) Factory::getApplication()->get('offline'),
            'configured'       => trim((string) $params->get('client_id', '')) !== '',
            'button_theme'     => (string) $params->get('button_theme', 'light'),
            'button_type'      => (string) $params->get('button_type', 'standard'),
            'button_shape'     => (string) $params->get('button_shape', 'rectangular'),
            'icon_align'       => (string) $params->get('icon_align', 'left'),
            'icon_match_height' => (bool) $params->get('icon_match_height', 1),
            'match_submit'     => (bool) $params->get('match_submit_style', 0),
            'force_consent'    => (bool) $params->get('force_google_consent', 1),
        ];
    }

    private static function row(string $label, string $value, ?string $level = null): array
    {
        return [$label, $value, $level];
    }

    private static function yn(bool $value): string
    {
        return $value ? 'да' : 'нет';
    }

    private static function guard(callable $fn, string $default = 'н/д'): string
    {
        try {
            $value = $fn();

            return $value === null || $value === '' ? $default : (string) $value;
        } catch (\Throwable) {
            return $default;
        }
    }

    private static function phpRows(): array
    {
        $rows = [
            self::row('PHP', PHP_VERSION, version_compare(PHP_VERSION, '8.1.0', '>=') ? 'ok' : 'warn'),
            self::row('SAPI', PHP_SAPI),
            self::row('ОС', php_uname('s') . ' ' . php_uname('r')),
            self::row('Веб-сервер', (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'н/д')),
            self::row('memory_limit', (string) ini_get('memory_limit')),
            self::row('max_execution_time', (string) ini_get('max_execution_time') . ' c'),
            self::row('Часовой пояс PHP', date_default_timezone_get()),
            self::row('OPcache', self::yn(function_exists('opcache_get_status') && (bool) ini_get('opcache.enable'))),
            self::row('allow_url_fopen', self::yn((bool) ini_get('allow_url_fopen'))),
        ];

        foreach (['curl', 'gd', 'mbstring', 'openssl', 'intl', 'zip', 'json', 'dom', 'simplexml'] as $ext) {
            $loaded = extension_loaded($ext);
            $rows[] = self::row('ext-' . $ext, self::yn($loaded), $loaded || !in_array($ext, ['gd', 'curl'], true) ? ($loaded ? 'ok' : 'warn') : 'warn');
        }

        return $rows;
    }

    private static function joomlaRows(): array
    {
        $app = Factory::getApplication();
        $db = Harness::db();
        $version = new Version();
        $cfg = static fn (string $key): string => (string) $app->get($key);

        $rows = [
            self::row('Версия Joomla', $version->getShortVersion(), Version::MAJOR_VERSION >= 4 ? 'ok' : 'warn'),
            self::row('Сборка', self::guard(fn () => $version->getLongVersion())),
            self::row('Приложение', self::guard(fn () => $app->getName())),
            self::row('СУБД', self::guard(fn () => $db->getServerType() . ' ' . $db->getVersion())),
            self::row('Драйвер БД', get_class($db)),
            self::row('Кодировка БД', self::guard(fn () => $db->getCollation())),
            self::row('Префикс таблиц', $db->getPrefix()),
            self::row('URL сайта', Harness::siteRoot(), str_starts_with(Harness::siteRoot(), 'https://') ? 'ok' : 'warn'),
            self::row('Язык сайта / админки', self::guard(fn () => ComponentHelper::getParams('com_languages')->get('site') . ' / ' . ComponentHelper::getParams('com_languages')->get('administrator'))),
            self::row('Шаблон сайта', self::guard(fn () => (string) $db->setQuery(
                $db->getQuery(true)->select($db->quoteName('template'))->from($db->quoteName('#__template_styles'))
                    ->where($db->quoteName('client_id') . ' = 0')->where($db->quoteName('home') . ' <> ' . $db->quote('0'))
            )->loadResult())),
            self::row('Режим отладки', self::yn((bool) $app->get('debug'))),
            self::row('Кэширование', $cfg('caching') === '' ? '0' : $cfg('caching')),
            self::row('SEF / rewrite', self::yn((bool) $app->get('sef')) . ' / ' . self::yn((bool) $app->get('sef_rewrite'))),
            self::row('Force SSL', $cfg('force_ssl') === '' ? '0' : $cfg('force_ssl')),
            self::row('Обработчик сессий', $cfg('session_handler')),
            self::row('Почта (mailer / from)', $cfg('mailer') . ' / ' . $cfg('mailfrom')),
            self::row('Папка логов', $cfg('log_path')),
            self::row('Папка логов доступна на запись', self::yn(is_writable((string) $app->get('log_path'))), is_writable((string) $app->get('log_path')) ? 'ok' : 'warn'),
            self::row('Сайт отключён (offline)', self::yn((bool) $app->get('offline')), $app->get('offline') ? 'warn' : null),
        ];

        return $rows;
    }

    private static function pluginRows(): array
    {
        $row = Harness::extensionRow();

        if ($row === null) {
            return [self::row('Плагин', 'не найден в #__extensions', 'warn')];
        }

        $xml = Harness::readXml();
        $manifest = json_decode((string) $row->manifest_cache, true) ?: [];
        $params = new JRegistry((string) $row->params);
        $clientId = trim((string) $params->get('client_id', ''));
        $secret = trim((string) $params->get('client_secret', ''));
        $xmlVersion = $xml ? (string) $xml->version : 'н/д';
        $dbVersion = (string) ($manifest['version'] ?? 'н/д');
        $logFile = rtrim((string) Factory::getApplication()->get('log_path'), '/\\') . '/googleauth.log.php';

        return [
            self::row('ID расширения', (string) $row->extension_id),
            self::row('Включён', self::yn((bool) $row->enabled), $row->enabled ? 'ok' : 'warn'),
            self::row('Версия (googleauth.xml)', $xmlVersion),
            self::row('Версия (в базе)', $dbVersion, $xmlVersion === $dbVersion ? 'ok' : 'warn'),
            self::row('Класс плагина загружается', self::yn(class_exists(Harness::PLUGIN_CLASS)), class_exists(Harness::PLUGIN_CLASS) ? 'ok' : 'warn'),
            self::row('Client ID', $clientId === '' ? 'не задан' : substr($clientId, 0, 6) . '…' . (str_ends_with($clientId, '.apps.googleusercontent.com') ? '.apps.googleusercontent.com' : ''), $clientId === '' ? 'warn' : 'ok'),
            self::row('Client Secret', $secret === '' ? 'не задан' : 'задан', $secret === '' ? 'warn' : 'ok'),
            self::row('Схема / тип / форма кнопки', $params->get('button_theme', 'light') . ' / ' . $params->get('button_type', 'standard') . ' / ' . $params->get('button_shape', 'rectangular')),
            self::row('Окно согласия сайта', self::yn((bool) $params->get('require_consent', 1))),
            self::row('Экран согласия Google', self::yn((bool) $params->get('force_google_consent', 1))),
            self::row('После входа / регистрации', $params->get('redirect_login', 'current') . ' / ' . $params->get('redirect_register', 'same')),
            self::row('Уведомления админам', self::yn((bool) $params->get('notify_admins', 0))),
            self::row('Лог плагина', is_file($logFile) ? basename($logFile) . ', ' . number_format((int) filesize($logFile)) . ' байт' : 'ещё не создан'),
        ];
    }

    private static function cbRows(): array
    {
        $db = Harness::db();

        try {
            $query = $db->getQuery(true)->select('*')->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_comprofiler'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'));
            $row = $db->setQuery($query)->loadObject();
            $tables = $db->getTableList();
        } catch (\Throwable) {
            return [self::row('Community Builder', 'не удалось проверить', 'warn')];
        }

        if ($row === null) {
            return [self::row('Community Builder', 'не установлен (тесты CB будут пропущены)')];
        }

        $manifest = json_decode((string) $row->manifest_cache, true) ?: [];

        return [
            self::row('Версия', (string) ($manifest['version'] ?? 'н/д')),
            self::row('Включён', self::yn((bool) $row->enabled), $row->enabled ? 'ok' : 'warn'),
            self::row('Таблица #__comprofiler', self::yn(in_array($db->getPrefix() . 'comprofiler', $tables, true))),
        ];
    }
}
