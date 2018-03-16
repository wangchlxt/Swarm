<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Projects\View\Helper;

use Projects\Filter\ProjectList as ProjectListFilter;
use Projects\Model\Project;
use Zend\View\Helper\AbstractHelper;

class ProjectList extends AbstractHelper
{
    const HIDE_PRIVATE = 'hidePrivate';
    const NO_LINK      = 'noLink';
    const URL_HELPER   = 'urlHelper';
    const STYLE        = 'style';

    /**
     * Returns the markup for a project/branch list
     *
     * @param   array|string|null   $projects   the projects/branches to list
     * @param   string|null         $active     the active project if applicable
     * @param   array|null          $options      HIDE_PRIVATE - do not render private projects
     *                                                 NO_LINK - disable linking to the project
     *                                              URL_HELPER - optional, plugin/helper to use when generating links
     *                                                   STYLE - set to a string with custom styles for the link
     * @return  string              the project list html
     */
    public function __invoke($projects = null, $active = null, $options = null)
    {
        $options    = (array) $options + array(
            static::HIDE_PRIVATE => false,
            static::NO_LINK      => false,
            static::URL_HELPER   => null,
            static::STYLE        => ''
        );
        $filter     = new ProjectListFilter;
        $projects   = $filter->filter($projects);
        $view       = $this->getView();
        $services   = $view->getHelperPluginManager()->getServiceLocator();
        $justBranch = false;
        $style      = $options[static::STYLE]
            ? ' style="' . $view->escapeHtmlAttr($options[static::STYLE]) . '"'
            : '';
        $urlHelper  = $options[static::URL_HELPER] ?: array($view, 'url');

        // url helper must be a callable.
        if (!is_callable($urlHelper)) {
            throw new \InvalidArgumentException(
                'Url helper must be a callable.'
            );
        }

        // if we are hiding private projects, filter $projects list to keep only public projects
        if ($options[static::HIDE_PRIVATE]) {
            $models = Project::fetchAll(
                array(Project::FETCH_BY_IDS => array_keys($projects)),
                $services->get('p4_admin')
            );

            foreach ($projects as $project => $branches) {
                if (!isset($models[$project]) || $models[$project]->isPrivate()) {
                    unset($projects[$project]);
                }
            }
        }

        // we don't need to output the project id if we have an active project
        // with at least one branch and there are no other projects.
        if (strlen($active) && isset($projects[$active]) && count($projects[$active]) > 0 && count($projects) == 1) {
            $justBranch = true;
        }

        // generate a list of project-branch names. we will later implode with ', ' to join them
        $names = array();
        foreach ($projects as $project => $branches) {
            // if no branches for this project, just render 'project-id'
            if (!$branches) {
                if ($options[static::NO_LINK]) {
                    $names[] = $view->escapeHtml($project);
                } else {
                    $names[] = '<a href="'
                        . call_user_func($urlHelper, 'project', array('project' => $project))
                        . '"' . $style .'>'
                        . $view->escapeHtml($project)
                        . '</a>';
                }
                continue;
            }

            // if we have branches render each of them out. prefixed with project-id: if needed.
            foreach ($branches as $branch) {
                if ($options[static::NO_LINK]) {
                    $names[] = (!$justBranch ? $view->escapeHtml($project) . ':' : '')
                        . $view->escapeHtml($branch);
                } else {
                    $names[] = '<a href="'
                        . call_user_func($urlHelper, 'project', array('project' => $project))
                        . '"' . $style .'>'
                        . (!$justBranch ? $view->escapeHtml($project) . ':' : '')
                        . $view->escapeHtml($branch)
                        . '</a>';
                }
            }
        }

        return implode(", \n", $names);
    }
}
