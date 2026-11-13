<?php

/**
 * @package     Google Auth Tests
 * @license     GNU General Public License version 2 or later
 */

namespace TommiLin\Component\GoogleAuthTests\Administrator\Testing\Suite;

\defined('_JEXEC') or die;

/**
 * Definitions only: these tests run in the browser (assets/js/tests.js) inside a hidden
 * same-origin iframe with the site's login page, so the plugin's injected JavaScript is
 * executed for real on your template.
 */
final class BrowserSuite
{
    public const GROUP_ID = 'browser';
    public const GROUP_TITLE = 'В браузере (реальный JS на вашем шаблоне)';

    public static function tests(): array
    {
        return [
            ['id' => 'browser.js_syntax', 'mode' => 'browser', 'title' => 'JS плагина без синтаксических ошибок', 'desc' => 'Встроенный скрипт компилируется браузером.'],
            ['id' => 'browser.placed', 'mode' => 'browser', 'title' => 'Кнопка размещена в форме входа', 'desc' => 'После выполнения скрипта кнопка есть, видима и стоит рядом с формой / кнопкой submit.'],
            ['id' => 'browser.single', 'mode' => 'browser', 'title' => 'Кнопка не дублируется', 'desc' => 'После resize и клика кнопок по-прежнему по одной на форму (MutationObserver не плодит копии).'],
            ['id' => 'browser.link', 'mode' => 'browser', 'title' => 'Ссылка кнопки ведёт на Google', 'desc' => 'href на accounts.google.com с client_id и state.'],
            ['id' => 'browser.size', 'mode' => 'browser', 'title' => 'Размер кнопки', 'desc' => 'Для «только значок»: квадрат 40×40 или высота кнопки входа; для обычной кнопки — ширина формы.'],
            ['id' => 'browser.match_submit', 'mode' => 'browser', 'title' => 'Копирование стиля submit', 'desc' => 'Если режим включён: у кнопки Google те же CSS-классы, что у кнопки входа.'],
            ['id' => 'browser.hover_css', 'mode' => 'browser', 'title' => 'CSS ховера на странице', 'desc' => 'Правила наведения для выбранной схемы присутствуют и без transform-сдвига.'],
        ];
    }
}
