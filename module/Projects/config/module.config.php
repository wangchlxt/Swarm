<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

return array(
    'projects' => array(
        'mainlines'                  => array('main', 'mainline', 'master', 'trunk'),  // common mainline branch ids
        'add_admin_only'             => null,     // if enabled only admin users can create projects
        'add_groups_only'            => null,     // if set, only members of given groups can create projects
        'edit_name_admin_only'       => false,    // if enabled only admin users can edit project name
        'edit_branches_admin_only'   => false,    // if enabled only admin users can add/edit/remove project branches
        'private_by_default'         => false,    // if enabled then new projects will have 'private' option checked
        // control the project side bar sort. Supported values are 'rank', 'name' and 'id'
        'sidebar_sort_field'         => 'name',
        'sidebar_followers_disabled' => true,         // if enabled this will add followers to sidebar count.
        'max_readme_size'            => null,         // if enabled this will limit readme.md size. Size in Bytes.
        'readme_mode'                => 'restricted', // Readme are enabled by default but in "restricted" mode.
        // The mode option are "disabled", "restricted" or "unrestricted"
    ),
    'router' => array(
        'routes' => array(
            'project' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/project[s]/:project[/]',
                    'defaults' => array(
                        'controller' => 'Projects\Controller\Index',
                        'action'     => 'project'
                    ),
                ),
            ),
            'add-project' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/project[s]/add[/]',
                    'defaults' => array(
                        'controller' => 'Projects\Controller\Index',
                        'action'     => 'add',
                    ),
                ),
            ),
            'edit-project' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/project[s]/edit/:project[/]',
                    'defaults' => array(
                        'controller' => 'Projects\Controller\Index',
                        'action'     => 'edit'
                    ),
                ),
            ),
            'delete-project' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/project[s]/delete/:project[/]',
                    'defaults' => array(
                        'controller' => 'Projects\Controller\Index',
                        'action'     => 'delete'
                    ),
                ),
            ),
            'project-activity' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/project[s]/:project/activity[/]',
                    'defaults' => array(
                        'controller' => 'Projects\Controller\Index',
                        'action'     => 'activity'
                    ),
                ),
            ),
            'project-reviews' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/project[s]/:project/reviews[/]',
                    'defaults' => array(
                        'controller' => 'Projects\Controller\Index',
                        'action'     => 'reviews'
                    ),
                ),
            ),
            'project-jobs' => array(
                'type' => 'Application\Router\Regex',
                'options' => array(
                    'regex'    => '/projects?/(?P<project>.+)/jobs?(/(?P<job>.*))?',
                    'spec'     => '/projects/%project%/jobs/%job%',
                    'defaults' => array(
                        'controller' => 'Projects\Controller\Index',
                        'action'     => 'jobs',
                        'job'        => null
                    ),
                ),
            ),
            'project-browse' => array(
                'type' => 'Application\Router\Regex',
                'options' => array(
                    'regex'    => '/projects?/(?P<project>[^/]+)/'
                                . '(?P<mode>(files|view|download|changes))(/(?P<path>.*))?',
                    'spec'     => '/projects/%project%/%mode%/%path%',
                    'defaults' => array(
                        'controller' => 'Projects\Controller\Index',
                        'action'     => 'browse',
                        'path'       => null
                    ),
                ),
            ),
            'project-archive' => array(
                'type' => 'Application\Router\Regex',
                'options' => array(
                    'regex'    => '/projects?/(?P<project>[^/]+)/archives?/(?P<path>.+)\.zip',
                    'spec'     => '/projects/%project%/archives/%path%.zip',
                    'defaults' => array(
                        'controller' => 'Projects\Controller\Index',
                        'action'     => 'archive'
                    ),
                ),
            ),
            'projects' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/projects[/]',
                    'defaults' => array(
                        'controller' => 'Projects\Controller\Index',
                        'action'     => 'projects'
                    ),
                ),
            ),
        ),
    ),
    'controllers' => array(
        'invokables' => array(
            'Projects\Controller\Index' => 'Projects\Controller\IndexController'
        ),
    ),
    'view_manager' => array(
        'template_map' => array(
            'projects/index/project'    => __DIR__ . '/../view/projects/index/project.phtml',
            'projects/index/add'        => __DIR__ . '/../view/projects/index/add.phtml',
            'projects/index/activity'   => __DIR__ . '/../view/projects/index/activity.phtml'
        ),
        'template_path_stack'   => array(
            __DIR__ . '/../view',
        ),
    ),
    'view_helpers' => array(
        'invokables' => array(
            'projectList'     => 'Projects\View\Helper\ProjectList',
            'projectToolbar'  => 'Projects\View\Helper\ProjectToolbar',
            'projectSidebar'  => 'Projects\View\Helper\ProjectSidebar',
            'projectsSidebar' => 'Projects\View\Helper\ProjectsSidebar'
        ),
    ),
    'input_filters' => array(
        'factories'  => array(
            'project_filter'  => function ($manager) {
                    $services = $manager->getServiceLocator();
                    return new \Projects\Filter\Project($services->get('p4_admin'));
            },
        ),
    ),
);
