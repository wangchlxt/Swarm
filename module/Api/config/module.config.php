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
            'api' => array(
                'type' => 'literal',
                'options' => array(
                    'route' => '/api',
                ),
                'may_terminate' => false,
                'child_routes' => array(
                    'version' => array(
                        'type' => 'Zend\Mvc\Router\Http\Segment',
                        'options' => array(
                            'route' => '/[:version/]version[/]',
                            'constraints' => array('version' => 'v([2-8]|1(\.[1-2])?)'),
                            'defaults' => array(
                                'controller' => 'Api\Controller\Index',
                                'action'     => 'version'
                            ),
                        ),
                    ),
                    'activity' => array(
                        'type' => 'Zend\Mvc\Router\Http\Segment',
                        'may_terminate' => true,
                        'options' => array(
                            'route' => '/:version/activity[/]',
                            'constraints' => array('version' => 'v([2-8]|1(\.[1-2])?)'),
                            'defaults' => array(
                                'controller' => 'Api\Controller\Activity',
                            ),
                        ),
                    ),
                    'comments' => array(
                        'type' => 'Zend\Mvc\Router\Http\Segment',
                        'options' => array(
                            'route' => '/:version/comments[/:id][/]',
                            'constraints' => array('version' => 'v([3-8])'),
                            'defaults' => array(
                                'controller' => 'Api\Controller\Comments',
                            ),
                        ),
                    ),
                    'projects' => array(
                        'type' => 'Zend\Mvc\Router\Http\Segment',
                        'may_terminate' => true,
                        'options' => array(
                            'route' => '/:version/projects[/:id][/]',
                            'constraints' => array('version' => 'v([2-8]|1(\.[1-2])?)'),
                            'defaults' => array(
                                'controller' => 'Api\Controller\Projects',
                            ),
                        ),
                    ),
                    'groups' => array(
                        'type' => 'Zend\Mvc\Router\Http\Segment',
                        'options' => array(
                            'route' => '/:version/groups[/:id][/]',
                            'constraints' => array('version' => 'v([2-8])'),
                            'defaults' => array(
                                'controller' => 'Api\Controller\Groups',
                            ),
                        ),
                    ),
                    'dashboard' => array(
                        'type' => 'Zend\Mvc\Router\Http\Segment',
                        'options' => array(
                            'route' => '/:version/dashboards/action',
                            'constraints' => array('version' => 'v([6-8])'),
                        ),
                        'child_routes' => array(
                            'review-dashboard' => array(
                                'type' => 'Zend\Mvc\Router\Http\Method',
                                'options' => array (
                                    'verb' => 'get',
                                    'defaults' => array(
                                        'controller' => 'Api\Controller\Reviews',
                                        'action'     => 'dashboard',
                                        'author'     => null
                                    ),
                                ),
                            ),
                        ),
                    ),
                    'reviews' => array(
                        'type' => 'Zend\Mvc\Router\Http\Segment',
                        'options' => array(
                            'route' => '/:version/reviews[/:id][/]',
                            'constraints' => array('version' => 'v(8|7|6|5|4|3|2|1(\.[1-2])?)'),
                            'defaults' => array(
                                'controller' => 'Api\Controller\Reviews',
                            ),
                        ),
                    ),
                    'reviews/archive' => array(
                        'type' => 'Zend\Mvc\Router\Http\Segment',
                        'options' => array(
                            'route' => '/:version/reviews/archive[/]',
                            'constraints' => array('version' => 'v([6-8])'),
                            'defaults' => array(
                                'controller' => 'Api\Controller\Reviews',
                                'action'     => 'archiveInactive',
                            ),
                        ),
                    ),
                    'reviews/changes' => array(
                        'type' => 'Zend\Mvc\Router\Http\Segment',
                        'options' => array(
                            'route' => '/:version/reviews/:id/changes[/]',
                            'constraints' => array('version' => 'v([2-8]|1(\.[1-2])?)'),
                            'defaults' => array(
                                'controller' => 'Api\Controller\Reviews',
                                'action'     => 'addChange',
                            ),
                        ),
                    ),
                    'reviews/state' => array(
                        'type' => 'Zend\Mvc\Router\Http\Segment',
                        'options' => array(
                            'route' => '/:version/reviews/:id/state[/]',
                            'constraints' => array('version' => 'v([2-8])'),
                            'defaults' => array(
                                'controller' => 'Api\Controller\Reviews',
                                'action'     => 'state',
                            ),
                        ),
                    ),
                    'reviews/cleanup' => array(
                        'type' => 'Zend\Mvc\Router\Http\Segment',
                        'options' => array(
                            'route' => '/:version/reviews/:id/cleanup[/]',
                            'constraints' => array('version' => 'v([6-8])'),
                            'defaults' => array(
                                'controller' => 'Api\Controller\Reviews',
                                'action'     => 'cleanup',
                            ),
                        ),
                    ),
                    'users/unfollowall' => array(
                        'type' => 'Zend\Mvc\Router\Http\Segment',
                        'options' => array(
                            'route' => '/:version/users/:user/unfollowall[/]',
                            'constraints' => array('version' => 'v8'),
                        ),
                        'child_routes' => array(
                            'users-unfollowall' => array(
                                'type' => 'Zend\Mvc\Router\Http\Method',
                                'options' => array (
                                    'verb' => 'post',
                                    'defaults' => array(
                                        'controller' => 'Api\Controller\Users',
                                        'action'     => 'unfollowAll',
                                    ),
                                ),
                            ),
                        ),
                    ),
                    'change/defaultreviewers' => array(
                        'type' => 'Zend\Mvc\Router\Http\Segment',
                        'options' => array(
                            'route' => '/:version/changes/:change/defaultreviewers[/]',
                            'constraints' => array('version' => 'v8'),
                            'defaults' => array(
                                'controller' => 'Api\Controller\Changes',
                                'action'     => 'defaultReviewers'
                            ),
                        ),
                    ),
                    'change/affects' => array(
                        'type' => 'Zend\Mvc\Router\Http\Segment',
                        'options' => array(
                            'route' => '/:version/changes/:change/affectsprojects[/]',
                            'constraints' => array('version' => 'v8'),
                            'defaults' => array(
                                'controller' => 'Api\Controller\Changes',
                                'action'     => 'affectsProjects'
                            ),
                        ),
                    ),
                    'notfound' => array(
                        'type' => 'Zend\Mvc\Router\Http\Regex',
                        'priority' => -100,
                        'options' => array(
                            'regex' => '/(?P<path>.*)|$',
                            'spec'  => '/%path%',
                            'defaults' => array(
                                'controller' => 'Api\Controller\Index',
                                'action'     => 'notFound',
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ),
    'controllers' => array(
        'invokables' => array(
            'Api\Controller\Activity'           => 'Api\Controller\ActivityController',
            'Api\Controller\Index'              => 'Api\Controller\IndexController',
            'Api\Controller\Projects'           => 'Api\Controller\ProjectsController',
            'Api\Controller\Reviews'            => 'Api\Controller\ReviewsController',
            'Api\Controller\Groups'             => 'Api\Controller\GroupsController',
            'Api\Controller\Comments'           => 'Api\Controller\CommentsController',
            'Api\Controller\Users'              => 'Api\Controller\UsersController',
            'Api\Controller\Changes'            => 'Api\Controller\ChangesController',
        ),
    ),
    'security' => array(
        'login_exempt' => array('api/version')
    ),
);
