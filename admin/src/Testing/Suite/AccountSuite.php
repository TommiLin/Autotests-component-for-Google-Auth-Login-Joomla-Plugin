<?php

/**
 * @package     Google Auth Tests
 * @license     GNU General Public License version 2 or later
 */

namespace TommiLin\Component\GoogleAuthTests\Administrator\Testing\Suite;

\defined('_JEXEC') or die;

use TommiLin\Component\GoogleAuthTests\Administrator\Testing\Harness;
use TommiLin\Component\GoogleAuthTests\Administrator\Testing\Support\RedirectSignal;
use TommiLin\Component\GoogleAuthTests\Administrator\Testing\TestContext;
use Joomla\CMS\User\User;
use Joomla\Registry\Registry as JRegistry;

/**
 * Account logic against the real database. Every test creates temporary users
 * (gat-*@example.invalid) and the runner deletes them afterwards, whatever the outcome.
 * No e-mails are sent (the mailer is replaced) and no other plugin's events are fired.
 */
final class AccountSuite
{
    public const GROUP_ID = 'account';
    public const GROUP_TITLE = 'Аккаунты, вход и уведомления';

    public static function tests(): array
    {
        return [
            ['id' => 'account.username', 'method' => 'username', 'title' => 'Генерация имени пользователя', 'desc' => 'Из e-mail: недопустимые символы убираются, длина ≤ 80, при совпадении добавляется -1, -2…', 'write' => true],
            ['id' => 'account.create', 'method' => 'create', 'title' => 'Создание пользователя из профиля Google', 'desc' => 'Группа Registered, не заблокирован, Google ID в параметрах, надёжный хеш пароля.', 'write' => true],
            ['id' => 'account.resolve', 'method' => 'resolve', 'title' => 'Поиск аккаунта: Google ID, e-mail, конфликт', 'desc' => 'Сначала по Google ID, затем по e-mail; чужой Google ID на тот же e-mail отклоняется.', 'write' => true],
            ['id' => 'account.save_data', 'method' => 'saveData', 'title' => 'Запись Google ID и согласия в параметры', 'desc' => 'Другие параметры пользователя сохраняются; повреждённый JSON не перезаписывается.', 'write' => true],
            ['id' => 'account.like_escape', 'method' => 'likeEscape', 'title' => 'Поиск по Google ID не принимает шаблоны % и _', 'desc' => 'Спецсимволы LIKE в Google ID не должны находить чужих пользователей.', 'write' => true],
            ['id' => 'account.login', 'method' => 'login', 'title' => 'Вход пользователя', 'desc' => 'Активный входит; заблокированный и гость — нет; событие onUserAfterLogin с responseType GoogleAuth.', 'write' => true],
            ['id' => 'account.notify', 'method' => 'notify', 'title' => 'Уведомления администраторам', 'desc' => 'Подмена почты: письма не уходят. Проверяются адресаты, текст, пропуск заблокированных и несуществующих.', 'write' => true],
            ['id' => 'account.cb_sync', 'method' => 'cbSync', 'title' => 'Синхронизация с Community Builder', 'desc' => 'Создаётся профиль, имя делится на имя/фамилию, confirmed и approved = 1, повтор не дублирует.', 'write' => true],
        ];
    }

    public function username(TestContext $t): void
    {
        $plugin = Harness::requirePlugin();
        $name = static fn (string $email) => (string) Harness::call($plugin, 'makeUniqueUsername', [$email]);

        $t->same('werdtag', $name('we!rd+tag@example.com'), 'Недопустимые символы убираются');
        $t->same('googleuser', $name('@example.com'), 'Пустая локальная часть → googleuser');
        $t->ok(strlen($name(str_repeat('a', 200) . '@example.com')) <= 80, 'Длинное имя обрезается до 80 символов', 'Имя длиннее 80 символов');

        $existing = Harness::createTempUser(['username' => 'gat_collide_' . bin2hex(random_bytes(3))]);
        $second = $name($existing->username . '@example.org');
        $t->same($existing->username . '-1', $second, 'Занятое имя получает суффикс -1');
    }

    public function create(TestContext $t): void
    {
        $plugin = Harness::requirePlugin();
        Harness::useFakeApp();
        Harness::setParams(['notify_admins' => 0]);
        $profile = Harness::profile(['name' => 'Anna Maria Smith']);

        $user = Harness::call($plugin, 'createGoogleUser', [$profile]);
        $t->ok($user instanceof User && $user->id > 0, 'Пользователь создан, id=' . ($user->id ?? 0), 'Пользователь не создан');
        $row = Harness::userRow((int) $user->id);

        if ($row === null) {
            $t->fail('Строка пользователя не найдена в #__users');

            return;
        }

        $db = Harness::db();
        $groups = $db->setQuery($db->getQuery(true)->select($db->quoteName('group_id'))->from($db->quoteName('#__user_usergroup_map'))->where($db->quoteName('user_id') . ' = ' . (int) $user->id))->loadColumn();
        $t->same([(string) Harness::registeredGroupId()], array_map('strval', $groups), 'Группы пользователя');
        $t->same(strtolower($profile['email']), strtolower((string) $row->email), 'E-mail');
        $t->same('Anna Maria Smith', (string) $row->name, 'Имя');
        $t->same('0', (string) $row->block, 'Не заблокирован (block)');
        $t->ok(strlen((string) $row->password) > 30 && str_starts_with((string) $row->password, '$'), 'Хеш пароля выглядит как bcrypt/argon2', 'Хеш пароля подозрительный');

        $params = new JRegistry((string) $row->params);
        $t->same($profile['googleId'], (string) $params->get('googleauth_sub'), 'Google ID в параметрах');
    }

    public function resolve(TestContext $t): void
    {
        $plugin = Harness::requirePlugin();
        Harness::useFakeApp();
        Harness::setParams(['require_consent' => 1]);
        $sub = 'gat-sub-' . bin2hex(random_bytes(4));
        $linked = Harness::createTempUser(['params' => ['googleauth_sub' => $sub]]);
        $unlinked = Harness::createTempUser();
        $resolve = static fn (string $email, string $googleId) => Harness::call($plugin, 'resolveGoogleAccount', [$email, 'Test', $googleId, '']);

        $r = $resolve('someone-else@example.invalid', $sub);
        $t->ok(is_array($r) && $r['type'] === 'existing' && (int) $r['user']->id === (int) $linked->id, 'Находит по Google ID', 'Не нашёл пользователя по Google ID');

        $r = $resolve($unlinked->email, 'gat-sub-' . bin2hex(random_bytes(4)));
        $t->ok(is_array($r) && $r['type'] === 'existing' && (int) $r['user']->id === (int) $unlinked->id, 'Находит по e-mail, если Google ID у пользователя ещё не записан', 'Не нашёл пользователя по e-mail');

        $t->same(null, $resolve($linked->email, 'gat-sub-' . bin2hex(random_bytes(4))), 'Тот же e-mail, но другой Google ID → отказ (null)');

        $r = $resolve('gat-new-' . bin2hex(random_bytes(4)) . '@example.invalid', 'gat-sub-' . bin2hex(random_bytes(4)));
        $t->ok(is_array($r) && $r['type'] === 'new' && $r['requiresConsent'] === true, 'Неизвестный пользователь → new, требуется согласие', 'Неизвестный пользователь распознан неверно');

        Harness::setParams(['require_consent' => 0]);
        $r = $resolve('gat-new-' . bin2hex(random_bytes(4)) . '@example.invalid', 'gat-sub-' . bin2hex(random_bytes(4)));
        $t->same(false, $r['requiresConsent'] ?? null, 'require_consent=0 → согласие не требуется');
    }

    public function saveData(TestContext $t): void
    {
        $plugin = Harness::requirePlugin();
        $user = Harness::createTempUser();
        $user->setParam('timezone', 'Europe/Kyiv');
        $user->save();
        $db = Harness::db();
        $paramsOf = static fn (int $id) => new JRegistry((string) Harness::userRow($id)->params);

        $record = json_encode(['accepted_terms' => true, 'accepted_at' => gmdate('c'), 'terms_version' => 'T', 'privacy_version' => 'P']);
        Harness::call($plugin, 'saveGoogleAuthData', [(int) $user->id, 'gat-sub-save', (string) $record]);
        $params = $paramsOf((int) $user->id);
        $t->same('gat-sub-save', (string) $params->get('googleauth_sub'), 'Google ID записан');
        $t->same($record, (string) $params->get('google_privacy_accepted'), 'Запись согласия сохранена');
        $t->same('Europe/Kyiv', (string) $params->get('timezone'), 'Чужие параметры пользователя не потеряны');

        try {
            Harness::call($plugin, 'saveGoogleAuthData', [0, 'x', '{}']);
            $t->fail('Запись для user id = 0 не отклонена');
        } catch (\Throwable) {
            $t->ok(true, 'Запись для user id = 0 отклоняется');
        }

        $db->setQuery($db->getQuery(true)->update($db->quoteName('#__users'))->set($db->quoteName('params') . ' = ' . $db->quote('{broken json'))->where($db->quoteName('id') . ' = ' . (int) $user->id))->execute();

        try {
            Harness::call($plugin, 'saveGoogleAuthData', [(int) $user->id, 'gat-sub-other', (string) $record]);
            $t->fail('Повреждённый JSON параметров был перезаписан');
        } catch (\Throwable) {
            $t->ok(true, 'Повреждённый JSON параметров не перезаписывается (ошибка)');
        }

        $t->same('{broken json', (string) Harness::userRow((int) $user->id)->params, 'Повреждённые параметры остались нетронутыми');
    }

    public function likeEscape(TestContext $t): void
    {
        $plugin = Harness::requirePlugin();
        Harness::createTempUser(['params' => ['googleauth_sub' => 'gat-wild-123456']]);

        $found = Harness::call($plugin, 'findUserByGoogleId', ['gat-wild-123456']);
        $t->ok($found instanceof User && $found->id > 0, 'Точный Google ID находит пользователя', 'Точный Google ID не нашёл пользователя');

        foreach (['gat-wild-%' => '%', 'gat-wild-_23456' => '_'] as $pattern => $symbol) {
            $hit = Harness::call($plugin, 'findUserByGoogleId', [$pattern]);

            if ($hit instanceof User && $hit->id > 0) {
                $t->warn('Спецсимвол «' . $symbol . '» в Google ID работает как шаблон LIKE и находит чужого пользователя. На практике Google ID — это цифровая строка от самого Google, поэтому риск низкий, но значение стоит экранировать (addcslashes + ESCAPE).');
            } else {
                $t->ok(true, 'Шаблон с «' . $symbol . '» не находит пользователей');
            }
        }
    }

    public function login(TestContext $t): void
    {
        $plugin = Harness::requirePlugin();
        $app = Harness::useFakeApp();
        $active = Harness::createTempUser();
        $blocked = Harness::createTempUser(['block' => 1]);

        $t->same(false, Harness::call($plugin, 'loginUser', [$blocked]), 'Заблокированный пользователь не входит');
        $t->same(false, Harness::call($plugin, 'loginUser', [new User()]), 'Гость (id=0) не входит');
        $t->same([], array_filter($app->firedEvents, static fn ($e) => $e[0] === 'onUserAfterLogin'), 'Для отказов событие входа не вызывалось');

        $ok = false;

        try {
            $ok = (bool) Harness::call($plugin, 'loginUser', [$active]);
        } catch (RedirectSignal) {
            $ok = true;
        }

        $t->same(true, $ok, 'Активный пользователь входит');
        $t->same((int) $active->id, (int) $app->getIdentity()->id, 'В приложение загружена нужная личность');

        $fired = array_values(array_filter($app->firedEvents, static fn ($e) => $e[0] === 'onUserAfterLogin'));
        $t->ok(count($fired) === 1, 'Событие onUserAfterLogin вызвано один раз', 'Событие onUserAfterLogin вызвано ' . count($fired) . ' раз');

        if ($fired !== []) {
            $options = $fired[0][1] ?? [];
            $options = is_array($options) ? ($options[0] ?? $options) : $options;
            $response = is_array($options) ? ($options['responseType'] ?? ($options['options']['responseType'] ?? null)) : null;
            $t->same('GoogleAuth', $response, 'responseType входа');
        }

        $t->same('0', (string) Harness::userRow((int) $active->id)->block, 'Пользователь после входа не заблокирован');
        $t->ok(Harness::userRow((int) $active->id)->lastvisitDate !== null, 'Дата последнего визита обновлена', 'Дата последнего визита не обновлена');
    }

    public function notify(TestContext $t): void
    {
        $plugin = Harness::requirePlugin();
        $app = Harness::useFakeApp(['sitename' => 'GAT Site']);
        $mail = Harness::captureMail();
        // Recipients only need a valid e-mail and an active account; Super User group is not created here
        // (some sites forbid creating Super Users from code: "Access Denied").
        $admin = Harness::createTempUser();
        $admin2 = Harness::createTempUser();
        $blocked = Harness::createTempUser(['block' => 1]);
        $newUserId = 987654321;
        $notify = static fn () => Harness::call($plugin, 'notifyAdminsAboutNewUser', [$newUserId]);

        // Recipients only need an e-mail address and an unblocked account: no Super User group is required
        // (creating Super Users may be denied on real sites).
        // Joomla itself mails every user created in the administrator ("You have been added…"): not our concern here.
        $mail->sent = [];

        Harness::setParams(['notify_admins' => 0, 'admin_notify_ids' => (string) $admin->id]);
        $notify();
        $t->same(0, count($mail->sent), 'Выключено → писем нет');

        Harness::setParams(['notify_admins' => 1, 'admin_notify_ids' => '']);
        $notify();
        $t->same(0, count($mail->sent), 'Включено, но список ID пуст → писем нет');

        Harness::setParams(['notify_admins' => 1, 'admin_notify_ids' => $admin->id . ', 999999991; ' . $blocked->id . ' ' . $newUserId]);
        $notify();
        $t->same(1, count($mail->sent), 'Одно письмо: несуществующие, заблокированные и сам новый пользователь пропускаются');

        if ($mail->sent !== []) {
            $letter = $mail->sent[0];
            $t->same([$admin->email], $letter['to'], 'Получатель');
            $t->ok(str_contains($letter['body'], 'GAT Site'), 'В тексте есть название сайта', 'В тексте нет названия сайта: ' . $letter['body']);
            $t->ok(str_contains($letter['body'], (string) $newUserId), 'В тексте есть ID нового пользователя', 'В тексте нет ID нового пользователя: ' . $letter['body']);
            $t->ok(str_contains($letter['subject'], 'GAT Site'), 'В теме есть название сайта', 'В теме нет названия сайта: ' . $letter['subject']);
            $t->same(false, $letter['html'], 'Письмо в виде простого текста');
        }

        $mail->sent = [];
        Harness::setParams(['notify_admins' => 1, 'admin_notify_ids' => $admin->id . ',' . $admin2->id]);
        $notify();
        $t->same(2, count($mail->sent), 'Два администратора → два отдельных письма');
        $t->ok(count($mail->sent) === 2 && count($mail->sent[0]['to']) === 1 && count($mail->sent[1]['to']) === 1, 'Каждое письмо адресовано одному получателю (адреса друг другу не видны)', 'Письма уходят нескольким получателям сразу');
    }

    public function cbSync(TestContext $t): void
    {
        $plugin = Harness::requirePlugin();

        if (!Harness::call($plugin, 'isCommunityBuilderAvailable')) {
            $t->skip('Community Builder не установлен (нет таблицы #__comprofiler).');
        }

        $db = Harness::db();
        $user = Harness::createTempUser(['name' => 'Anna Maria Smith']);
        $rows = static function (int $id) use ($db): array {
            return $db->setQuery($db->getQuery(true)->select('*')->from($db->quoteName('#__comprofiler'))->where($db->quoteName('id') . ' = ' . $id))->loadObjectList() ?: [];
        };

        Harness::call($plugin, 'syncCommunityBuilderUser', [(int) $user->id, '']);
        $list = $rows((int) $user->id);
        $t->same(1, count($list), 'Создан один профиль CB');

        if ($list !== []) {
            $row = $list[0];
            $t->same('Anna', (string) $row->firstname, 'firstname');
            $t->same('Maria Smith', (string) $row->lastname, 'lastname');
            $t->same('1', (string) $row->confirmed, 'confirmed');
            $t->same('1', (string) $row->approved, 'approved');
        }

        Harness::call($plugin, 'syncCommunityBuilderUser', [(int) $user->id, '']);
        $t->same(1, count($rows((int) $user->id)), 'Повторная синхронизация не создаёт дубль');
    }
}
