<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Projects\View\Helper;

use Zend\View\Helper\AbstractHelper;

class ProjectsSidebar extends AbstractHelper
{
    /**
     * Returns the markup for a projects stream.
     *
     * @return  string  the projects stream html
     */
    public function __invoke()
    {
        $view     = $this->getView();
        $services = $view->getHelperPluginManager()->getServiceLocator();
        $config   = $services->get('config');
        return $this->buildTableBody($view, $config);
    }

    /**
     * Builds the HTML body
     * @param $view
     * @param $config
     * @return string
     */
    public function buildTableBody($view, $config)
    {
        $sortBy =
            isset($config['projects']['sidebar_sort_field']) && $config['projects']['sidebar_sort_field'] !== ''
                ? $config['projects']['sidebar_sort_field']
                : 'name';

        $addProject = $view->te("Add Project");
        $html       = <<<EOT
            <table class="table table-bordered tbody-bordered projects-sidebar">
                <thead>
                    <tr>
                        <th>
                            <div class="projects-dropdown pull-left">
                                <div role="button" tabIndex="0" aria-haspopup="true"
                                    class="dropdown-toggle" data-toggle="dropdown">
                                    <span class="projects-title">{$view->te("Projects")}</span>
                                    <span class="caret privileged"></span>
                                </div>
                                <ul class="dropdown-menu" role="menu" aria-label="{$view->te("Projects to Display")}">
                                    <li data-scope="all" role="menuitem">
                                        <a href="#">{$view->te("All Projects")}</a>
                                    </li>
                                    <li data-scope="user" role="menuitem">
                                        <a href="#">{$view->te("My Projects")}</a>
                                    </li>
                                </ul>
                            </div>
                            <div class="project-add pull-right project-add-restricted">
                                <a href="{$view->url('add-project')}"
                                   title="{$addProject}"
                                   aria-label="{$addProject}">
                                    <i class="icon-plus"></i>
                                </a>
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>

            <script type="text/javascript">
                $(function(){
                    swarm.projects.init('.projects-sidebar', "{$sortBy}");
                });
            </script>
EOT;

        return $html;
    }
}
