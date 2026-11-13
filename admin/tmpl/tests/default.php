<?php

/**
 * @package     Google Auth Tests
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var \TommiLin\Component\GoogleAuthTests\Administrator\View\Tests\HtmlView $this */
?>
<div id="gat-app" class="gat">
    <p class="gat-lead"><?php echo Text::_('COM_GOOGLEAUTHTESTS_LEAD'); ?></p>

    <details class="gat-panel" id="gat-env" open>
        <summary><?php echo Text::_('COM_GOOGLEAUTHTESTS_ENVIRONMENT'); ?></summary>
        <div class="gat-env-grid" id="gat-env-grid"></div>
    </details>

    <div class="gat-panel gat-controls">
        <div class="gat-buttons">
            <button type="button" class="btn btn-primary" id="gat-run-all"><?php echo Text::_('COM_GOOGLEAUTHTESTS_RUN_ALL'); ?></button>
            <button type="button" class="btn btn-success" id="gat-run-selected"><?php echo Text::_('COM_GOOGLEAUTHTESTS_RUN_SELECTED'); ?></button>
            <button type="button" class="btn btn-secondary" id="gat-stop" disabled><?php echo Text::_('COM_GOOGLEAUTHTESTS_STOP'); ?></button>
            <span class="gat-sep"></span>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="gat-select-all"><?php echo Text::_('COM_GOOGLEAUTHTESTS_SELECT_ALL'); ?></button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="gat-select-none"><?php echo Text::_('COM_GOOGLEAUTHTESTS_SELECT_NONE'); ?></button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="gat-select-failed"><?php echo Text::_('COM_GOOGLEAUTHTESTS_SELECT_FAILED'); ?></button>
        </div>
        <div class="gat-options">
            <label><input type="checkbox" id="gat-opt-write" checked> <?php echo Text::_('COM_GOOGLEAUTHTESTS_OPT_WRITE'); ?></label>
            <label><input type="checkbox" id="gat-opt-risky"> <?php echo Text::_('COM_GOOGLEAUTHTESTS_OPT_RISKY'); ?></label>
            <label><input type="checkbox" id="gat-opt-stop"> <?php echo Text::_('COM_GOOGLEAUTHTESTS_OPT_STOP'); ?></label>
        </div>
        <div class="gat-progress" aria-hidden="true"><div class="gat-progress-bar" id="gat-progress-bar"></div></div>
        <div class="gat-summary" id="gat-summary"></div>
    </div>

    <div id="gat-tests"></div>

    <div class="gat-panel gat-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="gat-copy"><?php echo Text::_('COM_GOOGLEAUTHTESTS_COPY_REPORT'); ?></button>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="gat-download"><?php echo Text::_('COM_GOOGLEAUTHTESTS_DOWNLOAD_JSON'); ?></button>
        <button type="button" class="btn btn-outline-danger btn-sm" id="gat-cleanup"><?php echo Text::_('COM_GOOGLEAUTHTESTS_CLEANUP'); ?></button>
        <span class="gat-hint"><?php echo Text::_('COM_GOOGLEAUTHTESTS_CLEANUP_HINT'); ?></span>
    </div>

    <iframe id="gat-frame" title="login page" tabindex="-1" aria-hidden="true"></iframe>
</div>
