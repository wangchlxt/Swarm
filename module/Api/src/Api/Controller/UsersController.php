<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Api\Controller;

use Api\AbstractApiController;
use P4\Connection\ConnectionInterface as Connection;
use P4\Exception;
use P4\Log\Logger;
use Projects\Model\Project;
use Users\Model\User;
use Zend\InputFilter\InputFilter;
use Zend\View\Model\JsonModel;

/**
 * Basic API controller providing a simple version action
 *
 * @SWG\Resource(
 *   apiVersion="v8",
 *   basePath="/api/v8/"
 * )
 */
class UsersController extends AbstractApiController
{
    /**
     * @SWG\Api(
     *     path="users/{user}/unfollowall",
     *     description="Unfollow all Users and Projects that the given user is following",
     *     @SWG\Operation(
     *         method="GET",
     *         summary="Unfollow all Users and Projects",
     *         notes="Admin and super users are permitted to execute unfollow all against any target
     *                user. Other users are only permitted to execute the call if they themselves
     *                are the target user"
     *     )
     * )
     *
     * @apiSuccessExample Successful Response:
     *     HTTP/1.1 200 OK
     *
     *     {
     *         "isValid": true,
     *         "messages": "User {user} is no longer following any Projects or Users."
     *     }
     *
     * @return  JsonModel
     */
    public function unfollowAllAction()
    {
        // only allow logged in users to unfollow
        $services = $this->getServiceLocator();
        $services->get('permissions')->enforce('authenticated');
        $translator = $services->get('translator');
        $isValid    = false;

        // Get the admin and current logged in user.
        $p4Admin     = $services->get('p4_admin');
        $currentUser = $services->get('user');
        $user        = $this->getEvent()->getRouteMatch()->getParam('user');

        // Check if the user that is being attempted to process exist else return.
        if (User::exists($user, $p4Admin)) {
            $user   = User::fetchById($user, $p4Admin);
            $userId = $user->getId();
        } else {
            $messages = $translator->t("User '%s' does not exist", array($user));
            return new JsonModel(
                array(
                    'isValid'  => false,
                    'messages' => $messages,
                )
            );
        }

        // Check if the current logged in user is on their own page else check if admin.
        if ($currentUser->getId() !== $userId) {
            $services->get('permissions')->enforce('admin');
        }

        try {
            // Get the followed projects
            $config   = $user->getConfig();
            $projects = Project::fetchAll(
                array( Project::FETCH_COUNT_FOLLOWERS => true ),
                $p4Admin
            );
            // filter out projects not accessible to the current user
            $projects = $services->get('projects_filter')->filter($projects);
            // Now filter the projects to only the projects we are following.
            $projects->filterByCallback(
                function (Project $project) use ($userId) {
                    return $project->isFollowing($userId) === true;
                }
            );
            // Fetch the following users as well.
            $followingUsers = $config->getFollows('user');
            // Now remove all the users that this user is following
            foreach ($followingUsers as $user) {
                $config->removeFollow($user, 'user')->save();
            }
            // Now remove all the projects this user is following.
            foreach ($projects as $project) {
                $config->removeFollow($project->getId(), 'project')->save();
            }
            // Now check that all user and projects are removed
            $projects->filterByCallback(
                function (Project $project) use ($userId) {
                    return $project->isFollowing($userId) === true;
                }
            );
            // Fetch the following users as well.
            $followingUsers = $config->getFollows('user');
            // Now check that we have removed all following items and set isValid to true.
            if (count($followingUsers) === 0 && count($projects) === 0) {
                $isValid = true;
            }
        } catch (Exception $error) {
            // Do nothing right now.
            Logger::log(Logger::ERR, "UserAPI: UnfollowAll : We ran into a problem :" . $error->getMessage());
            throw new \RuntimeException($error->getMessage());
        }
        $messages = $translator->t("User %s is no longer following any Projects or Users.", array($userId));
        return new JsonModel(
            array(
                'isValid'  => $isValid,
                'messages' => $messages,
            )
        );
    }
}
