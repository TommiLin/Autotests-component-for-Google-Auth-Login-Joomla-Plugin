<?php

/**
 * @package     Google Auth Tests
 * @license     GNU General Public License version 2 or later
 */

namespace TommiLin\Component\GoogleAuthTests\Administrator\Testing;

\defined('_JEXEC') or die;

/** Runs one server-side test and returns a JSON-ready result. */
final class Runner
{
    public static function run(string $id, array $options): array
    {
        $def = TestRegistry::find($id);

        if ($def === null || $def['mode'] !== 'server') {
            return ['id' => $id, 'status' => TestContext::ERROR, 'duration' => 0, 'messages' => [['level' => 'fail', 'text' => 'Неизвестный тест: ' . $id]]];
        }

        $ctx = new TestContext();
        $notices = [];
        $started = microtime(true);
        $status = TestContext::ERROR;

        set_error_handler(static function (int $no, string $str, string $file, int $line) use (&$notices): bool {
            $noise = in_array($no, [E_DEPRECATED, E_USER_DEPRECATED], true) || str_contains($str, 'Session ini settings');

            if (!$noise && count($notices) < 5) {
                $notices[] = $str . ' (' . basename($file) . ':' . $line . ')';
            }

            return true;
        });

        ob_start();

        try {
            if ($def['write'] && empty($options['write'])) {
                $ctx->skip('Тест пишет данные: включите опцию «тесты, пишущие данные».');
            }

            if ($def['risky'] && empty($options['risky'])) {
                $ctx->skip('Рискованный тест: включите опцию «рискованные тесты».');
            }

            $suite = new $def['class']();
            $suite->{$def['method']}($ctx);
            $status = $ctx->status();
        } catch (SkipException $e) {
            $status = TestContext::SKIP;
            $ctx->info($e->getMessage());
        } catch (\Throwable $e) {
            $status = TestContext::ERROR;
            $ctx->fail('Исключение ' . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
        } finally {
            $output = (string) ob_get_clean();
            restore_error_handler();

            try {
                Harness::restore();

                if (Harness::hasTemporaryData()) {
                    Harness::cleanup();
                }
            } catch (\Throwable $e) {
                $ctx->warn('Очистка после теста: ' . $e->getMessage());
            }
        }

        if (trim($output) !== '') {
            $ctx->warn('Тест вывел в stdout: ' . substr(trim(strip_tags($output)), 0, 200));
        }

        foreach ($notices as $notice) {
            $ctx->info('PHP notice/warning: ' . $notice);
        }

        return [
            'id'       => $id,
            'status'   => $status,
            'duration' => (int) round((microtime(true) - $started) * 1000),
            'messages' => $ctx->messages(),
        ];
    }
}
