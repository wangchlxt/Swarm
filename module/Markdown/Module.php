<?php
/**
 * Perforce Swarm, Community Development
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Markdown;

use Files\Format\Handler as FormatHandler;
use Zend\Mvc\MvcEvent;

class Module
{
    /**
     * Add a preview handler for markdown files in the file browser.
     * Note that files > 1MB will be cropped for performance reasons.
     */
    public function onBootstrap(MvcEvent $event)
    {
        $application = $event->getApplication();
        $services    = $application->getServiceManager();
        $formats     = $services->get('formats');

        $formats->addHandler(
            new FormatHandler(
                // can-preview callback
                function ($file, $extension, $mimeType, $request) {
                    // Returning false here will mean we never render markdown for diffs and file
                    // preview. We need to improve markdown display configuration with regards to
                    // the actual display and any size limit and how that effects display for
                    // diff vs view vs project overview. Note this does not effect the display of
                    // markdown in the project overview.
                    //
                    // see https://jira.perforce.com:8443/browse/SW-4196
                    // see https://jira.perforce.com:8443/browse/SW-4191
                    return false;
                },
                // render-preview callback
                function ($file, $extension, $mimeType) use ($services) {
                    $helpers          = $services->get('ViewHelperManager');
                    $purifiedMarkdown = $helpers->get('markupMarkdown');

                    $maxSize  = 1048576; // 1MB
                    $contents = $file->getDepotContents(
                        array(
                            $file::UTF8_CONVERT  => true,
                            $file::UTF8_SANITIZE => true,
                            $file::MAX_FILESIZE  => $maxSize
                        )
                    );

                    return '<div class="view view-md markdown">'
                    .   $purifiedMarkdown($contents)
                    .  '</div>';
                }
            ),
            'markdown'
        );
    }

    public function getAutoloaderConfig()
    {
        return array(
            'Zend\Loader\StandardAutoloader' => array(
                'namespaces' => array(
                    __NAMESPACE__ => __DIR__ . '/src/' . __NAMESPACE__,
                ),
            ),
            'Zend\Loader\ClassMapAutoloader' => array(
                array(
                    'Parsedown'           => BASE_PATH . '/library/Parsedown/Parsedown.php'
                ),
            ),
        );
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }
}
