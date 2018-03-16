<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

return array(
    'archives' => array(
        'max_input_size'     => 512 * 1024 * 1024, // 512M (must be in bytes)
        'archive_timeout'    => 1800,              // 30 minutes (must be in seconds)
        'cache_lifetime'     => 60 * 60 * 24,      // time to keep archives before deleting them (in seconds)
        'compression_level'  => 1                  // should be between 0 (no compression) and 9 (maximum compression)
    ),
    'files' => array(
        'max_size'           => 1024 * 1024,       // 1MB (must be in bytes)
        'download_timeout'   => 1800,              // Time allowed before download of a non archive file will error
                                                   // default 30 minutes (must be in seconds)
    ),
    'diffs' => array(
        'ignore_whitespace_default' => false,
        'max_diffs'                 => 1500        // maximum number of diff lines without manual user override
    ),
    'xhprof' => array(
        'ignored_routes' => array('archive', 'download', 'view')
    ),
    'router' => array(
        'routes' => array(
            'file' => array(
                'type' => 'Application\Router\Regex',
                'options' => array(
                    'regex'    => '/files?(/(?P<path>.*))?',
                    'spec'     => '/files/%path%',
                    'defaults' => array(
                        'controller' => 'Files\Controller\Index',
                        'action'     => 'file',
                        'path'       => null
                    ),
                ),
            ),
            'view' => array(
                'type' => 'Application\Router\Regex',
                'options' => array(
                    'regex'    => '/view(/(?P<path>.*))?',
                    'spec'     => '/view/%path%',
                    'defaults' => array(
                        'controller' => 'Files\Controller\Index',
                        'action'     => 'file',
                        'path'       => null,
                        'view'       => true
                    ),
                ),
            ),
            'download' => array(
                'type' => 'Application\Router\Regex',
                'options' => array(
                    'regex'    => '/downloads?(/(?P<path>.*))?',
                    'spec'     => '/downloads/%path%',
                    'defaults' => array(
                        'controller' => 'Files\Controller\Index',
                        'action'     => 'file',
                        'path'       => null,
                        'download'   => true
                    ),
                ),
            ),
            'archive' => array(
                'type' => 'Application\Router\Regex',
                'options' => array(
                    'regex'    => '/archives?/(?P<path>.+)\.zip',
                    'spec'     => '/archives/%path%.zip',
                    'defaults' => array(
                        'controller' => 'Files\Controller\Index',
                        'action'     => 'archive'
                    ),
                ),
            ),
            'archive-status' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/archive-status/:digest[/]',
                    'defaults' => array(
                        'controller' => 'Files\Controller\Index',
                        'action'     => 'archive'
                    ),
                ),
            ),
            'diff' => array(
                'type' => 'Zend\Mvc\Router\Http\Literal',
                'options' => array(
                    'route'    => '/diff',
                    'defaults' => array(
                        'controller' => 'Files\Controller\Index',
                        'action'     => 'diff',
                    ),
                ),
            ),
        ),
    ),
    'controllers' => array(
        'invokables' => array(
            'Files\Controller\Index' => 'Files\Controller\IndexController'
        ),
    ),
    'view_manager' => array(
        'template_path_stack'   => array(
            __DIR__ . '/../view',
        ),
    ),
    'view_helpers' => array(
        'invokables' => array(
            'fileSize'       => 'Files\View\Helper\FileSize',
            'decodeFilespec' => 'Files\View\Helper\DecodeFilespec'
        ),
    ),
    'service_manager' => array(
        'factories' => array(
            'formats' => function () {
                return new \Files\Format\Manager;
            },
            'archiver' => function ($services) {
                $config      = $services->get('config') + array('archives' => array());
                $compression = isset($config['archives']['compression_level'])
                    ? $config['archives']['compression_level']
                    : 1;
                $archiver = new \Files\Archiver;
                $archiver->setOptions(array('compression' => $compression))
                         ->setAdapter('\Files\Filter\Compress\Zip');

                return $archiver;
            },
        ),
    ),
);
