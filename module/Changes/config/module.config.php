<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

return array(
    'router' => array(
        'routes' => array(
            'changes' => array(
                'type' => 'Application\Router\Regex',
                'options' => array(
                    'regex'    => '/changes?(/(?P<path>.*))?',
                    'spec'     => '/changes/%path%',
                    'defaults' => array(
                        'controller' => 'Changes\Controller\Index',
                        'action'     => 'changes',
                        'path'       => null
                    ),
                ),
            ),
            'change' => array(
                'type' => 'Application\Router\Regex',
                'options' => array(
                    'regex'    => '/changes?(/(?P<change>[0-9]+))/?',
                    'spec'     => '/changes/%change%',
                    'defaults' => array(
                        'controller' => 'Changes\Controller\Index',
                        'action'     => 'change',
                        'change'     => null
                    ),
                ),
            ),
            'change-fixes' => array(
                'type' => 'Application\Router\Regex',
                'options' => array(
                    'regex'    => '/changes?/(?P<change>[^/]+)/fixes(/(?P<mode>(add|delete)))?/?',
                    'spec'     => '/changes/%change%/fixes/%mode%',
                    'defaults' => array(
                        'controller' => 'Changes\Controller\Index',
                        'action'     => 'fixes',
                        'change'     => null,
                        'mode'       => null
                    ),
                ),
            ),
        ),
    ),
    'controllers' => array(
        'invokables' => array(
            'Changes\Controller\Index' => 'Changes\Controller\IndexController'
        ),
    ),
    'view_manager' => array(
        'template_map' => array(
            'changes/index/index'  => __DIR__ . '/../view/changes/index/change.phtml',
        ),
        'template_path_stack'   => array(
            __DIR__ . '/../view',
        ),
    ),
);
