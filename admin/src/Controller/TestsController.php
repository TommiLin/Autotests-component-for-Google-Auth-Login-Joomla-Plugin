<?php

/**
 * @package     Google Auth Tests
 * @license     GNU General Public License version 2 or later
 */

namespace TommiLin\Component\GoogleAuthTests\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use TommiLin\Component\GoogleAuthTests\Administrator\Testing\Harness;
use TommiLin\Component\GoogleAuthTests\Administrator\Testing\Runner;

/**
 * AJAX endpoints: task=tests.run and task=tests.cleanup (JSON, Super Users + CSRF token only).
 */
class TestsController extends BaseController
{
    public function run(): void
    {
        $this->respond(function (): array {
            $id = $this->input->post->getString('id', '');
            $options = [
                'write' => $this->input->post->getInt('write', 0) === 1,
                'risky' => $this->input->post->getInt('risky', 0) === 1,
            ];

            return Runner::run($id, $options);
        });
    }

    public function cleanup(): void
    {
        $this->respond(static fn (): array => Harness::cleanup());
    }

    private function respond(callable $work): void
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();

        if (!$user || !$user->authorise('core.admin')) {
            $this->json(['status' => 'error', 'messages' => [['level' => 'fail', 'text' => 'Доступ только для Super User']]], 403);
        }

        if (!Session::checkToken('post')) {
            $this->json(['status' => 'error', 'messages' => [['level' => 'fail', 'text' => 'Недействительный CSRF-токен: обновите страницу']]], 403);
        }

        try {
            $this->json($work());
        } catch (\Throwable $e) {
            $this->json([
                'status' => 'error',
                'messages' => [['level' => 'fail', 'text' => get_class($e) . ': ' . $e->getMessage()]],
            ], 500);
        }
    }

    private function json(array $data, int $code = 200): never
    {
        $app = Factory::getApplication();

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $app->setHeader('Status', (string) $code, true);
        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->setHeader('Cache-Control', 'no-store', true);
        $app->sendHeaders();

        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        $app->close();
        exit;
    }
}
