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

/** Consent logic with in-memory User objects (nothing is written to the database). */
final class ConsentSuite
{
    public const GROUP_ID = 'consent';
    public const GROUP_TITLE = 'Согласие и версии документов';

    public static function tests(): array
    {
        return [
            ['id' => 'consent.accepted', 'method' => 'accepted', 'title' => 'Принятое согласие', 'desc' => 'Согласие считается действующим только для текущих версий документов.'],
            ['id' => 'consent.renewal', 'method' => 'renewal', 'title' => 'Повторное согласие после обновления документов', 'desc' => 'Причина повторного запроса: terms, privacy, both; null для первого согласия.'],
            ['id' => 'consent.describe', 'method' => 'describe', 'title' => 'Нужно ли показывать окно согласия', 'desc' => 'Зависит от require_consent и от принятых версий.'],
            ['id' => 'consent.versions', 'method' => 'versions', 'title' => 'Версии документов', 'desc' => 'Пустая версия заменяется значением по умолчанию.'],
        ];
    }

    private function user(?array $record): User
    {
        $user = new User();

        if ($record !== null) {
            $user->setParam('google_privacy_accepted', json_encode($record));
        }

        return $user;
    }

    private function isAccepted(User $user): bool
    {
        return (bool) Harness::call(Harness::requirePlugin(), 'hasAcceptedTerms', [$user]);
    }

    public function accepted(TestContext $t): void
    {
        Harness::setParams(['terms_version' => 'T2', 'privacy_version' => 'P2']);
        $ok = ['accepted_terms' => true, 'terms_version' => 'T2', 'privacy_version' => 'P2'];

        $t->same(true, $this->isAccepted($this->user($ok)), 'Текущие версии приняты');
        $t->same(false, $this->isAccepted($this->user(null)), 'Ничего не принималось');
        $t->same(false, $this->isAccepted($this->user(['terms_version' => 'T2', 'privacy_version' => 'P2'])), 'Нет флага accepted_terms');
        $t->same(false, $this->isAccepted($this->user(['accepted_terms' => true, 'terms_version' => 'T1', 'privacy_version' => 'P2'])), 'Устарели Условия');
        $t->same(false, $this->isAccepted($this->user(['accepted_terms' => true, 'terms_version' => 'T2', 'privacy_version' => 'P1'])), 'Устарела Политика');

        $broken = new User();
        $broken->setParam('google_privacy_accepted', '{не json');
        $t->same(false, $this->isAccepted($broken), 'Повреждённая запись согласия не считается принятой');
    }

    public function renewal(TestContext $t): void
    {
        $plugin = Harness::requirePlugin();
        Harness::setParams(['terms_version' => 'T2', 'privacy_version' => 'P2']);
        $reason = static fn (?array $record) => Harness::call($plugin, 'getRenewalReason', [(function () use ($record) {
            $u = new User();
            $record !== null && $u->setParam('google_privacy_accepted', json_encode($record));

            return $u;
        })()]);

        $t->same(null, $reason(null), 'Ничего не принималось → null (первое согласие)');
        $t->same('terms', $reason(['accepted_terms' => true, 'terms_version' => 'T1', 'privacy_version' => 'P2']), 'Изменились Условия');
        $t->same('privacy', $reason(['accepted_terms' => true, 'terms_version' => 'T2', 'privacy_version' => 'P1']), 'Изменилась Политика');
        $t->same('both', $reason(['accepted_terms' => true, 'terms_version' => 'T1', 'privacy_version' => 'P1']), 'Изменились оба документа');
        $t->same(null, $reason(['accepted_terms' => true, 'terms_version' => 'T2', 'privacy_version' => 'P2']), 'Ничего не изменилось → null');
    }

    public function describe(TestContext $t): void
    {
        $plugin = Harness::requirePlugin();
        Harness::setParams(['terms_version' => 'T2', 'privacy_version' => 'P2']);
        $current = $this->user(['accepted_terms' => true, 'terms_version' => 'T2', 'privacy_version' => 'P2']);
        $stale = $this->user(['accepted_terms' => true, 'terms_version' => 'T1', 'privacy_version' => 'P2']);
        $fresh = $this->user(null);

        Harness::setParams(['require_consent' => 1]);
        $t->same(false, Harness::call($plugin, 'describeExistingAccount', [$current])['requiresConsent'], 'require_consent=1, всё принято → окно не нужно');
        $stale_r = Harness::call($plugin, 'describeExistingAccount', [$stale]);
        $t->same(true, $stale_r['requiresConsent'], 'require_consent=1, документ обновлён → окно нужно');
        $t->same('terms', $stale_r['renewalReason'], 'Причина повторного согласия');
        $fresh_r = Harness::call($plugin, 'describeExistingAccount', [$fresh]);
        $t->same(true, $fresh_r['requiresConsent'], 'require_consent=1, согласия не было → окно нужно');
        $t->same(null, $fresh_r['renewalReason'], 'Для первого согласия причина не указывается');

        Harness::setParams(['require_consent' => 0]);
        $t->same(false, Harness::call($plugin, 'describeExistingAccount', [$stale])['requiresConsent'], 'require_consent=0 → окно не показывается никогда');
        $t->same(false, Harness::call($plugin, 'describeExistingAccount', [$fresh])['requiresConsent'], 'require_consent=0, согласия не было → окно не показывается');
    }

    public function versions(TestContext $t): void
    {
        $plugin = Harness::requirePlugin();
        $default = Harness::constant('DEFAULT_DOC_VERSION');

        Harness::setParams(['terms_version' => '', 'privacy_version' => '   ']);
        $t->same($default, Harness::call($plugin, 'currentTermsVersion'), 'Пустая версия Условий → значение по умолчанию');
        $t->same($default, Harness::call($plugin, 'currentPrivacyVersion'), 'Пустая версия Политики → значение по умолчанию');

        Harness::setParams(['terms_version' => ' 2027-01-01 ']);
        $t->same('2027-01-01', Harness::call($plugin, 'currentTermsVersion'), 'Версия Условий обрезается по краям');
    }
}
