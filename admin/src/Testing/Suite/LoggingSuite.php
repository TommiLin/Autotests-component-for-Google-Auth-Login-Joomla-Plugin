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

final class LoggingSuite
{
    public const GROUP_ID = 'logging';
    public const GROUP_TITLE = 'Журнал плагина';

    public static function tests(): array
    {
        return [
            ['id' => 'log.pipeline', 'method' => 'pipeline', 'title' => 'Плагин пишет в googleauth.log.php', 'desc' => 'Тестовая запись попадает в журнал плагина.'],
            ['id' => 'log.no_secrets', 'method' => 'noSecrets', 'title' => 'В журнале нет секретов', 'desc' => 'Нет client secret, токенов Google и JWT в последних записях журнала.'],
        ];
    }

    private function logFile(): string
    {
        return rtrim((string) Factory::getApplication()->get('log_path'), '/\\') . '/googleauth.log.php';
    }

    private function tail(int $bytes): string
    {
        $file = $this->logFile();

        if (!is_file($file)) {
            return '';
        }

        $size = (int) filesize($file);
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            return '';
        }

        fseek($handle, max(0, $size - $bytes));
        $data = (string) stream_get_contents($handle);
        fclose($handle);

        return $data;
    }

    public function pipeline(TestContext $t): void
    {
        $plugin = Harness::requirePlugin();
        $marker = 'gat-' . bin2hex(random_bytes(6));
        Harness::call($plugin, 'log', ['info', 'gat_test', $marker]);

        $t->ok(is_file($this->logFile()), 'Файл журнала существует: ' . basename($this->logFile()), 'Файл журнала не создан: проверьте права на папку логов (' . dirname($this->logFile()) . ')');
        $t->ok(str_contains($this->tail(65536), $marker), 'Тестовая запись найдена в журнале', 'Тестовая запись не появилась в журнале');
    }

    public function noSecrets(TestContext $t): void
    {
        $log = $this->tail(262144);

        if ($log === '') {
            $t->skip('Журнал плагина пока пуст.');
        }

        $row = Harness::extensionRow();
        $secret = $row ? trim((string) (json_decode((string) $row->params, true)['client_secret'] ?? '')) : '';

        if (strlen($secret) >= 8) {
            $t->ok(!str_contains($log, $secret), 'Client secret в журнале не найден', 'В журнале обнаружен client secret!');
        }

        $patterns = [
            'access token Google (ya29.…)' => '/ya29\.[A-Za-z0-9_-]{20,}/',
            'JWT (eyJ….eyJ…)' => '/eyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}/',
            'client secret вида GOCSPX-…' => '/GOCSPX-[A-Za-z0-9_-]{10,}/',
            'поле "access_token"' => '/"access_token"\s*:/',
            'поле "id_token"' => '/"id_token"\s*:/',
        ];

        foreach ($patterns as $label => $pattern) {
            $t->ok(preg_match($pattern, $log) !== 1, 'Нет: ' . $label, 'В журнале найдено: ' . $label);
        }

        $t->info('Просмотрено последних байт журнала: ' . strlen($log));
    }
}
