<?php

/**
 * @package     Google Auth Tests
 * @license     GNU General Public License version 2 or later
 */

namespace TommiLin\Component\GoogleAuthTests\Administrator\View\Tests;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Uri\Uri;
use TommiLin\Component\GoogleAuthTests\Administrator\Testing\Environment;
use TommiLin\Component\GoogleAuthTests\Administrator\Testing\Harness;
use TommiLin\Component\GoogleAuthTests\Administrator\Testing\TestRegistry;

class HtmlView extends BaseHtmlView
{
    public array $environment = [];

    public function display($tpl = null): void
    {
        ToolbarHelper::title(Text::_('COM_GOOGLEAUTHTESTS'), 'check-circle');

        $this->environment = Environment::collect();

        $doc = Factory::getApplication()->getDocument();
        $wa = $doc->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('com_googleauthtests');
        $wa->useStyle('com_googleauthtests.tests')->useScript('com_googleauthtests.tests');

        $doc->addScriptOptions('com_googleauthtests', [
            'groups'   => TestRegistry::groups(),
            'token'    => Session::getFormToken(),
            'runUrl'   => 'index.php?option=com_googleauthtests&task=tests.run&format=json',
            'cleanUrl' => 'index.php?option=com_googleauthtests&task=tests.cleanup&format=json',
            'siteRoot' => Harness::siteRoot(),
            'loginUrl' => Harness::siteRoot() . 'index.php?option=com_users&view=login',
            'plugin'   => Environment::browserParams(),
            'env'      => $this->environment,
            'tool'     => TestRegistry::TOOL_VERSION,
        ]);

        parent::display($tpl);
    }
}
