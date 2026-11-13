<?php

/**
 * @package     Google Auth Tests
 * @license     GNU General Public License version 2 or later
 */

namespace TommiLin\Component\GoogleAuthTests\Administrator\Testing\Suite;

\defined('_JEXEC') or die;

use TommiLin\Component\GoogleAuthTests\Administrator\Testing\Harness;
use TommiLin\Component\GoogleAuthTests\Administrator\Testing\TestContext;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Uri\Uri;

/**
 * Tests the redirect target logic ("After sign-in" settings). Note: tests run in the
 * administrator, so Uri::base() points to /administrator/ and the router is the admin one.
 */
final class RedirectSuite
{
    public const GROUP_ID = 'redirect';
    public const GROUP_TITLE = 'Редирект после входа';

    public static function tests(): array
    {
        return [
            ['id' => 'redirect.home_current', 'method' => 'homeAndCurrent', 'title' => 'Режимы «главная» и «текущая страница»', 'desc' => 'Параметры сохраняются; чужой сайт, страница входа и пустой адрес → главная.'],
            ['id' => 'redirect.profile', 'method' => 'profile', 'title' => 'Режим «личный кабинет»', 'desc' => 'Community Builder, если он есть и включён, иначе профиль Joomla.'],
            ['id' => 'redirect.custom', 'method' => 'custom', 'title' => 'Режим «свой URL»', 'desc' => 'Полный адрес, ссылка index.php?…, путь от корня сайта, пустое значение.'],
            ['id' => 'redirect.register', 'method' => 'register', 'title' => 'Отдельная настройка для регистрации', 'desc' => '«Как после входа» и собственные значения для нового пользователя.'],
        ];
    }

    private function resolve(string $returnUrl, bool $isNew = false): string
    {
        return (string) Harness::call(Harness::requirePlugin(), 'resolveRedirectUrl', [$returnUrl, $isNew]);
    }

    public function homeAndCurrent(TestContext $t): void
    {
        Harness::useFakeApp();
        $root = Uri::root();
        $internal = Uri::base() . 'index.php?option=com_content&view=article&id=5&Itemid=101';

        Harness::setParams(['redirect_login' => 'home']);
        $t->same($root, $this->resolve($internal), 'home → корень сайта');

        Harness::setParams(['redirect_login' => 'current']);
        $t->same($internal, $this->resolve($internal), 'current → та же страница вместе с параметрами');
        $t->same($root, $this->resolve('https://evil.example.com/phish'), 'current + чужой хост → главная');
        $t->same($root, $this->resolve(Uri::base() . 'index.php?option=com_users&view=login'), 'current + страница входа → главная');
        $t->same($root, $this->resolve(''), 'current + пустой адрес → главная');
        $t->same($root, $this->resolve('javascript:alert(1)'), 'current + javascript: → главная');
        $t->info('Тесты идут из админки, поэтому «свой» сайт — это ' . Uri::base());
    }

    public function profile(TestContext $t): void
    {
        $plugin = Harness::requirePlugin();
        Harness::useFakeApp();
        Harness::setParams(['redirect_login' => 'profile']);

        $cb = (bool) Harness::call($plugin, 'isCommunityBuilderAvailable') && ComponentHelper::isEnabled('com_comprofiler');
        $url = $this->resolve('');

        if ($cb) {
            $t->ok(str_contains($url, 'com_comprofiler'), 'Community Builder активен → ' . $url, 'Community Builder активен, но адрес не ведёт в CB: ' . $url);
        } else {
            $t->ok(str_contains($url, 'com_users'), 'Community Builder не активен → профиль Joomla: ' . $url, 'CB не активен, но адрес не ведёт в профиль Joomla: ' . $url);
        }

        $t->info('Community Builder считается активным, если есть таблица #__comprofiler и компонент включён: ' . ($cb ? 'да' : 'нет'));
    }

    public function custom(TestContext $t): void
    {
        Harness::useFakeApp();
        $root = Uri::root();
        $cases = [
            ['https://example.org/welcome?x=1', 'https://example.org/welcome?x=1', 'полный адрес'],
            ['/my/profile', rtrim($root, '/') . '/my/profile', 'путь от корня сайта'],
            ['cabinet', rtrim($root, '/') . '/cabinet', 'относительный путь'],
            ['', $root, 'пустое значение → главная'],
            ['   ', $root, 'пробелы → главная'],
        ];

        foreach ($cases as [$input, $expected, $label]) {
            Harness::setParams(['redirect_login' => 'custom', 'redirect_login_url' => $input]);
            $t->same($expected, $this->resolve(''), 'custom: ' . $label);
        }

        Harness::setParams(['redirect_login' => 'custom', 'redirect_login_url' => 'index.php?option=com_users&view=profile']);
        $t->ok(str_contains($this->resolve(''), 'option=com_users'), 'custom: ссылка index.php?… проходит через роутер Joomla', 'custom: ссылка index.php?… обработана неверно: ' . $this->resolve(''));
    }

    public function register(TestContext $t): void
    {
        Harness::useFakeApp();
        $root = Uri::root();
        $internal = Uri::base() . 'index.php?option=com_content&view=article&id=7';

        Harness::setParams(['redirect_login' => 'current', 'redirect_register' => 'same']);
        $t->same($internal, $this->resolve($internal, true), 'Новый пользователь + same → как при входе');

        Harness::setParams(['redirect_register' => 'home']);
        $t->same($root, $this->resolve($internal, true), 'Новый пользователь + home → главная');
        $t->same($internal, $this->resolve($internal, false), 'Вернувшийся пользователь не затронут настройкой регистрации');

        Harness::setParams(['redirect_register' => 'custom', 'redirect_register_url' => 'https://example.org/onboarding']);
        $t->same('https://example.org/onboarding', $this->resolve($internal, true), 'Новый пользователь + custom → свой адрес');

        Harness::setParams(['redirect_register' => 'current']);
        $t->same($internal, $this->resolve($internal, true), 'Новый пользователь + current → текущая страница');
    }
}
