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
use P4\Spec\Change;
use P4\Spec\Exception\NotFoundException;
use Projects\Model\Project;
use Zend\View\Model\JsonModel;
use Api\Converter\Reviewers as ReviewersConverter;

/**
 * API controller providing a service for changes
 *
 * @SWG\Resource(
 *   apiVersion="v8",
 *   basePath="/api/v8/"
 * )
 */
class ChangesController extends AbstractApiController
{

    /**
     * @SWG\Api(
     *     path="changes/{change}/defaultreviewers",
     *     @SWG\Operation(
     *         method="GET",
     *         summary="Get default reviewers for a given change id.",
     *         notes="All authenticated users are able to use this API."
     *     )
     * )
     *
     * @apiSuccessExample Successful Response:
     *     HTTP/1.1 200 OK
     *
     * {
     *      "change": {
     *          "id": "1050",
     *          "defaultReviewers": {
     *              "groups": {
     *                  "group1": {"required": "1"},
     *                  "group2": {}
     *              },
     *              "users": {
     *                  "user1": {},
     *                  "user2": {"required": "true"}
     *              }
     *          }
     *      }
     * }
     *
     * @return JsonModel
     */
    public function defaultReviewersAction()
    {
        $error    = null;
        $services = $this->getServiceLocator();
        $services->get('permissions')->enforce('authenticated');
        $changeId = $this->getEvent()->getRouteMatch()->getParam('change');
        $p4User   = $services->get('p4_user');
        // Check if the change exists.
        try {
            $change = Change::fetchById($changeId, $p4User);
        } catch (NotFoundException $e) {
            $error = $e;
        } catch (\InvalidArgumentException $e) {
            $error = $e;
        }
        if ($error) {
            return new JsonModel(
                array(
                    'isValid'  => false,
                    'messages' => $error->getMessage(),
                )
            );
        }
        $reviewers = ReviewersConverter::expandUsersAndGroups(
            Project::mergeDefaultReviewersForChange($change, array(), $p4User)
        );

        return new JsonModel(
            array(
                'change' => array('id' => $changeId, 'defaultReviewers' => $reviewers)
            )
        );
    }

    /**
     * @SWG\Api(
     *     path="changes/{change}/affectsprojects",
     *     @SWG\Operation(
     *         method="GET",
     *         summary="Get projects, and branches, affected by a given change id.",
     *         notes="All authenticated users are able to use this API."
     *     )
     * )
     *
     * @apiSuccessExample Successful Response:
     *     HTTP/1.1 200 OK
     *
     *     {
     *         "change": {
     *             "id":"1050",
     *             "projects": {
     *                 "jam": [
     *                     "live",
     *                     "main"
     *                 ]
     *             }
     *         }
     *     }
     *
     * @return JsonModel
     */
    public function affectsProjectsAction()
    {
        $error    = null;
        $services = $this->getServiceLocator();
        $services->get('permissions')->enforce('authenticated');
        $changeId = $this->getEvent()->getRouteMatch()->getParam('change');
        $p4       = $services->get('p4');
        // Check if the change exists.
        try {
            $change = Change::fetchById($changeId, $p4);
        } catch (NotFoundException $e) {
            $error = $e;
        } catch (\InvalidArgumentException $e) {
            $error = $e;
        }
        if ($error) {
            return new JsonModel(
                array(
                    'isValid'  => false,
                    'messages' => $error->getMessage(),
                )
            );
        }

        return new JsonModel(
            array(
                'change' => array('id' => $changeId, 'projects' => Project::getAffectedByChange($change, $p4))
            )
        );
    }
}
