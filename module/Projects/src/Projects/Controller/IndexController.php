<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Projects\Controller;

use Application\Filter\Preformat;
use Application\Config\ConfigManager;
use Projects\Filter\Project as ProjectFilter;
use Projects\Model\Project;
use Record\Exception\NotFoundException;
use Users\Model\User;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;
use Zend\View\Model\ViewModel;

class IndexController extends AbstractActionController
{
    public function addAction()
    {
        // ensure user is permitted to add projects
        $this->getServiceLocator()->get('permissions')->enforce('projectAddAllowed');

        // force the 'id' field to have the value of name
        // the input filtering will reformat it for us.
        $request = $this->getRequest();
        $request->getPost()->set('id', $request->getPost('name'));

        return $this->doAddEdit(ProjectFilter::MODE_ADD);
    }

    public function editAction()
    {
        // before we call the doAddEdit method we need to ensure the
        // project exists and the user has rights to edit it.
        $project = $this->getRequestedProject();
        if (!$project) {
            return;
        }

        // ensure only admin/super or project members/owners can delete the entry
        $checks = $project->hasOwners()
            ? array('admin', 'owner'  => $project)
            : array('admin', 'member' => $project);
        $this->getServiceLocator()->get('permissions')->enforceOne($checks);

        // ensure the id in the post is the value passed in the url.
        // we don't want to risk having differing opinions.
        $this->getRequest()->getPost()->set('id', $project->getId());

        // hydrate members and subgroups to ensure accuracy when editing
        $project->getMembers();

        return $this->doAddEdit(ProjectFilter::MODE_EDIT, $project);
    }

    public function deleteAction()
    {
        $translator = $this->getServiceLocator()->get('translator');

        // request must be a post or delete
        $request = $this->getRequest();
        if (!$request->isPost() && !$request->isDelete()) {
            return new JsonModel(
                array(
                    'isValid'   => false,
                    'error'     => $translator->t('Invalid request method. HTTP POST or HTTP DELETE required.')
                )
            );
        }

        // attempt to retrieve the specified project to delete
        $project = $this->getRequestedProject(array(Project::FETCH_INCLUDE_DELETED => true));
        if (!$project) {
            return new JsonModel(
                array(
                    'isValid'   => false,
                    'error'     => $translator->t('Cannot delete project: project not found.')
                )
            );
        }

        // ensure only admin/super or project members/owners can delete the entry
        $checks = $project->hasOwners()
            ? array('admin', 'owner'  => $project)
            : array('admin', 'member' => $project);
        $this->getServiceLocator()->get('permissions')->enforceOne($checks);

        // shallow delete the project - we don't permanently remove the record, but set the 'deleted' field
        // to true so the project becomes hidden in general view
        // Expect -d action=undelete/delete as the primary use case
        $deleteMode = $request->getPost('action');
        if ($deleteMode === null) {
            // Look for action as a query parameter, if it wasn't already found
            $deleteMode = $request->getQuery()->get('action')?:"delete";
        }
        $project->setDeleted("delete" === $deleteMode)->save();

        return new JsonModel(
            array(
                'isValid' => true,
                'id'      => $project->getId()
            )
        );
    }

    /**
     * This is a shared method to power both add and edit actions.
     *
     * @param   string          $mode       one of 'add' or 'edit'
     * @param   Project|null    $project    only passed on edit, the project for starting values
     * @return  ViewModel       the data needed to render an add/edit view
     */
    protected function doAddEdit($mode, Project $project = null)
    {
        $services = $this->getServiceLocator();
        $p4Admin  = $services->get('p4_admin');
        $config   = $services->get('config');
        $request  = $this->getRequest();

        // decide whether user can edit project name/branches
        $nameAdminOnly     = isset($config['projects']['edit_name_admin_only'])
            ? (bool) $config['projects']['edit_name_admin_only']
            : false;
        $branchesAdminOnly = isset($config['projects']['edit_branches_admin_only'])
            ? (bool) $config['projects']['edit_branches_admin_only']
            : false;
        $canEditName       = !$nameAdminOnly     || $services->get('permissions')->is('admin');
        $canEditBranches   = !$branchesAdminOnly || $services->get('permissions')->is('admin');

        if ($request->isPost()) {
            $data = $request->getPost();

            // set up our filter with data and the add/edit mode
            $filter = $services->get('InputFilterManager')->get('ProjectFilter');
            $filter->setMode($mode)
                   ->setData($data);

            // mark name/branches fields not-allowed if user cannot modify them
            // this will cause an error if data for these fields are posted
            if ($project) {
                !$canEditName     && $filter->setNotAllowed('name');
                !$canEditBranches && $filter->setNotAllowed('branches');
            }

            // if we are in edit mode, set the validation group to process
            // only defined fields we received posted data for
            if ($filter->isEdit()) {
                $filter->setValidationGroupSafe(array_keys($data->toArray()));
            }

            // if the data is valid, setup the project and save it
            $isValid = $filter->isValid();
            if ($isValid) {
                $values  = $filter->getValues();
                $project = $project ?: new Project($p4Admin);
                $project->set($values)
                        ->save();

                // remove followers for private projects
                if ($project->isPrivate()) {
                    foreach ($project->getFollowers() as $user) {
                        $config = User::fetchById($user, $p4Admin)->getConfig();
                        $config->removeFollow($project->getId(), 'project')->save();
                    }
                }
            }

            if ($project) {
                $projectData           = $project->get();
                $projectData['tests']  = $project->getTests();
                $projectData['deploy'] = $project->getDeploy();
            }

            return new JsonModel(
                array(
                     'isValid'   => $isValid,
                     'messages'  => $filter->getMessages(),
                     'redirect'  => $this->getRequest()->getBaseUrl() . '/projects/' . $filter->getValue('id'),
                     'project'   => isset($projectData) ? $projectData : null
                )
            );
        }

        // prepare view for form.
        $privateByDefault = isset($config['projects']['private_by_default'])
            ? (bool) $config['projects']['private_by_default']
            : false;
        $view             = new ViewModel;
        $view->setVariables(
            array(
                'mode'             => $mode,
                'project'          => $project ?: new Project($p4Admin),
                'canEditName'      => $canEditName,
                'canEditBranches'  => $canEditBranches,
                'privateByDefault' => $privateByDefault
            )
        );

        return $view;
    }

    public function projectAction()
    {
        $project = $this->getRequestedProject();
        if (!$project) {
            return;
        }

        // Fetch readme file and process output.
        $readme = $this->processReadme($project);

        if ($this->getRequest()->getQuery()->get('format') === 'json') {
            $projectData = $project->get();

            // ensure only admin/super or project members/owners can see tests/deploy
            $checks = $project->hasOwners()
                ? array('admin', 'owner'  => $project)
                : array('admin', 'member' => $project);

            if ($this->getServiceLocator()->get('permissions')->isOne($checks)) {
                $projectData['tests']  = $project->getTests();
                $projectData['deploy'] = $project->getDeploy();
            }

            return new JsonModel(array('project' => $projectData, 'readme' => $readme));
        }

        return new ViewModel(array('project' => $project, 'readme' => $readme));
    }

    /**
     * This is going to check the readme.md exist and process the file to be displayed.
     *
     * @param   Project|null    $project    The project for starting values
     * @return  readme          The data needed to render the readme
     */
    public function processReadme($project)
    {
        $services     = $this->getServiceLocator();
        $config       = $services->get('config');
        $readmeConfig = ConfigManager::getValue($config, ConfigManager::PROJECTS_README_MODE, 'restricted');
        $readme       = '';
        switch ($readmeConfig) {
            case 'disabled':
                break;
            default:
                $mainlines      = ConfigManager::getValue($config, ConfigManager::PROJECTS_MAINLINES, array());
                $maxReadmeSize  = ConfigManager::getValue($config, ConfigManager::PROJECTS_MAX_README_SIZE, null);
                $markdownHelper = $services->get('ViewHelperManager');
                $readme         = $project->getReadme($mainlines, $maxReadmeSize, $markdownHelper);
                break;
        }
        return $readme;
    }

    public function projectsAction()
    {
        $query    = $this->getRequest()->getQuery();
        $services = $this->getServiceLocator();
        $user     = $services->get('user')->getId();
        $p4Admin  = $services->get('p4_admin');
        $config   = $services->get('config');
        // Get the settings for if we disable the sidebar.

        $followersDisabled = ConfigManager::getValue(
            $config,
            ConfigManager::PROJECTS_SIDEBAR_FOLLOWERS_DISABLED
        );
        // fetch all projects
        $projects = Project::fetchAll(
            array(),
            $p4Admin
        );

        // filter out projects not accessible to the current user
        $projects = $services->get('projects_filter')->filter($projects);

        // prepare data for output
        // include a virtual isMember field
        // by default, html'ize the description and provide the count of followers and members
        // pass listUsers   = true to instead get the listing of follower/member ids
        // pass disableHtml = true to stop html'izing the description
        $data        = array();
        $preformat   = new Preformat($this->getRequest()->getBaseUrl());
        $listUsers   = (bool) $query->get('listUsers',   false);
        $disableHtml = (bool) $query->get('disableHtml', false);
        $allFields   = (bool) $query->get('allFields',   false);
        $idsOnly     = (bool) $query->get('idsOnly',     false);

        // if asked for ids only, return list with projects ids
        if ($idsOnly) {
            return new JsonModel($projects->invoke('getId'));
        }

        foreach ($projects as $project) {
            $values = $allFields
                ? $project->get()
                : array('id' => $project->getId(), 'name' => $project->getName(), 'isPrivate' => $project->isPrivate());

            // get list of members, but flipped so we can easily check if user is a member
            // in the API route case (allFields = true), we will already have them
            $members           = isset($values['members'])
                ? array_flip($values['members'])
                : $project->getAllMembers(true);
            $values['members'] = $listUsers ? array_flip($members) : count($members);

            // If followers is disabled don't process the followers.
            // The call to fetch followers can be slow and a large performance hit.
            if ($followersDisabled) {
                $values['followersDisabled'] = true;
            } else {
                // get list of followers or count of followers if listUsers is false
                // followers that are also members will be excluded
                $followers           = $project->getFollowers(array_flip($members));
                $values['followers'] = $listUsers ? $followers : count($followers);
            }

            if ($user) {
                $values['isMember'] = isset($members[$user]);
            }

            $values['description'] = $disableHtml
                ? $project->getDescription()
                : $preformat->filter($project->getDescription());

            $data[] = $values;
        }

        return new JsonModel($data);
    }

    public function activityAction()
    {
        $project = $this->getRequestedProject();
        if (!$project) {
            return;
        }

        if ($this->getRequest()->getQuery()->get('format') === 'json') {
            $projectData = $project->get();

            // ensure only admin/super or project members/owners can see tests/deploy
            $checks = $project->hasOwners()
                ? array('admin', 'owner'  => $project)
                : array('admin', 'member' => $project);

            if ($this->getServiceLocator()->get('permissions')->isOne($checks)) {
                $projectData['tests']  = $project->getTests();
                $projectData['deploy'] = $project->getDeploy();
            }

            return new JsonModel(array('project' => $projectData));
        }

        return new ViewModel(
            array(
                'project' => $project,
                'readme'  => $this->processReadme($project)
            )
        );
    }

    public function reviewsAction()
    {
        $query   = $this->getRequest()->getQuery();
        $project = $this->getRequestedProject();
        if (!$project) {
            return;
        }

        // forward json requests to the reviews module
        if ($query->get('format') === 'json') {
            // if query doesn't already contain a filter for project, add one
            $query->set('project', $query->get('project') ?: $project->getId());

            return $this->forward()->dispatch(
                'Reviews\Controller\Index',
                array('action' => 'index', 'activeProject' => $project->getId())
            );
        }

        return new ViewModel(
            array(
                'project' => $project,
                'readme'  => $this->processReadme($project)
            )
        );
    }

    public function jobsAction()
    {
        $project = $this->getRequestedProject();
        if (!$project) {
            return;
        }


        return $this->forward()->dispatch(
            'Jobs\Controller\Index',
            array(
                'action'    => 'job',
                'project'   => $project,
                'job'       => $this->getEvent()->getRouteMatch()->getParam('job')
            )
        );
    }

    public function browseAction()
    {
        $project = $this->getRequestedProject();
        if (!$project) {
            return;
        }

        $route = $this->getEvent()->getRouteMatch();
        $mode  = $route->getParam('mode');
        $path  = $route->getParam('path');

        // based on the mode, redirect to changes or files
        if ($mode === 'changes') {
            return $this->forward()->dispatch(
                'Changes\Controller\Index',
                array(
                    'action'    => 'changes',
                    'path'      => $path,
                    'project'   => $project,
                )
            );
        } else {
            return $this->forward()->dispatch(
                'Files\Controller\Index',
                array(
                    'action'    => 'file',
                    'path'      => $path,
                    'project'   => $project,
                    'view'      => $mode === 'view'     ? true : null,
                    'download'  => $mode === 'download' ? true : null,
                    'readme'    => $this->processReadme($project)
                )
            );
        }
    }

    public function archiveAction()
    {
        $project = $this->getRequestedProject();
        if (!$project) {
            return;
        }

        $route = $this->getEvent()->getRouteMatch();
        $path  = $route->getParam('path');

        // archiving is handled by the Files module
        return $this->forward()->dispatch(
            'Files\Controller\Index',
            array(
                'action'  => 'archive',
                'path'    => $path,
                'project' => $project,
            )
        );
    }

    /**
     * Helper method to return model of requested project or false if project
     * id is missing, invalid or the project is not accessible for the current user.
     *
     * @return  Project|false   project model or false if project id is missing or invalid
     */
    protected function getRequestedProject($options = array())
    {
        $id      = $this->getEvent()->getRouteMatch()->getParam('project');
        $p4Admin = $this->getServiceLocator()->get('p4_admin');

        // attempt to retrieve the specified project
        // translate invalid/missing id's into a 404
        $options += array(Project::FETCH_BY_IDS => array($id));
        try {
            $project = Project::fetchAll($options, $p4Admin)->first();
        } catch (NotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }

        // return the project if its accessible for the current user, otherwise treat
        // inaccessible projects as "not found" to prevent information leakage
        if ($project && $this->getServiceLocator()->get('projects_filter')->canAccess($project)) {
            return $project;
        }

        $this->getResponse()->setStatusCode(404);
        return false;
    }
}
