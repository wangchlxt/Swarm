<?php
/**
 * Perforce Swarm, Community Development
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

return array(
    'router' => array(
        'routes' => array(
            'project-readme' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/project/readme/:project[/]',
                    'defaults' => array(
                        'controller' => 'Markdown\Controller\Index',
                        'action'     => 'project',
                        'project'    => null
                    ),
                ),
            ),
            'project-activity' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/project[s]/:project/activity[/]',
                    'defaults' => array(
                        'controller' => 'Markdown\Controller\Index',
                        'action'     => 'activity'
                    ),
                ),
            ),
        ),
    ),
    'view_manager' => array(
        'template_map' => array(
            'projects/index/project' => __DIR__ . '/../view/markdown/index/project.phtml',
            'projects/index/activity' => __DIR__ . '/../view/markdown/index/activity.phtml',
        ),
        'template_path_stack'   => array(
            __DIR__ . '/../view',
        ),
    ),
    'view_helpers' => array(
        'invokables' => array(
            'markupMarkdown' => 'Markdown\View\Helper\MarkupMarkdown',
        )
    ),
    'controllers' => array(
        'invokables' => array(
            'Markdown\Controller\Index' => 'Markdown\Controller\IndexController',
        ),
    ),
);
