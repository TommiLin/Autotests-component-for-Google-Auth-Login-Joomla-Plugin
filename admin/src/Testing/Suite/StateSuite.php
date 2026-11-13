<?php

/**
 * @package     Google Auth Tests
 * @license     GNU General Public License version 2 or later
 */

namespace TommiLin\Component\GoogleAuthTests\Administrator\Testing\Suite;

\defined('_JEXEC') or die;

use TommiLin\Component\GoogleAuthTests\Administrator\Testing\Harness;
use TommiLin\Component\GoogleAuthTests\Administrator\Testing\TestContext;

final class StateSuite
{
    public const GROUP_ID = 'state';
    public const GROUP_TITLE = 'OAuth state (защита от CSRF)';

    public static function tests(): array
    {
        return [
            ['id' => 'state.lifecycle', 'method' => 'lifecycle', 'title' => 'State одноразовый', 'desc' => 'Сохранённый state принимается один раз, повторное использование и чужой state отклоняются.'],
            ['id' => 'state.expiry', 'method' => 'expiry', 'title' => 'State истекает', 'desc' => 'Старше STATE_TTL (10 минут) — отклоняется, свежий — принимается.'],
            ['id' => 'state.multi', 'method' => 'multi', 'title' => 'Несколько вкладок', 'desc' => 'Несколько states действуют одновременно и не мешают друг другу.'],
            ['id' => 'state.garbage', 'method' => 'garbage', 'title' => 'Мусорные значения', 'desc' => 'Пустой, слишком длинный и «хитрый» state не принимаются и не вызывают ошибок.'],
        ];
    }

    public function lifecycle(TestContext $t): void
    {
        $plugin = Harness::requirePlugin();
        Harness::useFakeApp();
        $state = bin2hex(random_bytes(32));

        Harness::call($plugin, 'storeOauthState', [$state]);
        $t->same(true, Harness::call($plugin, 'consumeOauthState', [$state]), 'Первое использование');
        $t->same(false, Harness::call($plugin, 'consumeOauthState', [$state]), 'Повторное использование (replay)');
        $t->same(false, Harness::call($plugin, 'consumeOauthState', [bin2hex(random_bytes(32))]), 'Неизвестный state');
    }

    public function expiry(TestContext $t): void
    {
        $plugin = Harness::requirePlugin();
        $app = Harness::useFakeApp();
        $ttl = (int) Harness::constant('STATE_TTL');
        $old = bin2hex(random_bytes(32));
        $fresh = bin2hex(random_bytes(32));
        $app->getSession()->set(Harness::constant('STATE_KEY'), [$old => time() - $ttl - 5, $fresh => time() - $ttl + 30]);

        $t->same(false, Harness::call($plugin, 'consumeOauthState', [$old]), 'State старше ' . $ttl . ' с');
        $t->same(true, Harness::call($plugin, 'consumeOauthState', [$fresh]), 'State моложе ' . $ttl . ' с');

        $expired = bin2hex(random_bytes(32));
        $app->getSession()->set(Harness::constant('STATE_KEY'), [$expired => time() - $ttl - 5]);
        Harness::call($plugin, 'storeOauthState', [bin2hex(random_bytes(32))]);
        $left = (array) $app->getSession()->get(Harness::constant('STATE_KEY'), []);
        $t->ok(!isset($left[$expired]), 'Просроченные states чистятся при сохранении нового', 'Просроченный state остался в сессии');
    }

    public function multi(TestContext $t): void
    {
        $plugin = Harness::requirePlugin();
        Harness::useFakeApp();
        $states = [bin2hex(random_bytes(32)), bin2hex(random_bytes(32)), bin2hex(random_bytes(32))];

        foreach ($states as $state) {
            Harness::call($plugin, 'storeOauthState', [$state]);
        }

        $t->same(true, Harness::call($plugin, 'consumeOauthState', [$states[1]]), 'Средний state принят');
        $t->same(true, Harness::call($plugin, 'consumeOauthState', [$states[0]]), 'Первый state всё ещё действует');
        $t->same(true, Harness::call($plugin, 'consumeOauthState', [$states[2]]), 'Последний state всё ещё действует');
        $t->same(false, Harness::call($plugin, 'consumeOauthState', [$states[1]]), 'Средний state повторно не принимается');
    }

    public function garbage(TestContext $t): void
    {
        $plugin = Harness::requirePlugin();
        Harness::useFakeApp();
        Harness::call($plugin, 'storeOauthState', [bin2hex(random_bytes(32))]);

        foreach (['' => 'пустая строка', str_repeat('a', 5000) => '5000 символов', "' OR 1=1 --" => 'SQL-подобная строка', '../../etc/passwd' => 'путь', '0' => '"0"', "\0" => 'нулевой байт'] as $value => $label) {
            $t->same(false, Harness::call($plugin, 'consumeOauthState', [(string) $value]), $label);
        }
    }
}
