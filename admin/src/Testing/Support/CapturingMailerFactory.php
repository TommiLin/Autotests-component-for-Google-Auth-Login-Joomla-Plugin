<?php

/**
 * @package     Google Auth Tests
 * @license     GNU General Public License version 2 or later
 */

namespace TommiLin\Component\GoogleAuthTests\Administrator\Testing\Support;

\defined('_JEXEC') or die;

use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Mail\MailerInterface;
use Joomla\Registry\Registry;

final class CapturingMailerFactory implements MailerFactoryInterface
{
    /** @var array<int, array{to:array,subject:string,body:string,html:bool}> */
    public array $sent = [];

    public function createMailer(?Registry $settings = null): MailerInterface
    {
        return new CapturingMailer($this);
    }
}
