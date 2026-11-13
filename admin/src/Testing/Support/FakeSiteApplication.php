<?php

/**
 * @package     Google Auth Tests
 * @license     GNU General Public License version 2 or later
 */

namespace TommiLin\Component\GoogleAuthTests\Administrator\Testing\Support;

\defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Session\Session;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Registry\Registry;
use Joomla\Session\Storage\RuntimeStorage;

/**
 * An isolated "front end" application used to run the plugin's code paths from the
 * administrator: private in-memory session, no redirects/exits, no real events, no session
 * metadata writes. Nothing here touches the visitor's real session.
 */
final class FakeSiteApplication extends CMSApplication
{
    protected $name = 'site';

    public ?string $redirectedTo = null;

    /** @var array<int, array{0:string,1:string}> */
    public array $queuedMessages = [];

    /** @var array<int, array{0:string,1:mixed}> */
    public array $firedEvents = [];

    public static function create(array $options = []): self
    {
        $container = Factory::getContainer();
        // Joomla 4/5 type-hint Joomla\CMS\Input\Input, Joomla 6 removed it in favour of Joomla\Input\Input.
        $inputClass = class_exists(\Joomla\CMS\Input\Input::class) ? \Joomla\CMS\Input\Input::class : \Joomla\Input\Input::class;
        $input = new $inputClass($options['input'] ?? []);
        $config = new Registry([
            'sitename'         => $options['sitename'] ?? (string) Factory::getApplication()->get('sitename'),
            'session_metadata' => false,
        ]);

        $app = new self($input, $config, null, $container);
        $app->setUserFactory($container->get(UserFactoryInterface::class));
        $app->setSession(new Session(new RuntimeStorage()));
        $app->loadIdentity($options['identity'] ?? null);

        // The fake request must not inherit the real one (e.g. this tool's own XHR header, which the
        // plugin would take for an AJAX request): give it a clean, minimal $_SERVER.
        $server = $input->server;
        $data = new \ReflectionProperty(\Joomla\Input\Input::class, 'data');
        $data->setAccessible(true);
        $data->setValue($server, [
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST'      => $_SERVER['HTTP_HOST'] ?? 'localhost',
            'HTTP_ACCEPT'    => 'text/html',
        ]);

        foreach (($options['server'] ?? []) as $key => $value) {
            $server->set($key, $value);
        }

        $app->setBody((string) ($options['body'] ?? ''));

        return $app;
    }

    protected function doExecute()
    {
        // Never executed: this application only hosts the plugin's code paths.
    }

    public function redirect($url, $status = 303)
    {
        $this->redirectedTo = (string) $url;

        throw new RedirectSignal((string) $url);
    }

    public function enqueueMessage($msg, $type = self::MSG_INFO)
    {
        $this->queuedMessages[] = [(string) $type, (string) $msg];
    }

    public function isClient($identifier)
    {
        return $identifier === 'site';
    }

    public function checkSession()
    {
        // No #__session writes during tests.
    }

    public function triggerEvent($eventName, $args = [])
    {
        // Recorded instead of dispatched, so other extensions' listeners are never triggered.
        $this->firedEvents[] = [(string) $eventName, $args];

        return [];
    }

    public function getLanguage()
    {
        return Factory::getApplication()->getLanguage();
    }

    public function asUser(User $user): self
    {
        $this->loadIdentity($user);

        return $this;
    }
}
