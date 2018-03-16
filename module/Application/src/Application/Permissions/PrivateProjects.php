<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Application\Permissions;

use P4\Connection\ConnectionInterface;
use P4\Model\Fielded\Iterator as ModelIterator;
use Projects\Filter\ProjectList;
use Projects\Model\Project;
use Record\Key\AbstractKey;
use Users\Model\User;

class PrivateProjects
{
    protected $p4Admin     = null;
    protected $userId      = null;
    protected $isUserSuper = false;

    /**
     * Ensure we get an admin connection and username of the user to check projects access for on construction.
     *
     * @param   ConnectionInterface         $p4Admin    admin connection
     * @param   ConnectionInterface|null    $p4User     connection of the user to determine access to projects for
     *                                                  or null for anonymous users
     */
    public function __construct(ConnectionInterface $p4Admin, ConnectionInterface $p4User = null)
    {
        $this->p4Admin     = $p4Admin;
        $this->userId      = $p4User ? $p4User->getUser()         : null;
        $this->isUserSuper = $p4User ? $p4User->isSuperUser()     : false;
    }

    /**
     * Filter models in the given $items iterator.
     * Projects are assumed to be accessible under the model field specified in $projectField, unless
     * the model is project itself. For non-project models, each item's project list will be filtered
     * and inaccessible private projects will be removed from $projectField. If the item had projects
     * but none are left after filtering, that item will be removed from the $items iterator.
     *
     * @param   ModelIterator   $items          iterator with models to filter projects from
     * @param   string|null     $projectField   name of the model's field where projects are stored
     *                                          if null, we assume the $items are projects
     * @return  ModelIterator   filtered iterator
     */
    public function filter(ModelIterator $items, $projectField = null)
    {
        // we will need project models to check for the access
        // collect project ids from across all items and pre-fetch project models at once to be a bit more effective
        $projectList = new ProjectList;
        $projectIds  = array();
        foreach ($items as $item) {
            // we don't need to process items that are projects themselves as they can be filtered directly
            if ($item instanceof Project) {
                continue;
            }

            if (!is_string($projectField) || !strlen($projectField)) {
                throw new \InvalidArgumentException("Project field must be non-empty string.");
            }

            $projects   = $projectList->filter($item->get($projectField));
            $projectIds = array_merge($projectIds, array_keys($projects));
        }

        $projectModels = Project::fetchAll(array(Project::FETCH_BY_IDS => array_unique($projectIds)), $this->p4Admin);

        // Filter models in $items with respect to the user set on this instance.
        // We filter out models that are inaccessible projects and other models with no accessible associated projects.
        // Additionally, filtered projects will be set back on models that are kept and not projects themselves.
        $filter = $this;
        return $items->filterByCallback(
            function ($item) use ($projectField, $projectModels, $filter) {
                // handle a case when item is an instance of a Project
                if ($item instanceof Project) {
                    return $filter->canAccess($item);
                }

                // keep items with no initial projects
                $projects = $item->get($projectField);
                if (!$projects) {
                    return true;
                }

                // filter projects and set them back on the model
                if (!method_exists($item, 'set')) {
                    throw new \InvalidArgumentException("Cannot set filtered projects: 'set' method not defined.");
                }
                $projects = $filter->filterList($projects, $projectModels);
                $item->set($projectField, $projects);

                // return the number of projects left after filtering
                // if no projects are left, this indicates the item should be removed
                return count($projects);
            }
        );
    }

    /**
     * Filter given projects to remove ones not accessible to the user set on this instance.
     * If project models are passed in the second argument, they will be used when checking
     * for project accessibility, otherwise project models will be fetched from the projects
     * list passed in the first argument.
     *
     * @param   array|string        $projects       projects to filter
     * @param   ModelIterator|null  $projectModels  optional - list with project models
     *                                              to use when checking project access
     * @return  array               filtered projects, returning array will have project ids in keys
     */
    public function filterList($projects, ModelIterator $projectModels = null)
    {
        $projectList   = new ProjectList;
        $projects      = $projectList->filter($projects);
        $projectModels = $projectModels
            ?: Project::fetchAll(array(Project::FETCH_BY_IDS => array_keys($projects)), $this->p4Admin);

        $filtered = array();
        foreach ($projects as $id => $branches) {
            if (isset($projectModels[$id]) && $this->canAccess($projectModels[$id])) {
                $filtered[$id] = $branches;
            }
        }

        return $filtered;
    }

    /**
     * Filter list of given users to remove ones not having access to the given project.
     *
     * @param   array|User|string   $users      list of users to filter (given either by usernames or User objects)
     * @param   Project             $project    project to filter users' access by
     * @return  array               filtered list of users containing only those having access to the given project
     */
    public function filterUsers($users, Project $project)
    {
        $filtered = array();
        $users    = !is_array($users) ? array($users) : $users;
        foreach ($users as $user) {
            if ($this->canUserAccess($user, $project)) {
                $filtered[] = $user;
            }
        }

        return $filtered;
    }

    /**
     * Return true if given item is accessible to the p4 user set on this instance, false otherwise.
     *
     * Currently we support items that are either instances of the AbstractKey class that implement
     * getProjects() method or instances of the Project class.
     *
     * Project is accessible if p4 user is super or if granted by canUserAccess() method for
     * other p4 users.
     *
     * AbstractKey record is accessible if it is not related to any projects (i.e. getProjects()
     * returns empty list) or if at least one project returned by getProjects() is accessible to
     * the p4 user.
     *
     * @param   mixed       $item   item (Project or model implementing getProjects() method) to
     *                              check accessibility for
     * @return  bool        true if item is accessible for the user in question, false otherwise
     */
    public function canAccess($item)
    {
        // access to projects is always granted for Perforce super users
        // for other users, access is determined via canUserAccess() method
        if ($item instanceof Project) {
            return $this->isUserSuper || $this->canUserAccess($this->userId, $item);
        }

        // access to other records is granted if the record is not related to any
        // projects of if user can access at least one of the related projects
        if ($item instanceof AbstractKey && method_exists($item, 'getProjects')) {
            $projects = $item->getProjects();
            return !$projects || $this->filterList($projects);
        }

        throw new \InvalidArgumentException(
            "Item must be a Project or an AbstractKey record implementing getProjects() method."
        );
    }

    /**
     * Return true if given project is accessible to the given user, false otherwise.
     * Project is accessible if it is public or if the user is among users with allowed
     * access to the given project.
     *
     * @param   User|string|null    $user           user to determine access for
     * @param   Project             $project        project to check access against
     * @return  bool                true if project is accessible for the user, false otherwise
     */
    public function canUserAccess($user, Project $project)
    {
        $user = $user instanceof User ? $user->getId() : $user;
        if (!is_string($user) && !is_null($user)) {
            throw new \InvalidArgumentException("Given user must be a user model, string or null.");
        }

        // public projects are accessible by anyone
        if (!$project->isPrivate()) {
            return true;
        }

        // anonymous users cannot access any private projects
        if ($user === null) {
            return false;
        }

        // at this point we have a user and a private project, only grant access to allowed users
        return in_array($user, $this->getUsersWithAccess($project));
    }

    /**
     * Return list of users with access to the given project.
     * Access is allowed to project members, owners and moderators of any branch.
     *
     * @param   Project     $project    project to get users with access for
     * @return  array       list of users with access to the given project
     */
    public function getUsersWithAccess(Project $project)
    {
        return array_unique(
            array_merge(
                $project->getAllMembers(),
                $project->getOwners(),
                $project->getModerators()
            )
        );
    }
}
