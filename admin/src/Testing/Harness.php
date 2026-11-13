<?php

/**
 * @package     Google Auth Tests
 * @license     GNU General Public License version 2 or later
 */

namespace TommiLin\Component\GoogleAuthTests\Administrator\Testing;

\defined('_JEXEC') or die;

use TommiLin\Component\GoogleAuthTests\Administrator\Testing\Support\CapturingMailerFactory;
use TommiLin\Component\GoogleAuthTests\Administrator\Testing\Support\FakeSiteApplication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ContainerResource;

/**
 * Shared helpers for the suites: plugin access (including private methods), a fake front-end
 * application, in-memory parameter overrides, a capturing mailer and temporary test users.
 * Everything changed here is reverted by restore()/cleanup() after each test.
 */
final class Harness
{
    public const PLUGIN_CLASS = 'Joomla\\Plugin\\System\\Googleauth\\Extension\\Googleauth';
    public const TEMP_EMAIL_LIKE = 'gat-%@example.invalid';

    /** @var array<int, callable> */
    private static array $restorers = [];
    /** @var array<int, int> */
    private static array $tempUserIds = [];
    /** @var array<int, string> */
    private static array $tempFiles = [];
    private static ?object $plugin = null;
    private static ?CapturingMailerFactory $mailCapture = null;

    public static function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    /** Site root URL with a trailing slash, never ending in /administrator/. */
    public static function siteRoot(): string
    {
        return preg_replace('#/administrator/?$#i', '/', Uri::root()) ?: Uri::root();
    }

    public static function pluginDir(): string
    {
        return JPATH_PLUGINS . '/system/googleauth';
    }

    public static function readXml(): ?\SimpleXMLElement
    {
        $file = self::pluginDir() . '/googleauth.xml';

        if (!is_file($file)) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($file);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $xml ?: null;
    }

    public static function extensionRow(): ?object
    {
        $db = self::db();
        $query = $db->getQuery(true)->select('*')->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('googleauth'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'));

        return $db->setQuery($query)->loadObject() ?: null;
    }

    /** The booted plugin instance, or null when it is missing/disabled. */
    public static function plugin(): ?object
    {
        if (self::$plugin !== null) {
            return self::$plugin;
        }

        $row = self::extensionRow();

        if ($row === null || !(int) $row->enabled || !class_exists(self::PLUGIN_CLASS)) {
            return null;
        }

        try {
            self::$plugin = Factory::getApplication()->bootPlugin('googleauth', 'system');
        } catch (\Throwable) {
            return null;
        }

        return self::$plugin;
    }

    public static function requirePlugin(): object
    {
        $plugin = self::plugin();

        if ($plugin === null) {
            throw new SkipException('Плагин не найден, выключен или его класс не загружается (см. «Установка и настройка»).');
        }

        return $plugin;
    }

    /** Calls a private/protected method through reflection. */
    public static function call(object $object, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $args);
    }

    public static function constant(string $name): mixed
    {
        return (new \ReflectionClassConstant(self::PLUGIN_CLASS, $name))->getValue();
    }

    /** Overrides plugin parameters in memory only (never saved); reverted by restore(). */
    public static function setParams(array $values): void
    {
        $plugin = self::requirePlugin();
        $saved = [];

        foreach ($values as $key => $value) {
            $saved[$key] = $plugin->params->exists($key) ? [true, $plugin->params->get($key)] : [false, null];
            $plugin->params->set($key, $value);
        }

        self::$restorers[] = static function () use ($plugin, $saved): void {
            foreach ($saved as $key => [$existed, $value]) {
                $existed ? $plugin->params->set($key, $value) : $plugin->params->remove($key);
            }
        };
    }

    /** Makes the plugin use an isolated fake front-end application until restore(). */
    public static function useFakeApp(array $options = []): FakeSiteApplication
    {
        $plugin = self::requirePlugin();
        $real = Factory::getApplication();
        $fake = FakeSiteApplication::create($options);
        $plugin->setApplication($fake);

        self::$restorers[] = static function () use ($plugin, $real): void {
            $plugin->setApplication($real);
        };

        return $fake;
    }

    /** Replaces the mailer factory in the DI container with a capturing one until restore(). */
    public static function captureMail(): CapturingMailerFactory
    {
        if (self::$mailCapture !== null) {
            return self::$mailCapture;
        }

        if (!interface_exists(MailerFactoryInterface::class)) {
            throw new SkipException('MailerFactoryInterface есть только в Joomla 4.4+.');
        }

        $container = Factory::getContainer();
        $key = MailerFactoryInterface::class;
        $property = new \ReflectionProperty(Container::class, 'resources');
        $property->setAccessible(true);
        $resources = $property->getValue($container);

        if (!isset($resources[$key])) {
            throw new SkipException('Фабрика почты не зарегистрирована в контейнере.');
        }

        $original = $resources[$key];
        $fake = new CapturingMailerFactory();
        $resources[$key] = new ContainerResource($container, $fake, ContainerResource::SHARE);
        $property->setValue($container, $resources);
        self::$mailCapture = $fake;

        self::$restorers[] = static function () use ($property, $container, $key, $original): void {
            $current = $property->getValue($container);
            $current[$key] = $original;
            $property->setValue($container, $current);
            self::$mailCapture = null;
        };

        return $fake;
    }

    /**
     * Runs the plugin's onAfterRender() on $html in the given fake application and parses the result.
     *
     * @return array<string,mixed>
     */
    public static function renderIn(FakeSiteApplication $app, string $html, array $params = []): array
    {
        $plugin = self::requirePlugin();

        self::setParams($params + ['client_id' => '111111111111-gattest.apps.googleusercontent.com']);
        $app->setBody($html);
        $plugin->onAfterRender();

        return self::parseRender($html, $app->getBody());
    }

    /** Renders once in a brand-new fake application (own session). */
    public static function render(string $html, array $params = [], array $appOptions = []): array
    {
        $app = self::useFakeApp($appOptions);
        $result = self::renderIn($app, $html, $params);
        $result['app'] = $app;

        return $result;
    }

    public static function parseRender(string $original, string $body): array
    {
        $result = [
            'injected'  => $body !== $original,
            'body'      => $body,
            'markup'    => null,
            'script'    => null,
            'css'       => null,
            'link'      => null,
            'container' => null,
            'href'      => '',
            'query'     => [],
        ];

        if (preg_match('/const buttonMarkup = ("(?:[^"\\\\]|\\\\.)*");/', $body, $m)) {
            $result['markup'] = json_decode($m[1], false);
        }

        if (preg_match('#<script>\s*\(function \(\) \{.*?\}\)\(\);\s*</script>#s', $body, $m)) {
            $result['script'] = $m[0];
        }

        if (preg_match('#<style>.*?google-login-btn.*?</style>#s', $body, $m)) {
            $result['css'] = $m[0];
        }

        if (is_string($result['markup'])) {
            $previous = libxml_use_internal_errors(true);
            $dom = new \DOMDocument();
            $dom->loadHTML('<?xml encoding="utf-8"?><body>' . $result['markup'] . '</body>');
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            $xpath = new \DOMXPath($dom);
            $container = $xpath->query('//div[contains(@class,"google-auth-container")]')->item(0);
            $link = $xpath->query('//a')->item(0);

            if ($container instanceof \DOMElement && $link instanceof \DOMElement) {
                $result['container'] = $container;
                $result['link'] = $link;
                $result['href'] = $link->getAttribute('href');
                parse_str((string) parse_url($result['href'], PHP_URL_QUERY), $result['query']);
            }
        }

        return $result;
    }

    /** Value of a CSS property in an inline style string, or null. */
    public static function cssProp(string $style, string $prop): ?string
    {
        return preg_match('/(?:^|;)\s*' . preg_quote($prop, '/') . '\s*:\s*([^;]+)/i', $style, $m) ? trim($m[1]) : null;
    }

    public static function registeredGroupId(): int
    {
        $id = (int) ComponentHelper::getParams('com_users')->get('new_usertype', 2);

        return $id > 0 ? $id : 2;
    }

    public static function createTempUser(array $options = []): User
    {
        // Saving a user in the administrator may make Joomla e-mail the new user: never let that out.
        try {
            self::captureMail();
        } catch (SkipException) {
            // Joomla < 4.4: no mailer factory to replace; temp addresses use the reserved .invalid TLD.
        }

        $token = bin2hex(random_bytes(4));
        $user = new User();
        $user->set('name', $options['name'] ?? 'GAT Test ' . $token);
        $user->set('username', $options['username'] ?? 'gat_' . $token);
        $user->set('email', $options['email'] ?? 'gat-' . $token . '@example.invalid');
        $user->set('password', UserHelper::hashPassword(bin2hex(random_bytes(16))));
        $user->set('groups', $options['groups'] ?? [self::registeredGroupId()]);
        $user->set('block', (int) ($options['block'] ?? 0));
        $user->set('activation', '');
        $user->set('registerDate', Factory::getDate()->toSql());

        foreach (($options['params'] ?? []) as $key => $value) {
            $user->setParam($key, $value);
        }

        if (!$user->save()) {
            throw new \RuntimeException('Не удалось создать временного пользователя: ' . $user->getError());
        }

        self::$tempUserIds[] = (int) $user->id;

        return self::loadUser((int) $user->id);
    }

    public static function loadUser(int $id): User
    {
        return Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($id);
    }

    public static function userRow(int $id): ?object
    {
        $db = self::db();
        $query = $db->getQuery(true)->select('*')->from($db->quoteName('#__users'))->where($db->quoteName('id') . ' = ' . (int) $id);

        return $db->setQuery($query)->loadObject() ?: null;
    }

    /** A fake Google profile as the plugin's callback would pass it on. */
    public static function profile(array $override = []): array
    {
        $token = bin2hex(random_bytes(4));

        return $override + [
            'email'     => 'gat-' . $token . '@example.invalid',
            'name'      => 'GAT Profile ' . $token,
            'googleId'  => 'gat-sub-' . $token,
            'avatarUrl' => '',
        ];
    }

    public static function trackFile(string $path): void
    {
        self::$tempFiles[] = $path;
    }

    public static function hasTemporaryData(): bool
    {
        return self::$tempUserIds !== [] || self::$tempFiles !== [];
    }

    public static function restore(): void
    {
        while (($restorer = array_pop(self::$restorers)) !== null) {
            $restorer();
        }
    }

    /** Deletes every temporary user (tracked or found by the e-mail pattern) and related leftovers. */
    public static function cleanup(): array
    {
        $db = self::db();
        $report = ['users' => 0, 'cb_rows' => 0, 'files' => 0, 'action_logs' => 0];

        $query = $db->getQuery(true)->select($db->quoteName('id'))->from($db->quoteName('#__users'))
            ->where($db->quoteName('email') . ' LIKE ' . $db->quote(self::TEMP_EMAIL_LIKE));
        $ids = array_map('intval', $db->setQuery($query)->loadColumn() ?: []);
        $ids = array_values(array_unique(array_merge($ids, self::$tempUserIds)));
        $ids = array_filter($ids, static fn (int $id): bool => $id > 0);

        $tables = $db->getTableList();
        $prefix = $db->getPrefix();

        foreach ($ids as $id) {
            $deleted = false;

            try {
                $user = self::loadUser($id);
                $deleted = $user->id > 0 && $user->delete();
            } catch (\Throwable) {
                $deleted = false;
            }

            if (!$deleted && self::userRow($id) !== null) {
                $db->setQuery('DELETE FROM ' . $db->quoteName('#__user_usergroup_map') . ' WHERE ' . $db->quoteName('user_id') . ' = ' . $id)->execute();
                $db->setQuery('DELETE FROM ' . $db->quoteName('#__users') . ' WHERE ' . $db->quoteName('id') . ' = ' . $id)->execute();
            }

            if (self::userRow($id) === null) {
                $report['users']++;
            }

            if (in_array($prefix . 'comprofiler', $tables, true)) {
                $db->setQuery('DELETE FROM ' . $db->quoteName('#__comprofiler') . ' WHERE ' . $db->quoteName('user_id') . ' = ' . $id)->execute();
                $report['cb_rows'] += $db->getAffectedRows();
            }

            if (in_array($prefix . 'action_logs', $tables, true)) {
                $db->setQuery('DELETE FROM ' . $db->quoteName('#__action_logs') . ' WHERE ' . $db->quoteName('user_id') . ' = ' . $id)->execute();
                $report['action_logs'] += $db->getAffectedRows();
            }

            foreach (glob(JPATH_ROOT . '/images/comprofiler/{,tn}' . $id . '_*', GLOB_BRACE) ?: [] as $file) {
                if (@unlink($file)) {
                    $report['files']++;
                }
            }
        }

        foreach (self::$tempFiles as $file) {
            if (is_file($file) && @unlink($file)) {
                $report['files']++;
            }
        }

        self::$tempUserIds = [];
        self::$tempFiles = [];

        return ['status' => 'pass', 'removed' => $report, 'messages' => [[
            'level' => 'ok',
            'text'  => sprintf('Удалено: пользователей %d, строк CB %d, файлов %d, записей журнала действий %d', $report['users'], $report['cb_rows'], $report['files'], $report['action_logs']),
        ]]];
    }
}
