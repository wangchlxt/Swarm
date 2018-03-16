<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

return array(
    'short_links' => array(
        'hostname'     => null,     // a dedicated host for short links - defaults to standard host
                                    // this setting will be ignored if 'external_url' is set
        'external_url' => null,     // force a custom fully qualified URL (example: "https://example.com:8488")
                                    // this setting will override 'hostname' if both are specified
                                    // if set then ['environment']['external_url'] must also be set
    ),
    'router' => array(
        'routes' => array(
            'short-link' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/l[/:link][/]',
                    'defaults' => array(
                        'controller' => 'ShortLinks\Controller\Index',
                        'action'     => 'index',
                        'link'       => null
                    ),
                ),
            ),
        ),
    ),
    'controllers' => array(
        'invokables' => array(
            'ShortLinks\Controller\Index' => 'ShortLinks\Controller\IndexController'
        ),
    ),
);
