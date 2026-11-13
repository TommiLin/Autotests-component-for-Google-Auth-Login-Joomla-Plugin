<?php

/**
 * @package     Google Auth Tests
 * @license     GNU General Public License version 2 or later
 */

namespace TommiLin\Component\GoogleAuthTests\Administrator\Testing\Support;

\defined('_JEXEC') or die;

/** Thrown by the fake application instead of redirecting (and exiting). */
final class RedirectSignal extends \RuntimeException
{
    public function __construct(public readonly string $url)
    {
        parent::__construct('redirect: ' . $url);
    }
}
