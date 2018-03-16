<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Projects\View\Helper;

use Projects\Model\Project;
use Zend\View\Helper\AbstractHelper;

class ProjectToolbar extends AbstractHelper
{
    /**
     * Returns the markup for a project toolbar.
     *
     * @param   Project|string      $project   the project to render toolbar for
     * @param   Readme|string       $readme    the project readme
     * @return  string              markup for the project toolbar
     */
    public function __invoke($project, $readme = '')
    {
        return $this->buildToolBar($this->getView(), $project, $readme);
    }

    /**
     * Build the Toolbar for project page
     *
     * @param $view      Page view
     * @param $project   Project the toolbar is bring built for.
     * @param $readme    The project readme
     * @return string    Return a html element
     */
    public function buildToolBar($view, $project, $readme)
    {
        $services    = $view->getHelperPluginManager()->getServiceLocator();
        $permissions = $services->get('permissions');
        $event       = $services->get('Application')->getMvcEvent();
        $route       = $event->getRouteMatch()->getMatchedRouteName();
        $mode        = $event->getRouteMatch()->getParam('mode');

        // get project model if project is passed via id
        if (!$project instanceof Project) {
            $p4Admin = $services->get('p4_admin');
            $project = Project::fetch($project, $p4Admin);
        }

        $links = array();

        // Check if readme is enabled.
        $readmeEnabled = $this->fetchOverviewLink($view, $route, $project->getId(), $readme);
        if (is_array($readmeEnabled)) {
            $links[] = $readmeEnabled;
        }

        // declare project links
        $links[] =  array(
            'label'  => 'Activity',
            'url'    => $view->url('project-activity', array('project' => $project->getId())),
            'active' => $route === 'project-activity' || $route === 'activity',
            'class'  => 'activity-link'
        );
        $links[] = array(
            'label'  => 'Reviews',
            'url'    => $view->url('project-reviews', array('project' => $project->getId())),
            'active' => $route === 'project-reviews' || $route === 'review',
            'class'  => 'review-link'
        );

        // add links to view projects files and history if projects has (any) branches
        if (count($project->getBranches())) {
            $links[] = array(
                'label'  => 'Files',
                'url'    => $view->url('project-browse', array('project' => $project->getId(), 'mode' => 'files')),
                'active' => $route === 'project-browse' && $mode === 'files',
                'class'  => 'browse-link'
            );
            $links[] = array(
                'label'  => 'Commits',
                'url'    => $view->url('project-browse', array('project' => $project->getId(), 'mode' => 'changes')),
                'active' => $route === 'project-browse' && $mode === 'changes',
                'class'  => 'history-link'
            );
        }

        // add a jobs link if project has a job filter set.
        if (trim($project->get('jobview'))) {
            $links[] = array(
                'label'  => 'Jobs',
                'url'    => $view->url('project-jobs', array('project' => $project->getId())),
                'active' => $route === 'project-jobs',
                'class'  => 'jobs'
            );
        }

        // add project settings link if user has permission
        $canEdit = $project->hasOwners()
            ? $permissions->isOne(array('admin', 'owner'  => $project))
            : $permissions->isOne(array('admin', 'member' => $project));
        if ($canEdit) {
            $links[] = array(
                'label'  => 'Settings',
                'url'    => $view->url('edit-project', array('project' => $project->getId())),
                'active' => $route === 'edit-project',
                'class'  => 'settings'
            );
        }

        // render list of links
        $list = '';
        foreach ($links as $link) {
            $list .= '<li class="' . ($link['active'] ? 'active' : '') . '">'
                .  '<a href="' . $link['url'] . '" class="' . $link['class'] . '">'
                . $view->te($link['label'])
                . '</a>'
                .  '</li>';
        }

        // render project toolbar
        $name        = $view->escapeHtml($project->getName());
        $url         = $view->url('project',  array('project' => $project->getId()));
        $privateIcon = $project->isPrivate()
            ? '<i class="icon-eye-close private-project-icon" title="' . $view->te('Private Project') . '"></i>'
            : '';
        return '<div class="profile-navbar project-navbar navbar" data-project="' . $project->getId() . '">'
            . ' <div class="navbar-inner">'
            .    $privateIcon
            . '  <a class="brand force-wrap" href="' . $url . '">' . $name . '</a>'
            . '  <ul class="nav">' . $list . '</ul>'
            . ' </div>'
            . '</div>';
    }

    /**
     * Check if the readme is not disabled and it has content
     *
     * @param $view         View of page
     * @param $route        Route Swarm page is on
     * @param $projectId    Project Id working on
     * @param $readme       Readme content.
     * @return array|null   return the link in the array otherwise null.
     */
    public function fetchOverviewLink($view, $route, $projectId, $readme)
    {
        $link = null;
        if (!empty($readme)) {
            $link = array(
                'label'  => $view->te('Overview'),
                'url'    => $view->url('project', array('project' => $projectId)),
                'active' => $route === 'project',
                'class'  => 'overview-link'
            );
        }
        return $link;
    }
}
