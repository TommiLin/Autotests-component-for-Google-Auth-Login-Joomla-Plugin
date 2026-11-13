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
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Version;
use Joomla\Registry\Registry as JRegistry;

final class InstallationSuite
{
    public const GROUP_ID = 'install';
    public const GROUP_TITLE = 'Установка и настройка';

    /** Fields the current plugin version must declare, with the default where it matters. */
    private const EXPECTED_FIELDS = [
        'client_id' => null, 'client_secret' => null,
        'button_theme' => 'light', 'button_type' => 'standard', 'icon_align' => 'left', 'icon_match_height' => '1',
        'button_shape' => 'rectangular', 'match_submit_style' => '0',
        'redirect_login' => 'current', 'redirect_login_url' => null, 'redirect_register' => 'same', 'redirect_register_url' => null,
        'require_consent' => '1', 'force_google_consent' => '1', 'privacy_link' => null, 'terms_link' => null,
        'privacy_version' => null, 'terms_version' => null,
        'notify_admins' => '0', 'admin_notify_ids' => null,
    ];

    /** Private methods the other suites call by reflection. */
    private const EXPECTED_METHODS = [
        'resolveGoogleAccount', 'describeExistingAccount', 'getRenewalReason', 'createGoogleUser', 'notifyAdminsAboutNewUser',
        'saveGoogleAuthData', 'loginUser', 'hasAcceptedTerms', 'findUserByGoogleId', 'findUserByEmail', 'makeUniqueUsername',
        'isCommunityBuilderAvailable', 'syncCommunityBuilderUser', 'getCallbackUrl', 'resolveRedirectUrl', 'getProfileUrl',
        'normalizeCustomUrl', 'storeOauthState', 'consumeOauthState', 'log',
    ];

    public static function tests(): array
    {
        return [
            ['id' => 'install.requirements', 'method' => 'requirements', 'title' => 'Требования к окружению', 'desc' => 'PHP 8.1+, Joomla 4+, расширения json/mbstring/openssl, curl или allow_url_fopen, GD.'],
            ['id' => 'install.files', 'method' => 'files', 'title' => 'Файлы плагина на месте', 'desc' => 'googleauth.xml, provider, класс плагина и языковые файлы en-GB / ru-RU / uk-UA.'],
            ['id' => 'install.manifest', 'method' => 'manifest', 'title' => 'Манифест googleauth.xml', 'desc' => 'Корректный XML, все ожидаемые поля и их значения по умолчанию, вкладки настроек.'],
            ['id' => 'install.registered', 'method' => 'registered', 'title' => 'Плагин зарегистрирован и включён', 'desc' => 'Строка в #__extensions, включён, версия в базе совпадает с версией в XML.'],
            ['id' => 'install.boot', 'method' => 'boot', 'title' => 'Плагин загружается', 'desc' => 'Класс подключается, подписан на нужные события, нужные методы на месте.'],
            ['id' => 'install.params', 'method' => 'params', 'title' => 'Сохранённые настройки корректны', 'desc' => 'Значения списков допустимы, Client ID похож на настоящий, ссылки и ID админов валидны.'],
            ['id' => 'install.site', 'method' => 'site', 'title' => 'Сайт готов к OAuth', 'desc' => 'HTTPS, корректный redirect URI, папка логов доступна на запись.'],
        ];
    }

    public function requirements(TestContext $t): void
    {
        $t->ok(version_compare(PHP_VERSION, '8.1.0', '>='), 'PHP ' . PHP_VERSION . ' (нужен 8.1+)', 'PHP ' . PHP_VERSION . ': плагину нужен 8.1+');
        $t->ok(Version::MAJOR_VERSION >= 4, 'Joomla ' . (new Version())->getShortVersion(), 'Joomla ' . (new Version())->getShortVersion() . ': нужна 4.x или новее');

        foreach (['json', 'mbstring', 'openssl'] as $ext) {
            $t->ok(extension_loaded($ext), 'ext-' . $ext . ' загружено', 'ext-' . $ext . ' не загружено');
        }

        if (!extension_loaded('curl') && !(bool) ini_get('allow_url_fopen')) {
            $t->fail('Нет ни cURL, ни allow_url_fopen: плагин не сможет обращаться к Google');
        } else {
            $t->ok(true, 'Есть способ делать исходящие HTTP-запросы (cURL: ' . (extension_loaded('curl') ? 'да' : 'нет') . ', allow_url_fopen: ' . ((bool) ini_get('allow_url_fopen') ? 'да' : 'нет') . ')');
        }

        if (!extension_loaded('gd')) {
            $t->warn('Нет расширения GD: аватар Community Builder сохранится без конвертации в JPEG и без миниатюры нужного размера');
        } else {
            $t->ok(true, 'ext-gd загружено');
        }
    }

    public function files(TestContext $t): void
    {
        $dir = Harness::pluginDir();
        $t->ok(is_dir($dir), 'Папка плагина: ' . $dir, 'Папка плагина не найдена: ' . $dir);

        $files = ['googleauth.xml', 'services/provider.php', 'src/Extension/Googleauth.php'];

        foreach (['en-GB', 'ru-RU', 'uk-UA'] as $lang) {
            $files[] = 'language/' . $lang . '/plg_system_googleauth.ini';
            $files[] = 'language/' . $lang . '/plg_system_googleauth.sys.ini';
        }

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            $t->ok(is_file($path) && is_readable($path), $file, 'Нет файла или он не читается: ' . $file);
        }
    }

    public function manifest(TestContext $t): void
    {
        $xml = Harness::readXml();

        if ($xml === null) {
            $t->fail('googleauth.xml не найден или не является корректным XML');

            return;
        }

        $t->same('plugin', (string) $xml['type'], 'type');
        $t->same('system', (string) $xml['group'], 'group');
        $t->ok(trim((string) $xml->version) !== '', 'Версия в XML: ' . (string) $xml->version, 'В XML нет версии');
        $t->ok(trim((string) $xml->namespace) !== '', 'Namespace: ' . (string) $xml->namespace, 'В XML нет тега namespace');

        $fields = [];

        foreach ($xml->xpath('//field') ?: [] as $field) {
            $fields[(string) $field['name']] = $field;
        }

        foreach (self::EXPECTED_FIELDS as $name => $default) {
            if (!isset($fields[$name])) {
                $t->fail('Нет поля «' . $name . '» в манифесте (установлена старая версия плагина?)');
                continue;
            }

            if ($default !== null && (string) $fields[$name]['default'] !== $default) {
                $t->fail('Поле «' . $name . '»: значение по умолчанию ' . TestContext::show((string) $fields[$name]['default']) . ', ожидалось ' . TestContext::show($default));
            }
        }

        $t->ok(true, 'Проверено полей: ' . count(self::EXPECTED_FIELDS));

        $extra = array_diff(array_keys($fields), array_keys(self::EXPECTED_FIELDS));

        if ($extra !== []) {
            $t->info('Дополнительные поля, которых тесты не знают: ' . implode(', ', $extra));
        }

        $fieldsets = array_map(static fn ($f) => (string) $f['name'], $xml->xpath('//fieldset') ?: []);
        $missing = array_diff(['basic', 'appearance', 'redirects', 'privacy', 'notifications'], $fieldsets);
        $t->ok($missing === [], 'Вкладки настроек: ' . implode(', ', $fieldsets), 'Нет вкладок: ' . implode(', ', $missing));
    }

    public function registered(TestContext $t): void
    {
        $row = Harness::extensionRow();

        if ($row === null) {
            $t->fail('Плагин не найден в #__extensions (type=plugin, folder=system, element=googleauth)');

            return;
        }

        $t->ok(true, 'Расширение #' . $row->extension_id);
        $t->ok((int) $row->enabled === 1, 'Плагин включён', 'Плагин выключен: включите его в Система → Плагины');

        $xml = Harness::readXml();
        $manifest = json_decode((string) $row->manifest_cache, true) ?: [];
        $dbVersion = (string) ($manifest['version'] ?? '');
        $xmlVersion = $xml ? (string) $xml->version : '';

        if ($xmlVersion !== '' && $dbVersion !== '') {
            $t->ok($xmlVersion === $dbVersion, 'Версия в базе совпадает с XML: ' . $dbVersion, 'Версия в базе (' . $dbVersion . ') не совпадает с версией в XML (' . $xmlVersion . '): обновление применено не полностью, переустановите пакет');
        } else {
            $t->warn('Не удалось сравнить версии (XML: ' . $xmlVersion . ', база: ' . $dbVersion . ')');
        }
    }

    public function boot(TestContext $t): void
    {
        $t->ok(class_exists(Harness::PLUGIN_CLASS), 'Класс плагина автозагружается', 'Класс плагина не загружается: проверьте namespace в манифесте и очистите кэш (Система → Очистить кэш)');
        $plugin = Harness::requirePlugin();
        $t->same(Harness::PLUGIN_CLASS, get_class($plugin), 'Экземпляр плагина');

        $events = array_keys(call_user_func([Harness::PLUGIN_CLASS, 'getSubscribedEvents']));
        sort($events);
        $t->same(['onAfterInitialise', 'onAfterRender'], $events, 'События');

        $missing = [];

        foreach (self::EXPECTED_METHODS as $method) {
            if (!method_exists($plugin, $method)) {
                $missing[] = $method;
            }
        }

        $t->ok($missing === [], 'Все ' . count(self::EXPECTED_METHODS) . ' методов, на которые опираются тесты, на месте', 'Не найдены методы: ' . implode(', ', $missing) . ' (версия плагина не соответствует тестам)');
    }

    public function params(TestContext $t): void
    {
        $row = Harness::extensionRow();

        if ($row === null) {
            $t->skip('Плагин не установлен.');
        }

        $raw = (string) $row->params;
        $decoded = $raw === '' ? [] : json_decode($raw, true);
        $t->ok(is_array($decoded), 'Параметры в базе — корректный JSON', 'Параметры в базе — повреждённый JSON');

        if (!is_array($decoded)) {
            return;
        }

        $params = new JRegistry($decoded);
        $xml = Harness::readXml();

        if ($xml !== null) {
            foreach ($xml->xpath('//field') ?: [] as $field) {
                $type = (string) $field['type'];
                $name = (string) $field['name'];

                if (!in_array($type, ['list', 'radio'], true) || !$params->exists($name)) {
                    continue;
                }

                $allowed = array_map(static fn ($o) => (string) $o['value'], $field->xpath('option') ?: []);
                $value = (string) $params->get($name);
                $t->ok(in_array($value, $allowed, true), $name . ' = ' . TestContext::show($value), $name . ' = ' . TestContext::show($value) . ': допустимо только ' . implode(' / ', $allowed));
            }
        }

        $clientId = trim((string) $params->get('client_id', ''));

        if ($clientId === '') {
            $t->warn('Client ID не задан: кнопка Google не показывается');
        } elseif (!preg_match('/^\d+-[a-z0-9]+\.apps\.googleusercontent\.com$/i', $clientId)) {
            $t->warn('Client ID не похож на идентификатор из Google Cloud Console (ожидается «…apps.googleusercontent.com»)');
        } else {
            $t->ok(true, 'Client ID выглядит корректно');
        }

        trim((string) $params->get('client_secret', '')) === '' ? $t->warn('Client Secret не задан: вход через Google не заработает') : $t->ok(true, 'Client Secret задан');

        foreach (['privacy_link', 'terms_link'] as $name) {
            $value = trim((string) $params->get($name, ''));

            if ($value !== '') {
                $t->ok((bool) filter_var($value, FILTER_VALIDATE_URL) && (bool) preg_match('#^https?://#i', $value), $name . ': корректный URL', $name . ': нужен полный адрес http(s)://…, плагин игнорирует другие значения');
            } elseif ((bool) $params->get('require_consent', 1)) {
                $t->warn($name . ' не задана: на окне согласия не будет ссылки');
            }
        }

        $ids = trim((string) $params->get('admin_notify_ids', ''));

        if ((bool) $params->get('notify_admins', 0)) {
            $valid = $ids !== '' && preg_match('/^[\d\s,;]+$/', $ids) === 1;
            $t->ok($valid, 'admin_notify_ids: ' . TestContext::show($ids), 'Уведомления включены, но admin_notify_ids пусто или содержит не только ID');
        }

        foreach (['login' => 'redirect_login', 'register' => 'redirect_register'] as $label => $name) {
            if ((string) $params->get($name) === 'custom' && trim((string) $params->get($name . '_url', '')) === '') {
                $t->warn($name . ' = custom, но адрес пуст: пользователь попадёт на главную');
            }
        }

        $known = [];

        foreach ($xml ? ($xml->xpath('//field') ?: []) : [] as $field) {
            $known[] = (string) $field['name'];
        }

        $unknown = array_diff(array_keys($decoded), $known);

        if ($unknown !== []) {
            $t->info('Неизвестные ключи в сохранённых настройках: ' . implode(', ', $unknown));
        }
    }

    public function site(TestContext $t): void
    {
        $root = Harness::siteRoot();
        $https = str_starts_with($root, 'https://');
        $https ? $t->ok(true, 'Сайт работает по HTTPS: ' . $root) : $t->warn('Сайт открыт по http: ' . $root . ' — Google требует https для redirect URI, плагин всегда строит адрес с https://');

        $plugin = Harness::requirePlugin();
        $callback = (string) Harness::call($plugin, 'getCallbackUrl');
        $t->ok(str_starts_with($callback, 'https://'), 'Redirect URI: ' . $callback, 'Redirect URI не https: ' . $callback);
        $t->ok(str_ends_with($callback, 'index.php?option=com_ajax&plugin=googleauth&group=system&format=raw'), 'Redirect URI оканчивается на com_ajax-эндпойнт плагина', 'Redirect URI имеет неожиданный вид: ' . $callback);
        $t->info('Добавьте именно этот адрес в «Authorized redirect URIs» в Google Cloud Console.');

        $logPath = (string) Factory::getApplication()->get('log_path');
        $t->ok(is_dir($logPath) && is_writable($logPath), 'Папка логов доступна на запись: ' . $logPath, 'Папка логов недоступна на запись: ' . $logPath);
    }
}
