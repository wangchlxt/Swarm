<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

return array(
    'reviews' => array(
        'patterns' => array(
            'octothorpe'      => array(     // #review or #review-1234 with surrounding whitespace/eol
                'regex'  => '/(?P<pre>(?:\s|^)\(?)'
                          . '\#(?P<keyword>review)(?:-(?P<id>[0-9]+))?'
                          . '(?P<post>[.,!?:;)]*(?=\s|$))/i',
                'spec'   => '%pre%#%keyword%-%id%%post%',
                'insert' => "%description%\n\n#review-%id%",
                'strip'  => '/^\s*\#review(-[0-9]+)?(\s+|$)|(\s+|^)\#review(-[0-9]+)?\s*$/i'
            ),
            'leading-square'  => array(     // [review] or [review-1234] at start
                'regex'  => '/^(?P<pre>\s*)\[(?P<keyword>review)(?:-(?P<id>[0-9]+))?\](?P<post>\s*)/i',
                'spec'   => '%pre%[%keyword%-%id%]%post%'
            ),
            'trailing-square' => array(     // [review] or [review-1234] at end
                'regex'  => '/(?P<pre>\s*)\[(?P<keyword>review)(?:-(?P<id>[0-9]+))?\](?P<post>\s*)?$/i',
                'spec'   => '%pre%[%keyword%-%id%]%post%'
            )
        ),
        'filters' => array(
            'fetch-max' => 50,
            'filter-max' => 50,
            'result_sorting' => true,
            'date_field' => 'created', // 'created' displays and sorts by created date, 'updated' displays and sorts
                                       // by last updated
            // These need to match Review::FETCH_BY...
            'hasVoted' => array(
                'fetch-max' => 5000,
            ),
            'myComments' => array(
                'fetch-max' => 2500,
            ),
        ),
        'expand_group_reviewers' => false, // whether swarm should expand group members on the review page if they
                                           // have been added as an individual.
        'cleanup'              => array(
            'mode'        => 'user', // auto - follow default, user - present checkbox(with default)
            'default'     => false,  // clean up pending changelists on commit
            'reopenFiles' => false   // re-open any opened files into the default changelist
        ),
        'disable_commit'        => false,
        'disable_self_approve'  => false, // whether authors can approve their own reviews
        'commit_credit_author'  => true,
        'commit_timeout'        => 1800,  // default: 30 minutes (must be in seconds)
        'unapprove_modified'    => true,  // whether approved reviews with modified files can be automatically
                                          // unapproved
        'ignored_users'         => array(),
        'allow_author_change'   => false, // Whether anyone can change the Author
        'sync_descriptions'     => false, // if true a changesaved event will update all reviews attached to said change
        'expand_all_file_limit' => 10,    // Controls if 'Expand all' is available for reviews by specifying a file
                                          // limit over which the option will not be available. 0 signifies always on.
        'disable_tests_on_approve_commit' => false // if set to true then automated tests will not be called when a
                                                   // review is approved and committed
    ),
    'security' => array(
        'login_exempt'  => array('review-tests', 'review-deploy')
    ),
    'router' => array(
        'routes' => array(
            'review' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/review[s]/:review[/v:version][/]',
                    'defaults' => array(
                        'controller' => 'Reviews\Controller\Index',
                        'action'     => 'review',
                        'version'    => null
                    ),
                ),
            ),
            'review-version-delete' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/review[s]/:review/v:version/delete[/]',
                    'defaults' => array(
                        'controller' => 'Reviews\Controller\Index',
                        'action'     => 'deleteVersion',
                        'version'    => null
                    ),
                ),
            ),
            'review-reviewer' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/review[s]/:review/reviewers/:user[/]',
                    'defaults' => array(
                        'controller' => 'Reviews\Controller\Index',
                        'action'     => 'reviewer',
                        'user'       => null
                    ),
                ),
            ),
            'review-reviewers' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/review[s]/:review/reviewers[/]',
                    'defaults' => array(
                        'controller' => 'Reviews\Controller\Index',
                        'action'     => 'reviewers'
                    ),
                ),
            ),
            'review-author' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/review[s]/:review/author[/:author][/]',
                    'defaults' => array(
                        'controller' => 'Reviews\Controller\Index',
                        'action'     => 'editAuthor',
                        'author'     => null
                    ),
                ),
            ),
            'review-vote' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/review[s]/:review/vote/:vote[/]',
                    'defaults' => array(
                        'controller' => 'Reviews\Controller\Index',
                        'action'     => 'vote',
                        'vote'       => null
                    ),
                ),
            ),
            'review-tests' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/review[s]/:review/tests/:status[/:token][/]',
                    'defaults' => array(
                        'controller' => 'Reviews\Controller\Index',
                        'action'     => 'testStatus',
                        'status'     => null,
                        'token'      => null
                    ),
                ),
            ),
            'review-deploy' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/review[s]/:review/deploy/:status[/:token][/]',
                    'defaults' => array(
                        'controller' => 'Reviews\Controller\Index',
                        'action'     => 'deployStatus',
                        'status'     => null,
                        'token'      => null
                    ),
                ),
            ),
            'review-transition' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/review[s]/:review/transition[/]',
                    'defaults' => array(
                        'controller' => 'Reviews\Controller\Index',
                        'action'     => 'transition'
                    ),
                ),
            ),
            'dashboards' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route' => '/dashboards/action',
                    'defaults' => array(
                        'controller' => 'Reviews\Controller\Index',
                        'action'     => 'dashboard',
                        'author' => null
                    ),
                ),
            ),
            'reviews' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/review[s][/]',
                    'defaults' => array(
                        'controller' => 'Reviews\Controller\Index',
                        'action'     => 'index'
                    ),
                ),
            ),
            'add-review' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/review[s]/add[/]',
                    'defaults' => array(
                        'controller' => 'Reviews\Controller\Index',
                        'action'     => 'add'
                    ),
                ),
            ),
            'review-file' => array(
                'type' => 'Application\Router\Regex',
                'options' => array(
                    'regex'    => '/reviews?/(?P<review>[0-9]+)/v(?P<version>[0-9,]+)/files?(/(?P<file>.*))?',
                    'spec'     => '/reviews/%review%/v%version%/files/%file%',
                    'defaults' => array(
                        'controller' => 'Reviews\Controller\Index',
                        'action'     => 'fileInfo',
                        'review'     => null,
                        'version'    => null,
                        'file'       => null
                    ),
                ),
            ),
        ),
    ),
    'controllers' => array(
        'invokables' => array(
            'Reviews\Controller\Index' => 'Reviews\Controller\IndexController'
        ),
    ),
    'service_manager' => array(
        'factories' => array(
            'review_keywords'   => function ($services) {
                $config = $services->get('config') + array('reviews' => array());
                $config = $config['reviews'] + array('patterns' => array());
                return new \Reviews\Filter\Keywords($config['patterns']);
            }
        )
    ),
    'view_manager' => array(
        'template_path_stack'   => array(
            __DIR__ . '/../view',
        ),
    ),
    'view_helpers' => array(
        'invokables' => array(
            'reviews'          => 'Reviews\View\Helper\Reviews',
            'reviewKeywords'   => 'Reviews\View\Helper\Keywords',
            'reviewersChanges' => 'Reviews\View\Helper\ReviewersChanges',
            'authorChange'     => 'Reviews\View\Helper\AuthorChange'
        ),
    ),
);
