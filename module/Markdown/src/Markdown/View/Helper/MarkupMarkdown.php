<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Markdown\View\Helper;

use Zend\View\Helper\AbstractHelper;
use Parsedown;

class MarkupMarkdown extends AbstractHelper
{
    /**
     * Generates html from the supplied markdown text.
     *
     * @param  string   $value  markdown text to be parsed
     * @return string   parsed result
     */
    public function __invoke($value)
    {
        // Fetch the service to get the config.
        $services = $this->getView()->getHelperPluginManager()->getServiceLocator();
        $config   = $services->get('config');
        $readme   = isset($config['projects']['readme_mode']) ? $config['projects']['readme_mode'] : '';

        return $this->markdownSetting($value, $readme);
    }

    /**
     * Depending on settings in the config.php depends how we render the readme.
     *
     * @param  string   $value  markdown text to be parsed
     * @param  string   $readme Config setting from config.php file.
     * @return string   parsed  result
     */
    public function markdownSetting($value, $readme)
    {
        // If readme config is set switch to check if "restricted" or "unrestricted" is set and escape html on
        // restricted. If disabled we will use default and not return readme file.
        $parsedown = new Parsedown();
        switch ((string) $readme) {
            case 'restricted':
                $parsedown->setMarkupEscaped(true);
                break;
            case 'unrestricted':
                break;
            default:
                return '';
        }
        return $parsedown->text($value);
    }
}
