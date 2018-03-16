<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Files;

use Files\MimeType;
use P4\Filter\Utf8;
use Zend\Mvc\MvcEvent;
use Application\Config\ConfigManager;
use Application\Config\ConfigException;

class Module
{
    /**
     * Add a basic preview handler for primitive (web-safe) types.
     */
    public function onBootstrap(MvcEvent $event)
    {
        $application = $event->getApplication();
        $services    = $application->getServiceManager();
        $formats     = $services->get('formats');
        $url         = $services->get('viewhelpermanager')->get('url');
        $events      = $services->get('queue')->getEventManager();

        // attach to archive cleanup event
        $events->attach(
            'task.cleanup.archive',
            function ($event) use ($services) {
                $archiveFile = $event->getParam('id');
                $data        = $event->getParam('data');
                $statusFile  = isset($data['statusFile']) ? $data['statusFile'] : null;

                try {
                    $result = $services->get('archiver')->removeArchive($archiveFile, $statusFile);
                } catch (\Exception $e) {
                    $services->get('logger')->err($e);
                }
            }
        );

        $formats->addHandler(
            new Format\Handler(
                // can-preview callback
                function ($file, $extension, $mimeType, $request) use ($url) {
                    $isWebSafeImage = MimeType::isWebSafeImage($mimeType);
                    if ($request
                        && $request->getUri()->getPath() == $url('diff')
                        && $file->isText()
                        && !$isWebSafeImage
                    ) {
                        return false;
                    }
                    return $file->isText() || strpos($mimeType, '/pdf') || $isWebSafeImage;
                },
                // render-preview callback
                function ($file, $extension, $mimeType, $request) use ($services) {
                    $helpers    = $services->get('ViewHelperManager');
                    $config     = $services->get('config');
                    $url        = $helpers->get('url');
                    $escapeUrl  = $helpers->get('escapeUrl');
                    $translator = $services->get('translator');
                    $viewUrl    = $url('view', array('path' => trim($file->getDepotFilename(), '/')))
                        . '?v=' . $escapeUrl($file->getRevspec());

                    $allContentUrl = $url('file', array('path' => trim($file->getDepotFilename(), '/')))
                        . '?v=' . $escapeUrl($file->getRevspec()) . '&' . $file::MAX_SIZE . '=unlimited';

                    if (strpos($mimeType, '/pdf')) {
                        return '<div class="view view-pdf img-polaroid">'
                            .  '<object width="100%" height="100%" type="application/pdf" data="' . $viewUrl . '">'
                            .  '<p>'
                            .  $translator->t('It appears you don\'t have a pdf plugin for this browser.')
                            .  '</p>'
                            .  '</object>'
                            . '</div>';
                    }

                    if (MimeType::isWebSafeImage($mimeType)) {
                        return '<div class="view view-image img-polaroid pull-left">'
                             .  '<img src="' . $viewUrl . '">'
                             . '</div>';
                    }

                    // making it this far means that the file must be text
                    $fileSize        = $helpers->get('fileSize');
                    $escapeHtml      = $helpers->get('escapeHtml');
                    $isPlain         = $extension === 'txt' || !strlen($extension);
                    $maxSizeOverride = null;
                    try {
                        $maxSize = ConfigManager::getValue($config, ConfigManager::FILES_MAX_SIZE);
                    } catch (ConfigException $ce) {
                        $services->get('logger')->warn($ce);
                        $maxSize = $file::MAX_FILESIZE_VALUE;
                    }
                    if ($request->getQuery($file::MAX_SIZE)) {
                        $maxSize = $request->getQuery($file::MAX_SIZE);
                    }
                    $contents = $file->getDepotContents(
                        array(
                            $file::UTF8_CONVERT      => true,
                            $file::UTF8_SANITIZE     => true,
                            $file::MAX_SIZE          => $maxSize,
                            Utf8::NON_UTF8_ENCODINGS =>
                                ConfigManager::getValue(
                                    $config,
                                    ConfigManager::TRANSLATOR_NON_UTF8_ENCODINGS,
                                    Utf8::$fallbackEncodings
                                )
                        ),
                        $cropped
                    );

                    if ($cropped) {
                        $message = $translator->t(
                            'Files larger than %s are truncated. Click %s to display the full file ' .
                            '(may cause the browser to become unresponsive) or use the %s button to view ' .
                            'outside of Swarm.',
                            array(
                                $fileSize($maxSize),
                                '<a id="file-snip-alert" href="' . $allContentUrl . '">' .
                                $translator->t('here') . '</a>',
                                '<a href="' . $viewUrl . '"><i class="icon-share"></i>' .
                                $translator->t('Open') . '</a>'
                            )
                        );
                    }

                    return ($cropped === true
                            ? '<div class="alert alert-info"><i class="icon-info-sign"></i>' . ' '
                            . $message
                            . '</div>' : '')
                        . '<pre class="view view-text prettyprint linenums '
                        .  ($isPlain ? 'nocode' : 'lang-' . $extension)
                        . '">'
                        .  $escapeHtml($contents)
                        . '</pre>';
                }
            ),
            'default'
        );
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function getAutoloaderConfig()
    {
        return array(
            'Zend\Loader\StandardAutoloader' => array(
                'namespaces' => array(
                    __NAMESPACE__ => __DIR__ . '/src/' . __NAMESPACE__,
                ),
            ),
        );
    }
}
