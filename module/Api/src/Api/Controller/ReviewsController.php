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
use Groups\Model\Group;
use Reviews\Model\Review;
use Zend\Http\Request;
use Zend\View\Model\JsonModel;
use Application\Filter\FormBoolean;
use Groups\Model\Config as GroupConfig;

/**
 * Swarm Reviews
 *
 * @SWG\Resource(
 *   apiVersion="v8",
 *   basePath="/api/v8/"
 * )
 */
class ReviewsController extends AbstractApiController
{
    /**
     * @SWG\Api(
     *     path="reviews/{id}",
     *     @SWG\Operation(
     *         method="GET",
     *         summary="Get Review Information",
     *         notes="Retrieve information about a review.",
     *         nickname="getReview",
     *         @SWG\Parameter(
     *             name="id",
     *             description="Review ID",
     *             paramType="path",
     *             type="integer",
     *             required=true
     *         ),
     *         @SWG\Parameter(
     *             name="fields",
     *             description="An optional comma-separated list (or array) of fields to show. Omitting this parameter
     *                          or passing an empty value shows all fields.",
     *             paramType="query",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\ResponseMessage(code=404, message="Not Found"),
     *         @SWG\ResponseMessage(code=401, message="Unauthorized")
     *     )
     * )
     *
     * @apiUsageExample Fetching a review
     *
     *   To fetch a review:
     *
     *   ```bash
     *   curl -u "username:password" "https://my-swarm-host/api/v8/reviews/123"
     *   ```
     *
     *   Swarm responds with a review entity:
     *
     *   ```json
     *   {
     *     "review": {
     *       "id": 123,
     *       "author": "bruno",
     *       "changes": [122,124],
     *       "commits": [124],
     *       "commitStatus": [],
     *       "created": 1399325913,
     *       "deployDetails": [],
     *       "deployStatus": null,
     *       "description": "Adding .jar that should have been included in r110\n",
     *       "groups": [],
     *       "participants": {
     *         "alex_qc": [],
     *         "bruno": {
     *           "vote": 1,
     *           "required": true
     *         },
     *         "vera": []
     *       },
     *       "reviewerGroups": {
     *         "group1" : [],
     *         "group2" : {
     *           "required" : true
     *         },
     *         "group3" : {
     *           "required" : true,
     *           "quorum": "1"
     *         }
     *       },
     *       "pending": false,
     *       "projects": {
     *         "swarm": ["main"]
     *       },
     *       "state": "archived",
     *       "stateLabel": "Archived",
     *       "testDetails": {
     *         "url": "http://jenkins.example.com/job/project_ci/123/"
     *       },
     *       "testStatus": null,
     *       "type": "default",
     *       "updated": 1399325913,
     *       "versions": []
     *     }
     *   }
     *   ```
     *
     * @apiSuccessExample Successful Response:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "review": {
     *         "id": 12204,
     *         "author": "bruno",
     *         "changes": [10667],
     *         "commits": [10667],
     *         "commitStatus": [],
     *         "created": 1399325913,
     *         "deployDetails": [],
     *         "deployStatus": null,
     *         "description": "Adding .jar that should have been included in r10145\n",
     *         "participants": {
     *           "alex_qc": [],
     *           "bruno": {
     *             "vote": 1,
     *             "required": true
     *           },
     *           "vera": []
     *         },
     *         "reviewerGroups": {
     *           "group1" : [],
     *           "group2" : {
     *             "required" : true
     *           },
     *           "group3" : {
     *             "required" : true,
     *             "quorum": "1"
     *           }
     *         },
     *         "pending": false,
     *         "projects": {
     *           "swarm": ["main"]
     *         },
     *         "state": "archived",
     *         "stateLabel": "Archived",
     *         "testDetails": {
     *           "url": "http://jenkins.example.com/job/project_ci/123/"
     *         },
     *         "testStatus": null,
     *         "type": "default",
     *         "updated": 1399325913
     *       }
     *     }
     *
     * @apiErrorExample Example 404 Response:
     *     HTTP/1.1 404 Not Found
     *
     *     {
     *       "error": "Not Found"
     *     }
     *
     * @param   mixed   $id
     * @return  JsonModel
     */
    public function get($id)
    {
        $fields = $this->getRequest()->getQuery('fields');
        $result = $this->forward('Reviews\Controller\Index', 'review', array('review' => $id));

        return $this->getResponse()->isOk()
            ? $this->prepareSuccessModel(array('review' => $result->getVariable('review')), $fields)
            : $this->prepareErrorModel($result);
    }

    /**
     * @SWG\Api(
     *     path="reviews/",
     *     @SWG\Operation(
     *         method="POST",
     *         summary="Create a Review",
     *         notes="Pass in a changelist ID to create a review. Optionally, you can also provide a
     *                description and a list of reviewers.",
     *         nickname="createReview",
     *         @SWG\Parameter(
     *             name="change",
     *             description="Change ID to create a review from",
     *             paramType="form",
     *             type="integer",
     *             required=true
     *         ),
     *         @SWG\Parameter(
     *             name="description",
     *             description="Description for the new review (defaults to change description)",
     *             paramType="form",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="reviewers",
     *             description="A list of reviewers for the new review",
     *             paramType="form",
     *             type="array",
     *             required=false,
     *             @SWG\Items("string")
     *         ),
     *         @SWG\Parameter(
     *             name="requiredReviewers",
     *             description="A list of required reviewers for the new review (v1.1+)",
     *             paramType="form",
     *             type="array",
     *             required=false,
     *             @SWG\Items("string")
     *         ),
     *         @SWG\Parameter(
     *             name="reviewerGroups",
     *             description="A list of required reviewers for the new review (v7+)",
     *             paramType="form",
     *             type="array",
     *             required=false
     *         ),
     *         @SWG\ResponseMessage(code=400, message="Bad Request"),
     *         @SWG\ResponseMessage(code=401, message="Unauthorized")
     *     )
     * )
     *
     * @apiUsageExample Starting a review
     *
     *   To start a review for a committed change or a non-empty shelved changelist specifying reviewer groups:
     *
     *   ```bash
     *   curl -u "username:password" \
     *        -d "change=122" \
     *        -d "reviewerGroups[0][name]=group1" \
     *        -d "reviewerGroups[1][name]=group2" \
     *        -d "reviewerGroups[1][required]=true" \
     *        -d "reviewerGroups[2][name]=group3" \
     *        -d "reviewerGroups[2][required]=true" \
     *        -d "reviewerGroups[2][quorum]=1" \
     *        "https://my-swarm-host/api/v8/reviews/"
     *   ```
     *
     *   Swarm responds with the new review entity:
     *
     *   ```json
     *   {
     *     "review": {
     *       "id": 123,
     *       "author": "bruno",
     *       "changes": [122],
     *       "commits": [],
     *       "commitStatus": [],
     *       "created": 1399325913,
     *       "deployDetails": [],
     *       "deployStatus": null,
     *       "description": "Adding .jar that should have been included in r110\n",
     *       "groups": [],
     *       "participants": {
     *         "bruno": []
     *       },
     *       "reviewerGroups": {
     *         "group1" : [],
     *         "group2" : {
     *           "required" : true
     *         },
     *         "group3" : {
     *           "required" : true,
     *           "quorum": "1"
     *         }
     *       },
     *       "pending": true,
     *       "projects": [],
     *       "state": "needsReview",
     *       "stateLabel": "Needs Review",
     *       "testDetails": [],
     *       "testStatus": null,
     *       "type": "default",
     *       "updated": 1399325913,
     *       "versions": []
     *     }
     *   }
     *   ```
     *
     * @apiSuccessExample Successful Response contains Review Entity:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "review": {
     *         "id": 12205,
     *         "author": "bruno",
     *         "changes": [10667],
     *         "commits": [10667],
     *         "commitStatus": [],
     *         "created": 1399325913,
     *         "deployDetails": [],
     *         "deployStatus": null,
     *         "description": "Adding .jar that should have been included in r10145\n",
     *         "participants": {
     *           "bruno": []
     *         },
     *         "reviewerGroups": {
     *           "group1" : [],
     *           "group2" : {
     *             "required" : true
     *           },
     *           "group3" : {
     *             "required" : true,
     *             "quorum": "1"
     *           }
     *         },
     *         "pending": false,
     *         "projects": [],
     *         "state": "archived",
     *         "stateLabel": "Archived",
     *         "testDetails": [],
     *         "testStatus": null,
     *         "type": "default",
     *         "updated": 1399325913
     *       }
     *     }
     *
     * @param   mixed   $data
     * @return  JsonModel
     */
    public function create($data)
    {
        $post = array(
            'change'      => isset($data['change'])      ? $data['change']      : null,
            'description' => isset($data['description']) ? $data['description'] : null,
            'reviewers'   => isset($data['reviewers'])   ? $data['reviewers']   : null
        );

        // if the api is 1.1 or newer, include required reviewers
        if ($this->getEvent()->getRouteMatch()->getParam('version') !== "v1") {
            $post['requiredReviewers'] = isset($data['requiredReviewers']) ? $data['requiredReviewers'] : null;
        }
        $version = $this->getEvent()->getRouteMatch()->getParam('version');
        // REVIEWER_GROUPS are only supported in API v7 and up
        if (isset($data[Review::REVIEWER_GROUPS])) {
            if (in_array($version, array('v1', 'v1.1', 'v1.2', 'v2', 'v3', 'v4', 'v5', 'v6'))) {
                $this->response->setStatusCode(405);
                return $this->prepareErrorModel(
                    new JsonModel(
                        array(
                            'error' => Review::REVIEWER_GROUPS . ' parameter is only supported for v7+ of the API'
                        )
                    )
                );
            } else {
                $post[Review::REVIEWER_GROUPS] = $data[Review::REVIEWER_GROUPS];
                $this->translateReviewerGroups($post);
            }
        }

        $result = $this->forward(
            'Reviews\Controller\Index',
            'add',
            null,
            null,
            $post
        );

        if (!$result->getVariable('isValid')) {
            $this->getResponse()->setStatusCode(400);
            return $this->prepareErrorModel($result);
        }

        return $this->prepareSuccessModel($result);
    }

    /**
     * @SWG\Api(
     *     path="reviews/{id}/changes/",
     *     @SWG\Operation(
     *         method="POST",
     *         summary="Add Change to Review",
     *         notes="Links the given change to the review and schedules an update.",
     *         nickname="addChange",
     *         @SWG\Parameter(
     *             name="id",
     *             description="Review ID",
     *             paramType="path",
     *             type="integer",
     *             required=true
     *         ),
     *         @SWG\Parameter(
     *             name="change",
     *             description="Change ID",
     *             paramType="form",
     *             type="integer",
     *             required=true
     *         ),
     *         @SWG\ResponseMessage(code=400, message="Bad Request"),
     *         @SWG\ResponseMessage(code=404, message="Not Found"),
     *         @SWG\ResponseMessage(code=405, message="Method Not Allowed"),
     *         @SWG\ResponseMessage(code=401, message="Unauthorized")
     *     )
     * )
     *
     * @apiUsageExample Adding a change to a review
     *
     *   You may want to update a review from a shelved or committed change that is different from the initiating
     *   change. This is done by adding a change to the review.
     *
     *   To add a change:
     *
     *   ```bash
     *   curl -u "username:password" -d "change=124" "https://my-swarm-host/api/v8/reviews/123/changes/"
     *   ```
     *
     *   Swarm responds with the updated review entity:
     *
     *   ```json
     *   {
     *     "review": {
     *       "id": 123,
     *       "author": "bruno",
     *       "changes": [122, 124],
     *       "commits": [],
     *       "commitStatus": [],
     *       "created": 1399325913,
     *       "deployDetails": [],
     *       "deployStatus": null,
     *       "description": "Adding .jar that should have been included in r110\n",
     *       "groups": [],
     *       "participants": {
     *         "bruno": []
     *       },
     *       "pending": true,
     *       "projects": [],
     *       "state": "needsReview",
     *       "stateLabel": "Needs Review",
     *       "testDetails": [],
     *       "testStatus": null,
     *       "type": "default",
     *       "updated": 1399325913,
     *       "versions": [
     *         {
     *           "difference": 1,
     *           "stream": null,
     *           "change": 124,
     *           "user": "bruno",
     *           "time": 1399330003,
     *           "pending": true,
     *           "archiveChange": 124
     *         }
     *       ]
     *     }
     *   }
     *   ```
     *
     * @apiSuccessExample Successful Response contains Review Entity:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "review": {
     *         "id": 12206,
     *         "author": "bruno",
     *         "changes": [10667, 12000],
     *         "commits": [10667, 12000],
     *         "commitStatus": [],
     *         "created": 1399325913,
     *         "deployDetails": [],
     *         "deployStatus": null,
     *         "description": "Adding .jar that should have been included in r10145\n",
     *         "participants": {
     *           "bruno": []
     *         },
     *         "pending": false,
     *         "projects": [],
     *         "state": "archived",
     *         "stateLabel": "Archived",
     *         "testDetails": [],
     *         "testStatus": null,
     *         "type": "default",
     *         "updated": 1399325913
     *       }
     *     }
     *
     * @return  JsonModel
     */
    public function addChangeAction()
    {
        $request  = $this->getRequest();
        $response = $this->getResponse();

        // this method is not inherently limited to post, so we check it explicitly
        if (!$request->isPost()) {
            $response->setStatusCode(405);
            return;
        }

        $review = $this->getEvent()->getRouteMatch()->getParam('id');
        $change = $request->getPost('change');

        $result = $this->forward(
            'Reviews\Controller\Index',
            'add',
            null,
            null,
            array('id' => $review, 'change' => $change)
        );

        if (!$result->getVariable('isValid')) {
            if ($response->isOk()) {
                $response->setStatusCode(400);
            }

            // the legacy endpoint returns 404 for a bad change, which is technically incorrect
            // as 404's refer specifically to invalid URIs and change is not in the URI
            if ($response->getStatusCode() === 404 && strlen($result->getVariable('change'))) {
                $response->setStatusCode(400);
            }

            return $this->prepareErrorModel($result);
        }

        return $this->prepareSuccessModel($result);
    }

    /**
     * @SWG\Api(
     *     path="reviews/{id}/state/",
     *     @SWG\Operation(
     *         method="PATCH",
     *         summary="Transition the Review State (v2+)",
     *         notes="Transition the review to a new state. When transitioning to approved, you can optionally
     *                commit the review. (v2+)",
     *         nickname="state",
     *         @SWG\Parameter(
     *             name="id",
     *             description="Review ID",
     *             paramType="path",
     *             type="integer",
     *             required=true
     *         ),
     *         @SWG\Parameter(
     *             name="state",
     *             description="Review State. Valid options: needsReview, needsRevision, approved, archived, rejected",
     *             paramType="form",
     *             type="string",
     *             required=true
     *         ),
     *         @SWG\Parameter(
     *             name="description",
     *             description="An optional description that is posted as a comment for non-commit transitions.
     *                          Commits that do not include a description default to using the Review description
     *                          in the resulting change description.",
     *             paramType="form",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="commit",
     *             description="Set this flag to true and provide a state of `approved` in order to trigger the
     *                          *Approve and Commit* action in Swarm.",
     *             paramType="form",
     *             type="boolean",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="wait",
     *             description="Instruct Swarm to wait for a commit to finish before returning.",
     *             paramType="form",
     *             type="boolean",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="jobs[]",
     *             description="When performing an 'Approve and Commit', one or more jobs can be attached to the review
     *                          as part of the commit process.",
     *             paramType="form",
     *             type="stringArray",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="fixStatus",
     *             description="Provide a fix status for the attached job(s) when performing an 'Approve and Commit'.
     *                          Possible status values vary by job specification, but often include:
     *                          open, suspended, closed, review, fixed.",
     *             paramType="form",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\ResponseMessage(code=400, message="Bad Request"),
     *         @SWG\ResponseMessage(code=404, message="Not Found"),
     *         @SWG\ResponseMessage(code=405, message="Method Not Allowed"),
     *         @SWG\ResponseMessage(code=401, message="Unauthorized")
     *     )
     * )
     *
     * @apiUsageExample Committing a review
     *
     *   To commit a review:
     *
     *   ```bash
     *   curl -u "username:password" -X PATCH -d "state=approved" -d "commit=1" \
     *        "https://my-swarm-host/api/v8/reviews/123/state/"
     *   ```
     *
     *   Swarm responds with the updated review entity, as well as a list of possible transitions for the review:
     *
     *   ```json
     *   {
     *     "review": {
     *       "id": 123,
     *       "author": "bruno",
     *       "changes": [122, 124],
     *       "commits": [124],
     *       "commitStatus": {
     *           "start": 1399326910,
     *           "change": 124,
     *           "status": "Committed",
     *           "committer": "bruno",
     *           "end": 1399326911
     *         },
     *       "created": 1399325913,
     *       "deployDetails": [],
     *       "deployStatus": null,
     *       "description": "Adding .jar that should have been included in r110\n",
     *       "groups": [],
     *       "participants": {
     *         "bruno": []
     *       },
     *       "pending": false,
     *       "projects": [],
     *       "state": "approved",
     *       "stateLabel": "Approved",
     *       "testDetails": [],
     *       "testStatus": null,
     *       "type": "default",
     *       "updated": 1399325913,
     *       "versions": []
     *     },
     *       "transitions": {
     *         "needsReview": "Needs Review",
     *         "approved": "Approve",
     *         "rejected": "Reject",
     *         "archived": "Archive"
     *       }
     *   }
     *   ```
     *
     * @apiSuccessExample Successful Response contains Review Entity:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "review": {
     *         "id": 12207,
     *         "author": "bruno",
     *         "changes": [10667, 12000],
     *         "commits": [],
     *         "commitStatus": [],
     *         "created": 1399325913,
     *         "deployDetails": [],
     *         "deployStatus": null,
     *         "description": "Adding .jar that should have been included in r10145\n",
     *         "participants": {
     *           "bruno": []
     *         },
     *         "pending": false,
     *         "projects": [],
     *         "state": "needsRevision",
     *         "stateLabel": "Needs Revision",
     *         "testDetails": [],
     *         "testStatus": null,
     *         "type": "default",
     *         "updated": 1399325913
     *       },
     *       "transitions": {
     *         "needsReview": "Needs Review",
     *         "approved": "Approve",
     *         "rejected": "Reject",
     *         "archived": "Archive"
     *       }
     *     }
     *
     * @apiSuccessExample Successful Commit contains Review and Commit Entities:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "review": {
     *         "id": 12208,
     *         "author": "bruno",
     *         "changes": [10667, 12000, 12006],
     *         "commits": [12006],
     *         "commitStatus": {
     *           "start": 1399326910,
     *           "change": 12006,
     *           "status": "Committed",
     *           "committer": "bruno",
     *           "end": 1399326911
     *         },
     *         "created": 1399325900,
     *         "deployDetails": [],
     *         "deployStatus": null,
     *         "description": "Adding .jar that should have been included in r10145\n",
     *         "participants": {
     *           "bruno": []
     *         },
     *         "pending": false,
     *         "projects": [],
     *         "state": "needsRevision",
     *         "stateLabel": "Needs Revision",
     *         "testDetails": [],
     *         "testStatus": null,
     *         "type": "default",
     *         "updated": 1399325905
     *       },
     *       "transitions": {
     *         "needsReview": "Needs Review",
     *         "needsRevision": "Needs Revision",
     *         "rejected": "Reject",
     *         "archived": "Archive"
     *       },
     *       "commit": 12006
     *     }
     *
     * @return  JsonModel
     */
    public function stateAction()
    {
        $request  = $this->getRequest();
        $response = $this->getResponse();

        // this method is not inherently limited to patch, so we check it explicitly
        if (!$request->isPatch()) {
            $response->setStatusCode(405);
            return;
        }

        $review      = $this->getEvent()->getRouteMatch()->getParam('id');
        $data        = $this->processBodyContent($request);
        $state       = isset($data['state'])       ? $data['state']       : null;
        $commit      = isset($data['commit'])      ? $data['commit']      : false;
        $wait        = isset($data['wait'])        ? $data['wait']        : true;
        $description = isset($data['description']) ? $data['description'] : null;
        $jobs        = isset($data['jobs'])        ? $data['jobs']        : null;
        $fixStatus   = isset($data['fixStatus'])   ? $data['fixStatus']   : null;

        $request->setMethod(Request::METHOD_POST);
        $result = $this->forward(
            'Reviews\Controller\Index',
            'transition',
            array(
                'review'        => $review,
                'disableCommit' => !$commit,
            ),
            null,
            array(
                'wait'        => $wait,
                'state'       => $state . ($commit ? ':commit' : ''),
                'description' => $description,
                'jobs'        => $jobs,
                'fixStatus'   => $fixStatus,
            )
        );

        if (!$result->getVariable('isValid')) {
            // make sure the response indicates everything is not OK, without overwriting an existing error code
            if ($response->isOk()) {
                $response->setStatusCode(400);
            }

            return $this->prepareErrorModel($result);
        }

        return $this->prepareSuccessModel($result);
    }

    /**
     * @SWG\Api(
     *     path="dashboards/action",
     *     @SWG\Operation(
     *         method="GET",
     *         summary="Get reviews for action dashboard",
     *         notes="Gets reviews for the action dashboard for the authenticated user",
     *         nickname="dashboardAction"
     *     )
     * )
     *
     * @apiUsageExample Getting reviews for the action dashboard
     *
     *   To list reviews:
     *   ```bash
     *   curl -u "username:password" "http://my-swarm-host/api/v8/dashboards/action"
     *   ```
     *   Swarm responds with a list of the latest reviews, a `totalCount` field, and a `lastSeen` value for pagination:
     *
     *   ```json
     *   {
     *     "lastSeen": 120,
     *     "reviews": [
     *       {
     *         "id": 7,
     *         "author": "swarm_admin",
     *         "changes": [6],
     *         "comments": [0,0],
     *         "commits": [6],
     *         "commitStatus": [],
     *         "created": 1485793976,
     *         "deployDetails": [],
     *         "deployStatus": null,
     *         "description": "test\n",
     *         "groups": ["swarm-project-test"],
     *         "participants": {"swarm_admin":[]},
     *         "pending": false,
     *         "projects": {"test":["test"]},
     *         "roles": ["moderator|reviewer|required_reviewer|author"],
     *         "state": "needsReview",
     *         "stateLabel": "Needs Review",
     *         "testDetails": [],
     *         "testStatus": null,
     *         "type": "default",
     *         "updated": 1485958875,
     *         "updateDate": "2017-02-01T06:21:15-08:00"
     *       }
     *     ],
     *     "totalCount": null
     *   }
     *   ```
     * @apiSuccessExample Successful Commit contains Review and Commit Entities:
     *     HTTP/1.1 200 OK
     *
     *   {
     *     "lastSeen": 120,
     *     "reviews": [
     *       {
     *         "id": 7,
     *         "author": "swarm_admin",
     *         "changes": [6],
     *         "comments": [0,0],
     *         "commits": [6],
     *         "commitStatus": [],
     *         "created": 1485793976,
     *         "deployDetails": [],
     *         "deployStatus": null,
     *         "description": "test\n",
     *         "groups": ["swarm-project-test"],
     *         "participants": {"swarm_admin":[]},
     *         "pending": false,
     *         "projects": {"test":["test"]},
     *         "roles": ["moderator|reviewer|required_reviewer|author"],
     *         "state": "needsReview",
     *         "stateLabel": "Needs Review",
     *         "testDetails": [],
     *         "testStatus": null,
     *         "type": "default",
     *         "updated": 1485958875,
     *         "updateDate": "2017-02-01T06:21:15-08:00"
     *       }
     *     ],
     *     "totalCount": null
     *   }
     *
     * @return  JsonModel
     */
    public function dashboardAction()
    {
        $request = $this->getRequest();
        $fields  = $request->getQuery('fields');
        $version = $this->getEvent()->getRouteMatch()->getParam('version');

        $query = array(
                'after'       => $request->getQuery('after'),
                'disableHtml' => true,
                'max'         => $request->getQuery('max', 1000),
            );

        $result = $this->forward(
            'Reviews\Controller\Index',
            'dashboard',
            null,
            $query
        );

        return $this->prepareSuccessModel(
            array(
                'lastSeen'   => $result->getVariable('lastSeen'),
                'reviews'    => $result->getVariable('reviews'),
                'totalCount' => $result->getVariable('totalCount')
            ),
            $fields
        );
    }

    /**
     * @SWG\Api(
     *     path="reviews/",
     *     @SWG\Operation(
     *         method="GET",
     *         summary="Get List of Reviews",
     *         notes="List and optionally filter reviews.",
     *         nickname="getReviews",
     *         @SWG\Parameter(
     *             name="after",
     *             description="A review ID to seek to. Reviews up to and including the specified
     *                          `id` are excluded from the results and do not count towards `max`.
     *                          Useful for pagination. Commonly set to the `lastSeen` property from
     *                          a previous query.",
     *             paramType="query",
     *             type="integer"
     *         ),
     *         @SWG\Parameter(
     *             name="max",
     *             description="Maximum number of reviews to return. This does not guarantee that
     *                          `max` reviews are returned. It does guarantee that the number of
     *                          reviews returned won't exceed `max`. Server-side filtering may
     *                          exclude some reviews for permissions reasons.",
     *             paramType="query",
     *             type="integer",
     *             defaultValue="1000"
     *         ),
     *         @SWG\Parameter(
     *             name="fields",
     *             description="An optional comma-separated list (or array) of fields to show. Omitting this parameter
     *                          or passing an empty value shows all fields.",
     *             paramType="query",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="author[]",
     *             description="One or more authors to limit reviews by. Reviews with any of the specified authors
     *                          are returned. (v1.2+)",
     *             paramType="query",
     *             type="array",
     *             @SWG\Items("string")
     *         ),
     *         @SWG\Parameter(
     *             name="change[]",
     *             description="One or more change IDs to limit reviews by. Reviews associated with any of the
     *                          specified changes are returned.",
     *             paramType="query",
     *             type="array",
     *             @SWG\Items("integer")
     *         ),
     *         @SWG\Parameter(
     *             name="hasReviewers",
     *             description="Boolean option to limit to reviews to those with or without reviewers. Use
     *                          `true` or `false` for JSON-encoded data, `1` for true and or `0` for
     *                          false for form-encoded data. The presence of the parameter without a
     *                          value is evaluated as true.",
     *             paramType="query",
     *             type="boolean"
     *         ),
     *         @SWG\Parameter(
     *             name="ids[]",
     *             description="One or more review IDs to fetch. Only the specified reviews are returned. This
     *                          filter cannot be combined with the `max` parameter.",
     *             paramType="query",
     *             type="array",
     *             @SWG\Items("integer")
     *         ),
     *         @SWG\Parameter(
     *             name="keywords",
     *             description="Keywords to limit reviews by. Only reviews where the description, participants list
     *                          or project list contain the specified keywords are returned.",
     *             paramType="query",
     *             type="string"
     *         ),
     *         @SWG\Parameter(
     *             name="participants[]",
     *             description="One or more participants to limit reviews by. Reviews with any of the specified
     *                          participants are returned.",
     *             paramType="query",
     *             type="array",
     *             @SWG\Items("string")
     *         ),
     *         @SWG\Parameter(
     *             name="project[]",
     *             description="One or more projects to limit reviews by. Reviews affecting any of the specified
     *                          projects are returned.",
     *             paramType="query",
     *             type="array",
     *             @SWG\Items("string")
     *         ),
     *         @SWG\Parameter(
     *             name="state[]",
     *             description="One or more states to limit reviews by. Reviews in any of the specified states
     *                          are returned.",
     *             paramType="query",
     *             type="array",
     *             @SWG\Items("string")
     *         ),
     *         @SWG\Parameter(
     *             name="passesTests",
     *             description="Boolean option to limit reviews by tests passing or failing. Use
     *             `true` or `false` for JSON-encoded data, `1` for true and `0` for false for
     *             form-encoded data. The presence of the parameter without a value is evaluated as
     *             true.",
     *             paramType="query",
     *             type="string"
     *         ),
     *         @SWG\Parameter(
     *             name="notUpdatedSince",
     *             description="Option to fetch unchanged reviews. Requires the date to be in the format YYYY-mm-dd,
     *             for example 2017-01-01. Reviews to be returned are determined by looking at the last updated date
     *             of the review.",
     *             paramType="query",
     *             type="string"
     *         ),
     *         @SWG\Parameter(
     *             name="hasVoted",
     *             description="Should have the value 'up' or 'down' to filter reviews that have been voted
     *             up or down by the current authenticated user.",
     *             paramType="query",
     *             type="string"
     *         ),
     *         @SWG\Parameter(
     *             name="myComments",
     *             description="True or false to support filtering reviews that include comments by the
     *             current authenticated user.",
     *             paramType="query",
     *             type="boolean"
     *         )
     *     )
     * )
     *
     * @apiUsageExample Listing reviews
     *
     *   To list reviews:
     *
     *   ```bash
     *   curl -u "username:password" "https://my-swarm-host/api/v8/reviews?max=2&fields=id,description,author,state"
     *   ```
     *
     *   Swarm responds with a list of the latest reviews, a `totalCount` field, and a `lastSeen` value for pagination:
     *
     *   ```json
     *   {
     *     "lastSeen": 120,
     *     "reviews": [
     *       {
     *         "id": 123,
     *         "author": "bruno",
     *         "description": "Adding .jar that should have been included in r110\n",
     *         "state": "needsReview"
     *       },
     *       {
     *         "id": 120,
     *         "author": "bruno",
     *         "description": "Fixing a typo.\n",
     *         "state": "needsReview"
     *       }
     *     ],
     *     "totalCount": null
     *   }
     *   ```
     *
     *   The `totalCount` field is populated when keywords are supplied. It indicates how many total matches there are.
     *   If keywords are not supplied the `totalCount` field remains `null`, indicating that the list of all reviews is
     *   being queried.
     *
     *  @apiUsageExample Paginating a review listing
     *
     *   To obtain the next page of a reviews list (based on the previous example):
     *
     *   ```bash
     *   curl -u "username:password" "https://my-swarm-host/api/v8/reviews\
     *   ?max=2&fields=id,description,author,state&after=120"
     *   ```
     *
     *   Swarm responds with the second page of results, if any reviews are present after the last seen review:
     *
     *   ```json
     *   {
     *     "lastSeen": 100,
     *     "reviews": [
     *       {
     *         "id": 110,
     *         "author": "bruno",
     *         "description": "Updating Java files\n",
     *         "state": "needsReview"
     *       },
     *       {
     *         "id": 100,
     *         "author": "bruno",
     *         "description": "Marketing materials for our new cutting-edge product\n",
     *         "state": "needsReview"
     *       }
     *     ],
     *     "totalCount": null
     *   }
     *   ```
     *
     * @apiUsageExample Finding reviews for a change or a list of changes
     *
     *   Given a list of change IDs (5, 6, 7), here is how to check if any of them have reviews attached:
     *
     *   ```bash
     *   curl -u "username:password" "https://my-swarm-host/api/v8/reviews\
     *   ?max=2&fields=id,changes,description,author,state&change\[\]=5&change\[\]=6&change\[\]=7"
     *   ```
     *
     *   Swarm responds with a list of reviews that include these changes:
     *
     *   ```json
     *   {
     *     "lastSeen": 100,
     *     "reviews": [
     *       {
     *         "id": 110,
     *         "author": "bruno",
     *         "changes": [5],
     *         "description": "Updating Java files\n",
     *         "state": "needsReview"
     *       },
     *       {
     *         "id": 100,
     *         "author": "bruno",
     *         "changes": [6,7],
     *         "description": "Marketing materials for our new cutting-edge product\n",
     *         "state": "needsReview"
     *       }
     *     ],
     *     "totalCount": 2
     *   }
     *   ```
     *
     *   If no corresponding reviews are found, Swarm responds with an empty reviews list:
     *
     *   ```json
     *   {
     *     "lastSeen": null,
     *     "reviews": [],
     *     "totalCount": 0
     *   }
     *   ```
     *
     * @apiSuccessExample Successful Response:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "lastSeen": 12209,
     *       "reviews": [
     *         {
     *           "id": 12206,
     *           "author": "swarm",
     *           "changes": [12205],
     *           "comments": 0,
     *           "commits": [],
     *           "commitStatus": [],
     *           "created": 1402507043,
     *           "deployDetails": [],
     *           "deployStatus": null,
     *           "description": "Review Description\n",
     *           "participants": {
     *             "swarm": []
     *           },
     *           "pending": true,
     *           "projects": [],
     *           "state": "needsReview",
     *           "stateLabel": "Needs Review",
     *           "testDetails": [],
     *           "testStatus": null,
     *           "type": "default",
     *           "updated": 1402518492
     *         }
     *       ],
     *       "totalCount": 1
     *     }
     *
     *     Swarm returns `null` for `totalCount` if no search filters were provided.
     *
     *     `lastSeen` can often be used as an offset for pagination, by using the value
     *     in the `after` parameter of subsequent requests.
     *
     *   @apiUsageExample Finding inactive reviews (by checking the last updated date)
     *
     *   ```bash
     *   curl -u "username:password" "https://my-swarm-host/api/v8/reviews\
     *   ?max=2&fields=id,changes,description,author,state&notUpdatedSince=2017-01-01"
     *   ```
     *
     *   Swarm responds with a list of reviews that have not been updated since
     *   the notUpdatedSince date:
     *
     *   ```json
     *   {
     *     "lastSeen": 100,
     *     "reviews": [
     *       {
     *         "id": 110,
     *         "author": "bruno",
     *         "changes": [5],
     *         "description": "Updating Java files\n",
     *         "state": "needsReview"
     *       },
     *       {
     *         "id": 100,
     *         "author": "bruno",
     *         "changes": [6,7],
     *         "description": "Marketing materials for our new cutting-edge product\n",
     *         "state": "needsReview"
     *       }
     *     ],
     *     "totalCount": 2
     *   }
     *   ```
     *   @apiUsageExample Finding reviews I have voted up
     *
     *   ```bash
     *   curl -u "username:password" "https://my-swarm-host/api/v8/reviews\
     *   ?max=2&fields=id,changes,description,author,state&hasVoted=up"
     *   ```
     *
     *   Swarm responds with a list of reviews that include these changes:
     *
     *   ```json
     *   {
     *     "lastSeen": 100,
     *     "reviews": [
     *       {
     *         "id": 110,
     *         "author": "bruno",
     *         "changes": [5],
     *         "description": "Updating Java files\n",
     *         "state": "needsReview"
     *       },
     *       {
     *         "id": 100,
     *         "author": "bruno",
     *         "changes": [6,7],
     *         "description": "Marketing materials for our new cutting-edge product\n",
     *         "state": "needsReview"
     *       }
     *     ],
     *     "totalCount": 2
     *   }
     *   ```
     *
     *   If no corresponding reviews are found, Swarm responds with an empty reviews list:
     *
     *   ```json
     *   {
     *     "lastSeen": null,
     *     "reviews": [],
     *     "totalCount": 0
     *   }
     *   ```
     *   @apiUsageExample Finding reviews I have commented on (current authenticated user)
     *
     *   ```bash
     *   curl -u "username:password" "https://my-swarm-host/api/v8/reviews\
     *   ?max=2&fields=id,changes,description,author,state&myComments=true"
     *   ```
     *
     *   Swarm responds with a list of reviews that include these changes:
     *
     *   ```json
     *   {
     *     "lastSeen": 100,
     *     "reviews": [
     *       {
     *         "id": 110,
     *         "author": "bruno",
     *         "changes": [5],
     *         "description": "Updating Java files\n",
     *         "state": "needsReview"
     *       },
     *       {
     *         "id": 100,
     *         "author": "bruno",
     *         "changes": [6,7],
     *         "description": "Marketing materials for our new cutting-edge product\n",
     *         "state": "needsReview"
     *       }
     *     ],
     *     "totalCount": 2
     *   }
     *   ```
     *
     *   If no corresponding reviews are found, Swarm responds with an empty reviews list:
     *
     *   ```json
     *   {
     *     "lastSeen": null,
     *     "reviews": [],
     *     "totalCount": 0
     *   }
     *   ```
     *
     * @apiSuccessExample When no results are found, the `reviews` array is empty:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "lastSeen": null,
     *       "reviews": [],
     *       "totalCount": 0
     *     }
     *
     * @return  JsonModel
     */
    public function getList()
    {
        $request = $this->getRequest();
        $fields  = $request->getQuery('fields');
        $version = $this->getEvent()->getRouteMatch()->getParam('version');

        // explicitly control the query params we forward to the legacy endpoint
        // if new features get added, we don't want them to suddenly appear
        $filters = array(
            'change', 'hasReviewers', 'ids', 'keywords', 'participants', 'project', 'state', 'passesTests'
        );

        // add the author filtering feature for API versions v1.2+
        if (!in_array($version, array('v1', 'v1.1'))) {
            array_push($filters, 'author');
        }

        // add notUpdatedSince, hasVoted and myComments reviews filtering feature for API versions v6+
        if (!in_array($version, array('v1', 'v1.1', 'v1.2', 'v2', 'v3', 'v4', 'v5'))) {
            array_push(
                $filters,
                Review::FETCH_BY_NOT_UPDATED_SINCE,
                Review::FETCH_BY_HAS_VOTED,
                Review::FETCH_BY_MY_COMMENTS
            );
        }

        $query = array(
            'after'       => $request->getQuery('after'),
            'disableHtml' => true,
            'max'         => $request->getQuery('max', 1000),
        ) + array_intersect_key((array) $request->getQuery(), array_flip($filters));

        $result = $this->forward(
            'Reviews\Controller\Index',
            'index',
            null,
            $query
        );

        return $this->getResponse()->isOk()
            ? $this->prepareSuccessModel(
                array(
                    'lastSeen'   => $result->getVariable('lastSeen'),
                    'reviews'    => $result->getVariable('reviews'),
                    'totalCount' => $result->getVariable('totalCount')
                ),
                $fields
            )
            : $this->prepareErrorModel($result);
    }

    /**
     * Update review description
     *
     * @SWG\Api(
     *     path="reviews/{review_id}",
     *     @SWG\Operation(
     *         method="PATCH",
     *         summary="Update Review Description",
     *         notes="Update the description field of a review.",
     *         nickname="updateReview",
     *         @SWG\Parameter(
     *             name="review_id",
     *             description="Review ID",
     *             paramType="path",
     *             type="integer",
     *             required=true
     *         ),
     *         @SWG\Parameter(
     *             name="author",
     *             description="The new author for the specified review. (At least one of Author or Description are
     *                          required.)",
     *             paramType="form",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="description",
     *             description="The new description for the specified review. (At least one of Description or Author are
     *                          required.)",
     *             paramType="form",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="_method",
     *             description="Method Override. If your client cannot submit HTTP PATCH, use an HTTP POST with the
     *                          parameter ?_method=PATCH to override.",
     *             paramType="query",
     *             type="string",
     *             required=false
     *         )
     *     )
     * )
     *
     * @apiSuccessExample Successful Response:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "review": {
     *           "id": 12306,
     *           "author": "swarm",
     *           "changes": [12205],
     *           "comments": 0,
     *           "commits": [],
     *           "commitStatus": [],
     *           "created": 1402507043,
     *           "deployDetails": [],
     *           "deployStatus": null,
     *           "description": "Updated Review Description\n",
     *           "participants": {
     *             "swarm": []
     *           },
     *           "pending": true,
     *           "projects": [],
     *           "state": "needsReview",
     *           "stateLabel": "Needs Review",
     *           "testDetails": [],
     *           "testStatus": null,
     *           "type": "default",
     *           "updated": 1402518492
     *       },
     *       "transitions": {
     *           "needsRevision": "Needs Revision",
     *           "approved": "Approve",
     *           "rejected": "Reject",
     *           "archived": "Archive"
     *       },
     *       "canEditAuthor": true
     *     }
     *
     *     Swarm returns `null` for `totalCount` if no search filters were provided.
     *
     *     `lastSeen` can often be used as an offset for pagination, by using the value
     *     in the `after` parameter of subsequent requests.
     *
     * @apiSuccessExample When no results are found, the `reviews` array is empty:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "lastSeen": null,
     *       "reviews": [],
     *       "totalCount": 0
     *     }
     *
     * @param mixed $id     review to edit
     * @param mixed $data   changes to apply
     * @return JsonModel
     */
    public function patch($id, $data)
    {
        $request = $this->getRequest();
        $data    = array_merge($data, $request->getPost()->toArray());
        $version = $this->getEvent()->getRouteMatch()->getParam('version');

        // this endpoint is only supported in API v5 and up
        if (in_array($version, array('v1', 'v1.1', 'v1.2', 'v2', 'v3', 'v4'))) {
            $this->response->setStatusCode(405);

            return $this->prepareErrorModel(
                new JsonModel(
                    array(
                        'error' => 'Method Not Allowed'
                    )
                )
            );
        }
        // REVIEWER_GROUPS are only supported in API v7 and up
        if (isset($data[Review::REVIEWER_GROUPS])) {
            if (in_array($version, array('v1', 'v1.1', 'v1.2', 'v2', 'v3', 'v4', 'v5', 'v6'))) {
                $this->response->setStatusCode(405);
                return $this->prepareErrorModel(
                    new JsonModel(
                        array(
                            'error' => Review::REVIEWER_GROUPS . ' parameter is only supported for v7+ of the API'
                        )
                    )
                );
            } else {
                $this->translateReviewerGroups($data);
            }
        }

        $result = $this->forward(
            'Reviews\Controller\Index',
            'review',
            array('review' => $id),
            null,
            $data
        );

        if (!isset($result->isValid) || !$result->isValid) {
            $response = $this->getResponse();
            // make sure the response indicates everything is not OK, without overwriting an existing error code
            if ($response->isOk()) {
                $response->setStatusCode(400);
            }

            return $this->prepareErrorModel($result);
        }

        return $this->prepareSuccessModel($result);
    }

    /**
     * Extends parent to provide special preparation of review data
     *
     * @param   JsonModel|array     $model              A model to adjust prior to rendering
     * @param   string|array        $limitEntityFields  Optional comma-separated string (or array) of fields
     *                                                  When provided, limits review/reviews to specified fields.
     * @return  JsonModel           The adjusted model
     */
    public function prepareSuccessModel($model, $limitEntityFields = null)
    {
        $model = parent::prepareSuccessModel($model);

        // some legacy endpoints include fields we don't want
        unset(
            $model->id,
            $model->messages,
            $model->avatars,
            $model->description,
            $model->canEditReviewers,
            $model->authorAvatar
        );

        // make adjustments to 'review' entity if present
        if ($model->getVariable('review')) {
            $model->setVariable('review', $this->normalizeReview($model->getVariable('review'), $limitEntityFields));
        }

        // if a list of reviews is present, normalize each one
        $reviews = $model->getVariable('reviews');
        if ($reviews) {
            foreach ($reviews as $key => $review) {
                $reviews[$key] = $this->normalizeReview($review, $limitEntityFields);
            }

            $model->setVariable('reviews', $reviews);
        }

        // API does not allow the 'approved:commit' transition (use commit param instead)
        $transitions = $model->getVariable('transitions');
        if ($transitions) {
            unset($transitions[Review::STATE_APPROVED . ':commit']);
            $model->setVariable('transitions', $transitions);
        }

        return $model;
    }

    protected function normalizeReview($review, $limitEntityFields = null)
    {
        // clobber redundant 'participants' field with more informative 'participantsData'
        if (isset($review['participants'], $review['participantsData'])) {
            $review['participants'] = $review['participantsData'];
            unset($review['participantsData']);
        }
        $version = $this->getEvent()->getRouteMatch()->getParam('version');
        $v7plus  = !in_array($version, array('v1', 'v1.1', 'v1.2', 'v2', 'v3', 'v4', 'v5', 'v6'));
        // Return any participant groups as part of Review::FIELD_PARTICIPANTS_GROUPS with the name
        // that would be returned by 'p4 groups' (not with the 'swarm-group-' prefix) only do this for v7
        // onwards
        if ($v7plus) {
            $review[Review::REVIEWER_GROUPS] = array();
        }
        if (isset($review[Review::FIELD_PARTICIPANTS])) {
            $participants = $review[Review::FIELD_PARTICIPANTS];
            foreach ($participants as $key => $value) {
                $stripped = Group::getGroupName($key);

                if ($stripped !== $key) {
                    // Populate reviewerGroups if v7+ otherwise strip the groups from participants
                    if ($v7plus) {
                        // It starts with 'swarm-group-' remove it from participants and add it to
                        // participant groups
                        $review[Review::REVIEWER_GROUPS] =
                            array_merge($review[Review::REVIEWER_GROUPS], array($stripped => $value));
                        if (isset($review[Review::REVIEWER_GROUPS][$stripped]['required'])) {
                            $requiredField = $review[Review::REVIEWER_GROUPS][$stripped]['required'];

                            // If required is a number translate it to true and set a quorum field with the number
                            if (!in_array($requiredField, array(true, "true", false, "false"), true)) {
                                $review[Review::REVIEWER_GROUPS][$stripped]['required']     = true;
                                $review[Review::REVIEWER_GROUPS][$stripped][Review::QUORUM] = $requiredField;
                            }
                        }
                    }
                    unset($review[Review::FIELD_PARTICIPANTS][$key]);
                }
            }
        }

        // several fields returned by the legacy endpoints are inconsistent/inappropriate for the api
        unset(
            $review['authorAvatar'],
            $review['createDate'],
            $review['downVotes'],
            $review['hasReviewer'],
            $review['upVotes']
        );

        // limit and re-order fields for aesthetics/consistency
        $review = $this->limitEntityFields($review, $limitEntityFields);
        $review = $this->sortEntityFields($review);

        return $review;
    }

    /**
     * Translate 'reviewerGroups' parameter to values in 'reviewers', 'requiredReviewers' and 'reviewerQuorums'.
     * @param $post post params
     */
    private function translateReviewerGroups(&$post)
    {
        if (isset($post[Review::REVIEWER_GROUPS])) {
            foreach ($post[Review::REVIEWER_GROUPS] as $key => $values) {
                if ($values && !empty($values)) {
                    $field     = Review::REVIEWERS;
                    $groupData = GroupConfig::KEY_PREFIX . $values['name'];
                    if (isset($values['required'])) {
                        if (isset($values[Review::QUORUM])) {
                            $field     = Review::REVIEWER_QUORUMS;
                            $groupData = array($groupData => $values[Review::QUORUM]);
                            if (!isset($post[$field])) {
                                $post[$field] = $groupData;
                            } else {
                                array_push($post[$field], $groupData);
                            }
                            // Quorum reviewers must also be in required
                            if (!isset($post[Review::REQUIRED_REVIEWERS])) {
                                $post[Review::REQUIRED_REVIEWERS] = array();
                            }
                            array_push(
                                $post[Review::REQUIRED_REVIEWERS],
                                GroupConfig::KEY_PREFIX . $values['name']
                            );
                            continue;
                        }
                        $field = Review::REQUIRED_REVIEWERS;
                    }
                    if (!isset($post[$field])) {
                        $post[$field] = array();
                    }
                    array_push($post[$field], $groupData);
                }
            }
            unset($post[Review::REVIEWER_GROUPS]);
        }
    }

    /**
     * @SWG\Api(
     *     path="reviews/archive/",
     *     @SWG\Operation(
     *         method="POST",
     *         summary="Archiving the inactive reviews (v6+)",
     *         notes="Archiving reviews not updated since the date (v6+)",
     *         nickname="archive",
     *         @SWG\Parameter(
     *             name="notUpdatedSince",
     *             description="Updated since date. Requires the date to be in the format YYYY-mm-dd, for example
     *             2017-01-01",
     *             paramType="form",
     *             type="string",
     *             required=true
     *         ),
     *         @SWG\Parameter(
     *             name="description",
     *             description="A description that is posted as a comment for archiving.",
     *             paramType="form",
     *             type="string",
     *             required=true
     *         ),
     *         @SWG\ResponseMessage(code=400, message="Bad Request"),
     *         @SWG\ResponseMessage(code=405, message="Method Not Allowed"),
     *         @SWG\ResponseMessage(code=401, message="Unauthorized")
     *     )
     * )
     *
     * @apiUsageExample Archiving reviews inactive since 2016/06/30
     *
     *   To archive reviews not updated since 2016/06/30 inclusive:
     *
     *   ```bash
     *   curl -u "username:password" -d "notUpdatedSince=2016-06-30" \
     *        "https://my-swarm-host/api/v8/reviews/archive/"
     *   ```
     *
     *   Swarm responds with the list of archived reviews and failed reviews if there are any:
     *
     *   ```json
     *   {
     *     "archivedReviews":[
     *       {
     *         "id": 911,
     *         "author": "swarm",
     *         "changes": [601],
     *         "commits": [],
     *         "commitStatus": [],
     *         "created": 1461164344,
     *         "deployDetails": [],
     *         "deployStatus": null,
     *         "description": "Touch up references on html pages.\n",
     *         "groups": [],
     *         "participants": {
     *           "swarm":[]
     *         },
     *         "pending": false,
     *         "projects": [],
     *         "state": "archived",
     *         "stateLabel": "Archived",
     *         "testDetails": [],
     *         "testStatus": null,
     *         "type": "default",
     *         "updated": 1478191605
     *       },
     *       {
     *         "id": 908,
     *         "author": "earl",
     *         "changes": [605],
     *         "commits": [],
     *         "commitStatus": [],
     *         "created": 1461947794,
     *         "deployDetails": [],
     *         "deployStatus": null,
     *         "description": "Remove (attempted) installation of now deleted man pages.\n",
     *         "groups": [],
     *         "participants": {
     *           "swarm": []
     *         },
     *         "pending": false,
     *         "projects": [],
     *         "state": "archived",
     *         "stateLabel": "Archived",
     *         "testDetails": [],
     *         "testStatus": null,
     *         "type": "default",
     *         "updated": 1478191605
     *       }
     *     ],
     *     "failedReviews":[
     *       {
     *       }
     *     ]
     *   }
     *   ```
     *
     *   If no reviews are archived, Swarm responds with an empty reviews list:
     *
     *   ```json
     *   {
     *     "archivedReviews": [],
     *     "failedReviews": []
     *   }
     *   ```
     *
     * @apiSuccessExample Successful Response:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "archivedReviews": [
     *         {
     *           "id": 836,
     *           "author": "swarm",
     *           "changes": [789],
     *           "commits": [],
     *           "commitStatus": [],
     *           "created": 1461164339,
     *           "deployDetails": [],
     *           "deployStatus": null,
     *           "description": "Review description\n",
     *           "groups": [],
     *           "participants": {
     *             "swarm": []
     *           },
     *           "pending": false,
     *           "projects": [],
     *           "state": "archived",
     *           "stateLabel": "Archived",
     *           "testDetails": [],
     *           "testStatus": null,
     *           "type": "default",
     *           "updated": 1478191607
     *         }
     *       ],
     *       "failedReviews": []
     *     }
     *
     * @return  JsonModel
     */
    public function archiveInactiveAction()
    {
        $request     = $this->getRequest();
        $response    = $this->getResponse();
        $version     = $this->getEvent()->getRouteMatch()->getParam('version');
        $description = $request->getPost('description');
        // this method is not inherently limited to post, so we check it explicitly
        if (!$request->isPost()) {
            $response->setStatusCode(405);
            return;
        }
        $query = array(
            Review::FETCH_BY_NOT_UPDATED_SINCE => $request->getPost(Review::FETCH_BY_NOT_UPDATED_SINCE),
            'disableHtml' => true,
        );

        $result = $this->forward(
            'Reviews\Controller\Index',
            'archiveIndex',
            null,
            $query
        );

        $valid = $result->getVariable('isValid');
        if ($valid === false) {
            $response = $this->getResponse();
            // make sure the response indicates everything is not OK, without overwriting an existing error code
            if ($response->isOk()) {
                $response->setStatusCode(400);
            }

            return $this->prepareErrorModel($result);
        }

        $reviews         = $result->getVariable('reviews');
        $archivedReviews = array();
        $failedReviews   = array();
        foreach ($reviews as $review) {
            if (!($review[Review::FETCH_BY_STATE] == Review::STATE_ARCHIVED)) {
                $transitionResult = $this->forward(
                    'Reviews\Controller\Index',
                    'transition',
                    array(
                        'review' => $review[Review::FIELD_ID],
                    ),
                    null,
                    array(
                        'state' => Review::STATE_ARCHIVED,
                        'description' => $description,
                    )
                );

                $valid = $transitionResult->getVariable('isValid');
                if ($valid === false) {
                    $transitionResult->setVariable('error', 'Failed to archive');
                    $model           = $this->prepareErrorModel($transitionResult);
                    $reviewArray     = $transitionResult->getVariable('review');
                    $failedReviews[] = array(
                        'error'  => $model->getVariable('details'),
                        'review' => $reviewArray['id']
                    );
                } else {
                    $archivedReviews[] = $this->prepareSuccessModel($transitionResult)->getVariable('review');
                }
            }
        }
        return new JsonModel(
            array(
                'archivedReviews' => $archivedReviews,
                'failedReviews'   => $failedReviews
            )
        );
    }

    /**
     * @SWG\Api(
     *     path="reviews/{id}/cleanup",
     *     @SWG\Operation(
     *         method="POST",
     *         summary="Clean up a review (v6+)",
     *         notes="Clean up a review for the given id.",
     *         nickname="cleanup",
     *         @SWG\Parameter(
     *             name="reopen",
     *             description="Expected to be a boolean (defaulting to false). If true then an attempt will
     *             be made to reopen files into a default changelist",
     *             paramType="form",
     *             type="boolean",
     *             required=false
     *         ),
     *         @SWG\ResponseMessage(code=400, message="Bad Request"),
     *         @SWG\ResponseMessage(code=405, message="Method Not Allowed"),
     *         @SWG\ResponseMessage(code=401, message="Unauthorized")
     *     )
     * )
     *
     * @apiUsageExample Cleaning up a review with id 1.
     *
     *   Cleanup review number 1, reopening any files into the default changelist.
     *
     *   ```bash
     *   curl -u "username:password" -d "reopen=true" \
     *        "https://my-swarm-host/api/v8/reviews/1/cleanup"
     *   ```
     *
     *   Swarm responds with the review and the changelists cleaned. Depending on the completion they
     *   will be either detailed in 'complete' or 'incomplete'. Incomplete changelists will have messages
     *   indicating why it was not possible to complete:
     *
     *   ```json
     *     {
     *       "complete": [
     *         {
     *           "1": ["2"]
     *         }
     *       ],
     *       "incomplete": []
     *     }
     *   ```
     *
     * @apiSuccessExample Successful Response:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "complete": [
     *         {
     *           "1": ["2"]
     *         }
     *       ],
     *       "incomplete": []
     *     }
     *
     * @return  JsonModel
     */
    public function cleanupAction()
    {
        $boolean  = new FormBoolean;
        $request  = $this->getRequest();
        $response = $this->getResponse();
        // this method is not inherently limited to post, so we check it explicitly
        if (!$request->isPost()) {
            $response->setStatusCode(405);
            return;
        }
        $reopen   = $boolean->filter($request->getPost('reopen'));
        $services = $this->getServiceLocator();
        $p4User   = $services->get('p4_user');
        $reviewID = $this->getEvent()->getRouteMatch()->getParam('id');
        $config   = $services->get('config');
        $logger   = $services->get('logger');

        $returnData = null;

        $incomplete = array();
        if ($p4User->isSuperUser()) {
            if (Review::exists($reviewID, $p4User)) {
                $review = Review::fetch($reviewID, $p4User);
                switch ($review->getState()) {
                    case Review::STATE_APPROVED:
                    case Review::STATE_ARCHIVED:
                        $logger->notice("Cleaning up the pending changelists from API.");
                        $returnData = $review->cleanup(array('reopen' => $reopen), $p4User);
                        break;

                    default:
                        $incomplete[$reviewID][] = 'Review is in state (' . $review->getState() .
                            '). Only approved and archived reviews can be cleaned up.';
                        break;
                }
            } else {
                $incomplete[$reviewID][] = 'Review (' . $reviewID . ') not found';
            }
            $response->setStatusCode(200);
        } else {
            $response->setStatusCode(401);
            $incomplete[$reviewID][] = 'You must be a super user to run this operation';
        }
        // Return Json data.
        return new JsonModel(
            $returnData === null ? array('complete' => array(), 'incomplete' => $incomplete) : $returnData
        );
    }
}
