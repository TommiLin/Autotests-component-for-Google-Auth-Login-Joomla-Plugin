<?php

/**
 * @package     Google Auth Tests
 * @license     GNU General Public License version 2 or later
 */

namespace TommiLin\Component\GoogleAuthTests\Administrator\Testing\Suite;

\defined('_JEXEC') or die;

use TommiLin\Component\GoogleAuthTests\Administrator\Testing\Harness;
use TommiLin\Component\GoogleAuthTests\Administrator\Testing\TestContext;
use Joomla\CMS\User\User;

/**
 * Runs the plugin's real onAfterRender() on sample HTML inside an isolated fake front-end
 * application, with settings overridden in memory only, and inspects the injected button.
 */
final class RenderSuite
{
    public const GROUP_ID = 'render';
    public const GROUP_TITLE = 'Кнопка: вывод на страницу';

    private const FORM = '<html><head></head><body><form id="login-form-16" method="post"><input type="hidden" name="task" value="user.login"><button type="submit" class="uk-button">Log in</button></form></body></html>';

    public static function tests(): array
    {
        return [
            ['id' => 'render.inject', 'method' => 'inject', 'title' => 'Когда кнопка добавляется на страницу', 'desc' => 'Формы Joomla/CB/JReviews — да; обычная страница, авторизованный, AJAX, пустой Client ID — нет.'],
            ['id' => 'render.url', 'method' => 'url', 'title' => 'Ссылка на Google', 'desc' => 'client_id, redirect_uri (https), response_type, scope, одноразовый state из 64 hex-символов.'],
            ['id' => 'render.prompt', 'method' => 'prompt', 'title' => 'Параметр prompt и force_google_consent', 'desc' => 'Да → select_account consent, нет → select_account.'],
            ['id' => 'render.themes', 'method' => 'themes', 'title' => 'Цветовые схемы', 'desc' => 'Light, Light (classic), Dark, Neutral: фон, рамка, цвет текста; неизвестная схема → Light.'],
            ['id' => 'render.types_shapes', 'method' => 'typesShapes', 'title' => 'Тип и форма кнопки', 'desc' => 'С надписью / только значок × прямоугольная / скруглённая: размеры, радиус, aria-label.'],
            ['id' => 'render.icon_align', 'method' => 'iconAlign', 'title' => 'Выравнивание значка', 'desc' => 'Слева / по центру / справа для кнопки «только значок».'],
            ['id' => 'render.match_submit', 'method' => 'matchSubmit', 'title' => 'Режим копирования стиля submit', 'desc' => 'Класс google-login-btn-match, data-атрибуты, отсутствие собственных цветов.'],
            ['id' => 'render.state_session', 'method' => 'stateSession', 'title' => 'State и страница возврата в сессии', 'desc' => 'Каждая отрисовка создаёт новый state; возврат запоминается с параметрами; CSS ховера на месте.'],
            ['id' => 'render.script', 'method' => 'script', 'title' => 'Встроенный JS содержит нужную логику', 'desc' => 'Определение формы по task=user.login, выравнивание значка, копирование стиля, MutationObserver.'],
        ];
    }

    private function link(TestContext $t, array $result): \DOMElement
    {
        if (!$result['injected'] || !($result['link'] instanceof \DOMElement)) {
            $app = $result['app'] ?? null;
            $why = $app ? ' [option=' . TestContext::show($app->input->getCmd('option')) . ', format=' . TestContext::show($app->input->getCmd('format')) . ', XHR=' . TestContext::show($app->input->server->getString('HTTP_X_REQUESTED_WITH', '')) . ', guest=' . TestContext::show((int) ($app->getIdentity()->id ?? 0)) . ']' : '';
            throw new \RuntimeException('Кнопка не добавлена на страницу (проверьте настройки и фильтр форм входа)' . $why);
        }

        return $result['link'];
    }

    public function inject(TestContext $t): void
    {
        $wrap = static fn (string $inner): string => '<html><head></head><body>' . $inner . '</body></html>';
        $cases = [
            [self::FORM, true, 'Модуль входа Joomla с id="login-form-16" (YOOtheme, Cassiopeia)'],
            [$wrap('<form class="mod-login" method="post"></form>'), true, 'Форма class="mod-login"'],
            [$wrap('<form class="cbLoginForm" method="post"></form>'), true, 'Форма Community Builder (cbLoginForm)'],
            [$wrap('<form class="jrForm" method="post"></form>'), true, 'Форма JReviews (jrForm)'],
            [$wrap('<div class="jrLoginBox"><form method="post"></form></div>'), true, 'Блок JReviews (jrLoginBox)'],
            [$wrap('<p>Обычная страница без формы входа</p>'), false, 'Страница без формы входа'],
        ];

        foreach ($cases as [$html, $expected, $label]) {
            $r = Harness::render($html);
            $t->ok($r['injected'] === $expected, $label . ': ' . ($expected ? 'кнопка добавлена' : 'кнопки нет'), $label . ': ' . ($expected ? 'кнопка НЕ добавлена' : 'кнопка добавлена, а не должна'));
        }

        $custom = Harness::render($wrap('<form id="my-signin" method="post"><input type="hidden" name="task" value="user.login"></form>'));
        $custom['injected']
            ? $t->ok(true, 'Форма без login-form/mod-login/… в теге, но с task=user.login: кнопка добавлена (плагин 1.10.2+)')
            : $t->warn('Форма, которая шлёт task=user.login, но не содержит в теге <form> признаков (login-form, mod-login, cbLoginForm, com_comprofiler, jrForm), кнопку не получила: так ведёт себя плагин старше 1.10.2, обновите плагин');

        $guest = new User();
        $loggedIn = new User();
        $loggedIn->set('id', 4242);
        $r = Harness::render(self::FORM, [], ['identity' => $loggedIn]);
        $t->ok(!$r['injected'], 'Авторизованному пользователю кнопка не показывается', 'Авторизованному пользователю показана кнопка');

        $r = Harness::render(self::FORM, ['client_id' => '']);
        $t->ok(!$r['injected'], 'Пустой Client ID: кнопки нет', 'Пустой Client ID, но кнопка добавлена');

        $r = Harness::render(self::FORM, [], ['input' => ['option' => 'com_ajax']]);
        $t->ok(!$r['injected'], 'Запрос com_ajax: кнопки нет', 'В ответ com_ajax добавлена кнопка');

        $r = Harness::render(self::FORM, [], ['server' => ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']]);
        $t->ok(!$r['injected'], 'XHR-запрос: кнопки нет', 'В XHR-ответ добавлена кнопка');
        unset($guest);
    }

    public function url(TestContext $t): void
    {
        $plugin = Harness::requirePlugin();
        $r = Harness::render(self::FORM, ['client_id' => '999888777666-gatclient.apps.googleusercontent.com']);
        $this->link($t, $r);
        $parts = parse_url($r['href']);

        $t->same('accounts.google.com', $parts['host'] ?? '', 'Хост');
        $t->same('/o/oauth2/v2/auth', $parts['path'] ?? '', 'Путь');
        $q = $r['query'];
        $t->same('999888777666-gatclient.apps.googleusercontent.com', $q['client_id'] ?? '', 'client_id');
        $t->same((string) Harness::call($plugin, 'getCallbackUrl'), $q['redirect_uri'] ?? '', 'redirect_uri');
        $t->ok(str_starts_with((string) ($q['redirect_uri'] ?? ''), 'https://'), 'redirect_uri начинается с https://', 'redirect_uri не https');
        $t->same('code', $q['response_type'] ?? '', 'response_type');
        $t->same('openid email profile', $q['scope'] ?? '', 'scope');
        $t->ok(preg_match('/^[a-f0-9]{64}$/', (string) ($q['state'] ?? '')) === 1, 'state — 64 hex-символа (256 бит)', 'state имеет неожиданный вид: ' . TestContext::show($q['state'] ?? null));
        $t->ok(!isset($q['client_secret']), 'client_secret в ссылку не попадает', 'client_secret попал в ссылку!');
    }

    public function prompt(TestContext $t): void
    {
        foreach ([[1, 'select_account consent'], [0, 'select_account']] as [$flag, $expected]) {
            $r = Harness::render(self::FORM, ['force_google_consent' => $flag]);
            $this->link($t, $r);
            $t->same($expected, $r['query']['prompt'] ?? '', 'force_google_consent=' . $flag . ' → prompt');
        }
    }

    public function themes(TestContext $t): void
    {
        $expected = [
            'light'   => ['#FFFFFF', '1px solid #747775', '#1F1F1F'],
            'classic' => ['#FFFFFF', '1px solid #dadce0', '#3c4043'],
            'dark'    => ['#131314', '1px solid #8E918F', '#E3E3E3'],
            'neutral' => ['#F2F2F2', '0', '#1F1F1F'],
        ];

        foreach ($expected as $theme => [$bg, $border, $color]) {
            $r = Harness::render(self::FORM, ['button_theme' => $theme, 'button_type' => 'standard', 'match_submit_style' => 0]);
            $link = $this->link($t, $r);
            $style = $link->getAttribute('style');
            $t->same(strtolower($bg), strtolower((string) Harness::cssProp($style, 'background')), $theme . ': фон');
            $t->same(strtolower($border), strtolower((string) Harness::cssProp($style, 'border')), $theme . ': рамка');
            $t->same(strtolower($color), strtolower((string) Harness::cssProp($style, 'color')), $theme . ': цвет текста');
            $t->ok(str_contains($link->getAttribute('class'), 'google-login-btn--' . $theme), $theme . ': есть класс схемы', $theme . ': нет класса google-login-btn--' . $theme);
        }

        $r = Harness::render(self::FORM, ['button_theme' => 'no-such-theme']);
        $link = $this->link($t, $r);
        $t->ok(str_contains($link->getAttribute('class'), 'google-login-btn--light'), 'Неизвестная схема заменяется на light', 'Неизвестная схема не заменена на light');
    }

    public function typesShapes(TestContext $t): void
    {
        foreach (['standard', 'icon'] as $type) {
            foreach (['rectangular', 'pill'] as $shape) {
                $r = Harness::render(self::FORM, ['button_type' => $type, 'button_shape' => $shape, 'match_submit_style' => 0]);
                $link = $this->link($t, $r);
                $style = $link->getAttribute('style');
                $label = $type . ' / ' . $shape;
                $t->same($shape === 'pill' ? '999px' : '4px', Harness::cssProp($style, 'border-radius'), $label . ': радиус');
                $spans = $link->getElementsByTagName('span')->length;

                if ($type === 'icon') {
                    $t->same(0, $spans, $label . ': текста в кнопке нет');
                    $t->ok(trim($link->getAttribute('aria-label')) !== '' && trim($link->getAttribute('title')) !== '', $label . ': есть aria-label и title', $label . ': нет aria-label/title у кнопки без текста');
                    $t->same('40px', Harness::cssProp($style, 'width'), $label . ': ширина');
                    $t->same('40px', Harness::cssProp($style, 'height'), $label . ': высота');
                } else {
                    $t->ok($spans === 1 && trim($link->textContent) !== '', $label . ': есть надпись «' . trim($link->textContent) . '»', $label . ': нет надписи');
                    $t->same('100%', Harness::cssProp($style, 'width'), $label . ': ширина');
                }
            }
        }
    }

    public function iconAlign(TestContext $t): void
    {
        $map = ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end', 'nonsense' => 'flex-start'];

        foreach ($map as $value => $expected) {
            $r = Harness::render(self::FORM, ['button_type' => 'icon', 'icon_align' => $value]);
            $this->link($t, $r);
            $style = $r['container']->getAttribute('style');
            $t->same($expected, Harness::cssProp($style, 'justify-content'), 'icon_align=' . $value . ' → justify-content');
        }

        $r = Harness::render(self::FORM, ['button_type' => 'standard', 'icon_align' => 'right']);
        $this->link($t, $r);
        $t->ok(Harness::cssProp($r['container']->getAttribute('style'), 'justify-content') === null, 'У обычной кнопки выравнивание не применяется', 'У обычной кнопки появилось выравнивание значка');
    }

    public function matchSubmit(TestContext $t): void
    {
        $r = Harness::render(self::FORM, ['match_submit_style' => 1, 'button_type' => 'standard']);
        $link = $this->link($t, $r);
        $style = $link->getAttribute('style');
        $t->same('google-login-btn-match', $link->getAttribute('class'), 'Класс кнопки');
        $t->same('1', $r['container']->getAttribute('data-match-submit'), 'data-match-submit');
        $t->ok(Harness::cssProp($style, 'background') === null && Harness::cssProp($style, 'color') === null && Harness::cssProp($style, 'border') === null, 'Собственных цветов и рамки нет (стиль берётся у submit)', 'В режиме копирования стиля остались собственные цвета/рамка');

        $r = Harness::render(self::FORM, ['match_submit_style' => 1, 'button_type' => 'icon', 'icon_match_height' => 1]);
        $this->link($t, $r);
        $t->same('1', $r['container']->getAttribute('data-match-height'), 'Значок + выравнивание по высоте: data-match-height');

        $r = Harness::render(self::FORM, ['match_submit_style' => 0, 'button_type' => 'icon', 'icon_match_height' => 0]);
        $this->link($t, $r);
        $t->ok(!$r['container']->hasAttribute('data-match-height'), 'Выравнивание по высоте выключено: атрибута нет', 'Выравнивание по высоте выключено, но атрибут есть');
        $t->same('40px', Harness::cssProp($r['link']->getAttribute('style'), 'height'), 'Фиксированная высота значка');

        $r = Harness::render(self::FORM, ['match_submit_style' => 0, 'button_type' => 'standard', 'icon_match_height' => 1]);
        $this->link($t, $r);
        $t->ok(!$r['container']->hasAttribute('data-match-height') && !$r['container']->hasAttribute('data-match-submit'), 'У обычной кнопки лишних data-атрибутов нет', 'У обычной кнопки появились data-атрибуты');
    }

    public function stateSession(TestContext $t): void
    {
        $app = Harness::useFakeApp();
        $first = Harness::renderIn($app, self::FORM);
        $second = Harness::renderIn($app, self::FORM);
        $this->link($t, $first);

        $t->ok(($first['query']['state'] ?? 'a') !== ($second['query']['state'] ?? 'a'), 'Каждая отрисовка получает новый state', 'Два запроса получили одинаковый state');

        $states = (array) $app->getSession()->get(Harness::constant('STATE_KEY'), []);
        $t->same(2, count($states), 'В сессии хранится состояний');

        foreach ($states as $state => $time) {
            $t->ok(is_int($time) && abs(time() - $time) < 60, 'Метка времени state свежая', 'У state неверная метка времени: ' . TestContext::show($time));
        }

        $return = (string) $app->getSession()->get('googleauth.return_url', '');
        $t->ok(preg_match('#^https?://#', $return) === 1, 'Страница возврата сохранена полным URL: ' . $return, 'Страница возврата не сохранена: ' . TestContext::show($return));

        $css = (string) $first['css'];
        $t->ok($css !== '', 'CSS ховера добавлен на страницу', 'CSS ховера не найден');

        foreach (['.google-login-btn:hover', '.google-login-btn--light:hover', '.google-login-btn--dark:hover', '.google-login-btn--neutral:hover'] as $selector) {
            $t->ok(str_contains($css, $selector), 'В CSS есть ' . $selector, 'В CSS нет правила ' . $selector);
        }

        $t->ok(!str_contains($css, 'translateY'), 'Кнопка не сдвигается при наведении (нет translateY)', 'В CSS ховера есть translateY');
    }

    public function script(TestContext $t): void
    {
        $r = Harness::render(self::FORM);
        $script = (string) $r['script'];
        $t->ok($script !== '', 'Встроенный скрипт найден', 'Встроенный скрипт не найден');

        foreach ([
            'input[name="task"][value="user.login"]' => 'определение формы входа по task=user.login (YOOtheme, id="login-form-NN")',
            'function findSubmit' => 'поиск кнопки submit',
            'function matchIconHeight' => 'высота значка как у submit',
            'function matchSubmitStyle' => 'копирование стиля submit',
            'MutationObserver' => 'формы, появляющиеся позже',
            'google-auth-container' => 'контейнер кнопки',
        ] as $needle => $what) {
            $t->ok(str_contains($script, $needle), $what, 'В скрипте нет: ' . $what);
        }
    }
}
