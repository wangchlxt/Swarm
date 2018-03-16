<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Reviews\View\Helper;

use Projects\Model\Project;
use Zend\View\Helper\AbstractHelper;
use Application\Config\ConfigManager;

class Reviews extends AbstractHelper
{
    /**
     * Returns the markup for the reviews queue page.
     *
     * @param   Project|string|null     $project    optional - limit reviews to a given project
     * @return  string                  the reviews queue page markup
     */
    public function __invoke($project = null)
    {
        $view     = $this->getView();
        $services = $view->getHelperPluginManager()->getServiceLocator();
        $p4Admin  = $services->get('p4_admin');
        $user     = $services->get('user');

        // get project model if given as string
        if (is_string($project)) {
            $project = Project::fetch($project, $p4Admin);
        }

        // at this point we should have a valid project or null
        if ($project && !$project instanceof Project) {
            throw new \InvalidArgumentException(
                "Project must be a string id, project object or null."
            );
        }

        // construct options for branch/project filter
        // options are either name of projects if no project is given,
        // otherwise name of branches of the given project
        $options    = array();
        $myProjects = array();
        if ($project) {
            $config    = $services->get('config');
            $mainlines = ConfigManager::getValue($config, ConfigManager::PROJECTS_MAINLINES, array());
            $branches  = $project->getBranches('name', $mainlines);
            $prefix    = $project->getId() . ':';
            foreach ($branches as $branch) {
                $options[$prefix . $branch['id']] = $branch['name'];
            }
        } else {
            $projects = Project::fetchAll(array(), $p4Admin);

            // filter out private projects
            $projects = $services->get('projects_filter')->filter($projects);

            $options = $projects->count()
                ? array_combine(
                    $projects->invoke('getId'),
                    $projects->invoke('getName')
                ) : array();
            // Sort the projects for reviews by name instead of id
            uasort(
                $options,
                function ($a, $b) {
                    return strcasecmp($a, $b);
                }
            );
            $myProjects = ($userId = $user->getId())
                ? (array)$projects->filterByCallback(
                    function (Project $project) use ($userId) {
                        return $project->getMembershipLevelForSort($userId)>0;
                    }
                )->invoke('getId')
                : array();
        }

        // prepare reviews markup
        $id         = $project ? $project->getId() : null;
        $class      = 'reviews' . ($project ? ' project-reviews' : '');
        $openedPane = $this->renderPane('opened', $id, $options, $myProjects);
        $closedPane = $this->renderPane('closed', $id, $options, $myProjects);
        // These values are defined here to be picked up by translation both for PHP and reviews.js
        $opened           = $view->te('Opened');
        $closed           = $view->te('Closed');
        $noOpenedFiltered = $view->te('No opened reviews match your filters.');
        $noClosedFiltered = $view->te('No closed reviews match your filters.');
        $noOpened         = $view->te('No opened reviews.');
        $noClosed         = $view->te('No closed reviews.');
        $reviews          = $view->te('Reviews');
        $html             = <<<EOT
            <div class="$class">
                <h1>{$reviews}</h1>

                <ul class="nav nav-tabs">
                    <li class="active">
                        <a id="reviews-opened-tab" href="#opened" data-toggle="tab">
                            {$opened} <span class="badge opened-counter">0</span>
                        </a>
                    </li>
                    <li>
                        <a id="reviews-closed-tab" href="#closed" data-toggle="tab">
                            {$closed} <span class="badge closed-counter">0</span>
                        </a>
                    </li>
                </ul>

                <div class="tab-content">
                    <div class="tab-pane fade active in" id="opened">
                        $openedPane
                    </div>
                    <div class="tab-pane fade" id="closed">
                        $closedPane
                    </div>
                </div>
            </div>

            <script type="text/javascript">
                $(function(){
                    swarm.reviews.initProjectList(
                        {$view->json($id)}, {$view->json($options)}, {$view->json($myProjects)}
                    );
                    swarm.reviews.init();
                });
            </script>
EOT;

        return $html;
    }

    /**
     * Return markup for a given review pane (opened/closed).
     *
     * @param   string          $type       pane type - 'opened' or 'closed'
     * @param   string|null     $project    project id or null if not restricted to the project
     * @param   array           $projects   list of strings in the form of either 'project-id'
     *                                      or 'project-id:branch-id' for filtering by projects/branches
     * @param   array           $myProjects list of strings in the form of 'project-id'
     * @return  string          markup for review pane
     */
    protected function renderPane($type, $project, array $projects, array $myProjects)
    {
        $view = $this->getView();
        return $view->render(
            'reviews-pane.phtml',
            array(
                'type'       => $type,
                'project'    => $project,
                'projects'   => $projects,
                'myProjects' => $myProjects
            )
        );
    }
}
