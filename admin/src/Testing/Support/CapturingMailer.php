<?php

/**
 * @package     Google Auth Tests
 * @license     GNU General Public License version 2 or later
 */

namespace TommiLin\Component\GoogleAuthTests\Administrator\Testing\Support;

\defined('_JEXEC') or die;

use Joomla\CMS\Mail\MailerInterface;

/** Records messages instead of sending them. */
final class CapturingMailer implements MailerInterface
{
    private array $recipients = [];
    private string $subject = '';
    private string $body = '';
    private bool $html = true;

    public function __construct(private readonly CapturingMailerFactory $factory)
    {
    }

    public function send()
    {
        $this->factory->sent[] = [
            'to'      => $this->recipients,
            'subject' => $this->subject,
            'body'    => $this->body,
            'html'    => $this->html,
        ];

        return true;
    }

    public function setSender($fromEmail, $name = '')
    {
        return $this;
    }

    public function setSubject($subject)
    {
        $this->subject = (string) $subject;

        return $this;
    }

    public function setBody($content)
    {
        $this->body = (string) $content;

        return $this;
    }

    public function addRecipient($recipientEmail, $name = '')
    {
        $this->recipients[] = (string) $recipientEmail;

        return $this;
    }

    /** PHPMailer-style alias used by Joomla's MailTemplate. */
    public function addAddress($address, $name = '')
    {
        return $this->addRecipient($address, $name);
    }

    /** Any other PHPMailer method used by other extensions is accepted and ignored. */
    public function __call(string $method, array $arguments)
    {
        return $this;
    }

    public function addCc($ccEmail, $name = '')
    {
        return $this;
    }

    public function addBcc($bccEmail, $name = '')
    {
        return $this;
    }

    public function addAttachment($data, $name = '', $encoding = 'base64', $type = 'application/octet-stream')
    {
        return $this;
    }

    public function addReplyTo($replyToEmail, $name = '')
    {
        return $this;
    }

    public function isHtml($ishtml = true)
    {
        $this->html = (bool) $ishtml;

        return $this;
    }
}
