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

/**
 * Black-box checks over HTTP: the server requests its own public URL like a visitor would.
 * They are skipped (not failed) if the server cannot reach itself.
 */
final class HttpSuite
{
    public const GROUP_ID = 'http';
    public const GROUP_TITLE = 'Сайт глазами посетителя (HTTP)';

    public static function tests(): array
    {
        return [
            ['id' => 'http.reachable', 'method' => 'reachable', 'title' => 'Страница входа открывается', 'desc' => 'Сервер может запросить собственную страницу входа (иначе остальные HTTP-тесты пропускаются).'],
            ['id' => 'http.button', 'method' => 'button', 'title' => 'Кнопка на живой странице', 'desc' => 'Гость видит кнопку Google; ссылка совпадает с настройками; state разный при каждом запросе.'],
            ['id' => 'http.callback', 'method' => 'callback', 'title' => 'Обратный вызов Google отклоняет подделки', 'desc' => 'Без параметров, с поддельным state, с error=access_denied: редирект на сайт, без ошибок 5xx и без утечек.'],
            ['id' => 'http.consent', 'method' => 'consent', 'title' => 'Окно согласия без сессии', 'desc' => 'Отправка согласия без действующей сессии и с чужим токеном ничего не создаёт.'],
            ['id' => 'http.leaks', 'method' => 'leaks', 'title' => 'Нет утечек и PHP-ошибок в ответах', 'desc' => 'Client secret не появляется на страницах; в ответах нет Fatal error / Warning / Stack trace.'],
        ];
    }

    private function loginUrl(): string
    {
        return Harness::siteRoot() . 'index.php?option=com_users&view=login';
    }

    /** @return array{status:int,headers:array<string,string>,body:string,error:string} */
    private function request(string $url, string $method = 'GET', array $post = [], bool $follow = false): array
    {
        if (!function_exists('curl_init')) {
            throw new \TommiLin\Component\GoogleAuthTests\Administrator\Testing\SkipException('Нет расширения cURL: HTTP-тесты пропущены.');
        }

        $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT      => 'GoogleAuthTests/1.0',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }

        $body = curl_exec($ch);
        $result = ['status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'headers' => $headers, 'body' => is_string($body) ? $body : '', 'error' => (string) curl_error($ch)];
        curl_close($ch);

        if ($follow && $result['status'] === 503 && (bool) Factory::getApplication()->get('offline')) {
            throw new \TommiLin\Component\GoogleAuthTests\Administrator\Testing\SkipException('Сайт отключён (offline): посетителю показывается страница «сайт закрыт» вместо формы входа. Включите сайт (Система → Общие настройки → Сайт отключён: Нет) и повторите.');
        }

        if ($result['status'] === 0) {
            throw new \TommiLin\Component\GoogleAuthTests\Administrator\Testing\SkipException('Сервер не может запросить собственный адрес (' . $result['error'] . '): HTTP-тесты пропущены. Это обычно настройка хостинга/DNS, а не ошибка плагина.');
        }

        return $result;
    }

    private function callbackUrl(array $query = []): string
    {
        // The endpoint is requested through the site's own scheme/host (the plugin's redirect URI is always https).
        $base = Harness::siteRoot() . 'index.php?option=com_ajax&plugin=googleauth&group=system&format=raw';

        return $base . ($query ? '&' . http_build_query($query) : '');
    }

    private function isRedirectToSite(array $r): bool
    {
        return in_array($r['status'], [301, 302, 303, 307, 308], true) && str_starts_with((string) ($r['headers']['location'] ?? ''), '/') || str_starts_with((string) ($r['headers']['location'] ?? ''), Harness::siteRoot());
    }

    public function reachable(TestContext $t): void
    {
        $r = $this->request($this->loginUrl(), 'GET', [], true);
        $t->ok($r['status'] === 200, 'Страница входа отвечает 200: ' . $this->loginUrl(), 'Страница входа ответила ' . $r['status']);
        $t->ok(stripos($r['body'], '<form') !== false, 'На странице есть форма', 'На странице нет ни одной формы');
    }

    public function button(TestContext $t): void
    {
        $plugin = Harness::requirePlugin();

        if (trim((string) $plugin->params->get('client_id', '')) === '') {
            $t->skip('Client ID не задан: кнопка на сайте не показывается.');
        }

        $first = $this->request($this->loginUrl(), 'GET', [], true);
        $second = $this->request($this->loginUrl(), 'GET', [], true);
        $t->ok(str_contains($first['body'], 'google-auth-container'), 'На странице входа есть кнопка Google', 'На странице входа нет кнопки Google (проверьте включение плагина, кэш страниц и шаблон)');

        $a = Harness::parseRender('', $first['body']);
        $b = Harness::parseRender('', $second['body']);

        if (!($a['link'] instanceof \DOMElement)) {
            $t->fail('Не удалось разобрать разметку кнопки на странице');

            return;
        }

        $t->same((string) $plugin->params->get('client_id'), $a['query']['client_id'] ?? '', 'client_id в ссылке совпадает с настройками');
        $redirect = (string) ($a['query']['redirect_uri'] ?? '');
        $t->ok(str_starts_with($redirect, 'https://') && str_ends_with($redirect, 'index.php?option=com_ajax&plugin=googleauth&group=system&format=raw'), 'redirect_uri на странице: ' . $redirect, 'redirect_uri на странице имеет неожиданный вид: ' . $redirect);
        $t->same((string) parse_url(Harness::siteRoot(), PHP_URL_HOST), (string) parse_url($redirect, PHP_URL_HOST), 'Хост redirect_uri совпадает с хостом сайта');
        $t->info('Именно этот адрес должен быть в «Authorized redirect URIs» в Google Cloud Console.');
        $t->same((bool) $plugin->params->get('force_google_consent', 1) ? 'select_account consent' : 'select_account', $a['query']['prompt'] ?? '', 'prompt в ссылке');
        $t->ok(($a['query']['state'] ?? 'x') !== ($b['query']['state'] ?? 'x'), 'Два запроса — два разных state', 'Два запроса получили одинаковый state (возможно, страница кэшируется: кэшированная страница ломает защиту state)');
    }

    public function callback(TestContext $t): void
    {
        $cases = [
            'без параметров' => [],
            'поддельный state' => ['code' => 'fake-code', 'state' => bin2hex(random_bytes(32))],
            'отказ пользователя (error=access_denied)' => ['error' => 'access_denied', 'state' => bin2hex(random_bytes(32))],
            'state без code' => ['state' => bin2hex(random_bytes(32))],
        ];

        foreach ($cases as $label => $query) {
            $r = $this->request($this->callbackUrl($query));
            $t->ok($r['status'] < 500, $label . ': ответ ' . $r['status'] . ' (не 5xx)', $label . ': сервер ответил ' . $r['status']);
            $t->ok($this->isRedirectToSite($r), $label . ': редирект на сайт (' . ($r['headers']['location'] ?? 'без Location') . ')', $label . ': нет редиректа на сайт (статус ' . $r['status'] . ', Location: ' . TestContext::show($r['headers']['location'] ?? null) . ')');
            $t->ok(!isset($r['headers']['set-cookie']) || !preg_match('/googleauth/i', (string) $r['headers']['set-cookie']), $label . ': плагин не ставит свои cookies', $label . ': плагин поставил cookie');
        }
    }

    public function consent(TestContext $t): void
    {
        $base = $this->callbackUrl();
        $r = $this->request($base . '&task=consent');
        $t->ok($r['status'] < 500 && $this->isRedirectToSite($r), 'GET task=consent без сессии: редирект на сайт (' . $r['status'] . ')', 'GET task=consent без сессии: ' . $r['status']);

        $r = $this->request($base . '&task=consent', 'POST', ['token' => bin2hex(random_bytes(16)), 'accept' => '1', 'accept_terms' => '1']);
        $t->ok($r['status'] < 500 && $this->isRedirectToSite($r), 'POST согласия с чужим токеном: редирект на сайт (' . $r['status'] . ')', 'POST согласия с чужим токеном: ' . $r['status']);

        $before = (int) Harness::db()->setQuery('SELECT COUNT(*) FROM ' . Harness::db()->quoteName('#__users'))->loadResult();
        $this->request($base . '&task=consent', 'POST', ['token' => 'x', 'accept' => '1']);
        $after = (int) Harness::db()->setQuery('SELECT COUNT(*) FROM ' . Harness::db()->quoteName('#__users'))->loadResult();
        $t->same($before, $after, 'Число пользователей не изменилось');

        $r = $this->request($base . '&task=consent&cancel=1');
        $t->ok($r['status'] < 500, 'Отмена согласия без сессии: ' . $r['status'], 'Отмена согласия без сессии: ' . $r['status']);
    }

    public function leaks(TestContext $t): void
    {
        $plugin = Harness::requirePlugin();
        $secret = trim((string) $plugin->params->get('client_secret', ''));
        $pages = [
            'callback без параметров' => $this->request($this->callbackUrl()),
            'callback с подделкой' => $this->request($this->callbackUrl(['code' => 'x', 'state' => 'y'])),
        ];

        try {
            $pages['страница входа'] = $this->request($this->loginUrl(), 'GET', [], true);
        } catch (\TommiLin\Component\GoogleAuthTests\Administrator\Testing\SkipException $e) {
            $t->info('Страница входа не проверена: ' . $e->getMessage());
        }

        foreach ($pages as $label => $r) {
            if (strlen($secret) >= 8) {
                $t->ok(!str_contains($r['body'], $secret), $label . ': client secret не найден', $label . ': в ответе есть client secret!');
            }

            $t->ok(preg_match('/(Fatal error|Parse error|Stack trace|Warning:|Notice:|Deprecated:)/', $r['body']) !== 1, $label . ': PHP-ошибок в ответе нет', $label . ': в ответе есть вывод PHP-ошибки');
        }

        if (strlen($secret) < 8) {
            $t->warn('Client secret не задан или слишком короткий: проверка утечки секрета пропущена.');
        }
    }
}
