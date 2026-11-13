<?php

/**
 * @package     Google Auth Tests
 * @license     GNU General Public License version 2 or later
 */

namespace TommiLin\Component\GoogleAuthTests\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;

class DisplayController extends BaseController
{
    protected $default_view = 'tests';

    public function display($cachable = false, $urlparams = [])
    {
        $user = Factory::getApplication()->getIdentity();

        // The tool reveals environment details and creates temporary users: Super Users only.
        if (!$user || !$user->authorise('core.admin')) {
            throw new \RuntimeException(Text::_('COM_GOOGLEAUTHTESTS_ERR_SUPERUSER_ONLY'), 403);
        }

        return parent::display($cachable, $urlparams);
    }
}
