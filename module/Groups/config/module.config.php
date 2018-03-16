<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

return array(
    'groups' => array(
        'edit_name_admin_only' => false,    // if enabled only admin users can edit group name
        'super_only'           => false,    // if enabled only super users can view groups pages
    ),
    'router' => array(
        'routes' => array(
            'group' => array(
                'type' => 'Application\Router\Regex',
                'options' => array(
                    'regex'    => '/group(s?)/(?P<group>.+[^/])(/?)',
                    'spec'     => '/groups/%group%',
                    'defaults' => array(
                        'controller' => 'Groups\Controller\Index',
                        'action'     => 'group'
                    ),
                ),
            ),
            'add-group' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/group[s]/add[/]',
                    'defaults' => array(
                        'controller' => 'Groups\Controller\Index',
                        'action'     => 'add',
                    ),
                ),
            ),
            'edit-group' => array(
                'type' => 'Application\Router\Regex',
                'options' => array(
                    'regex'    => '/group(s?)/(?P<group>.+[^/])(/?)/settings(/?)',
                    'spec'     => '/groups/%group%/settings/',
                    'defaults' => array(
                        'controller' => 'Groups\Controller\Index',
                        'action'     => 'edit'
                    ),
                ),
            ),
            'edit-notifications' => array(
                'type' => 'Application\Router\Regex',
                'options' => array(
                    'regex'    => '/group(s?)/(?P<group>.+[^/])(/?)/notifications(/?)',
                    'spec'     => '/groups/%group%/notifications/',
                    'defaults' => array(
                        'controller' => 'Groups\Controller\Index',
                        'action'     => 'notifications'
                    ),
                ),
            ),
            'delete-group' => array(
                'type' => 'Application\Router\Regex',
                'options' => array(
                    'regex'    => '/group(s?)/delete/(?P<group>.+[^/])(/?)',
                    'spec'     => '/groups/delete/%group%',
                    'defaults' => array(
                        'controller' => 'Groups\Controller\Index',
                        'action'     => 'delete'
                    ),
                ),
            ),
            'group-reviews' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/group[s][/:group]/reviews[/]',
                    'defaults' => array(
                        'controller' => 'Groups\Controller\Index',
                        'action'     => 'reviews'
                    ),
                ),
            ),
            'groups' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/groups[/]',
                    'defaults' => array(
                        'controller' => 'Groups\Controller\Index',
                        'action'     => 'groups'
                    ),
                ),
            ),
        ),
    ),
    'controllers' => array(
        'invokables' => array(
            'Groups\Controller\Index' => 'Groups\Controller\IndexController'
        ),
    ),
    'view_manager' => array(
        'template_path_stack'   => array(
            __DIR__ . '/../view',
        ),
    ),
    'view_helpers' => array(
        'invokables' => array(
            'groupToolbar'  => 'Groups\View\Helper\GroupToolbar',
            'groupSidebar'  => 'Groups\View\Helper\GroupSidebar',
            'groupAvatar'   => 'Groups\View\Helper\Avatar',
            'groupAvatars'  => 'Groups\View\Helper\Avatars',
            'groupNotificationSettings'  => 'Groups\View\Helper\NotificationSettings',
        ),
    ),
);
