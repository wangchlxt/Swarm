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
use Application\Response\CallbackResponse;
use P4\Spec\Change;
use Reviews\Model\Review;
use Zend\Http\Request;
use Zend\View\Model\JsonModel;

/**
 * Swarm Comments
 *
 * @SWG\Resource(
 *   apiVersion="v8",
 *   basePath="/api/v8/"
 * )
 */
class CommentsController extends AbstractApiController
{
    /**
     * @SWG\Api(
     *     path="comments/",
     *     @SWG\Operation(
     *         method="GET",
     *         summary="Get List of Comments",
     *         notes="List comments.",
     *         nickname="getComments",
     *         @SWG\Parameter(
     *             name="after",
     *             description="A comment ID to seek to. Comments up to and including the specified
     *                          ID are excluded from the results and do not count towards `max`.
     *                          Useful for pagination. Commonly set to the `lastSeen` property from
     *                          a previous query.",
     *             paramType="query",
     *             type="integer",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="max",
     *             description="Maximum number of comments to return. This does not guarantee that
     *                          `max` comments are returned. It does guarantee that the number of
     *                          comments returned won't exceed `max`.",
     *             paramType="query",
     *             type="integer",
     *             defaultValue="100",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="topic",
     *             description="Only comments for given topic are returned.
     *                          Examples: `reviews/1234`, `changes/1234` or `jobs/job001234`.",
     *             paramType="query",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="context[version]",
     *             description="If a `reviews/1234` topic is provided, limit returned comments to a specific version of
     *                          the provided review.",
     *             paramType="query",
     *             type="integer",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="ignoreArchived",
     *             description="Prevents archived comments from being returned. (v5+)",
     *             paramType="query",
     *             type="boolean",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="tasksOnly",
     *             description="Returns only comments that have been flagged as tasks. (v5+)",
     *             paramType="query",
     *             type="boolean",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="taskStates",
     *             description="Limit the returned comments to ones that match the provided task state (one or more of
     *                          `open`, `closed`, `verified`, or `comment`). (v5+)",
     *             paramType="query",
     *             type="array",
     *             required=false,
     *             @SWG\Items("string")
     *         ),
     *         @SWG\Parameter(
     *             name="fields",
     *             description="An optional comma-separated list (or array) of fields to show for each comment.
     *                          Omitting this parameter or passing an empty value shows all fields.",
     *             paramType="query",
     *             type="string",
     *             required=false
     *         )
     *     )
     * )
     *
     *
     * @apiUsageExample Listing comments
     *
     *   To list comments:
     *
     *   ```bash
     *   curl -u "username:password" "https://my-swarm-host/api/v8/comments\
     *   ?topic=reviews/911&max=2&fields=id,body,time,user"
     *   ```
     *
     *   Swarm responds with a list of the first two comments for review 911 and a `lastSeen` value for pagination:
     *
     *   ```json
     *   {
     *     "topic": "reviews/911",
     *     "comments": {
     *       "35": {
     *         "id": 35,
     *         "body": "Excitation thunder cats intelligent man braid organic bitters.",
     *         "time": 1461164347,
     *         "user": "bruno"
     *       },
     *       "39": {
     *         "id": 39,
     *         "body": "Chamber tote bag butcher, shirk truffle mode shabby chic single-origin coffee.",
     *         "time": 1461164347,
     *         "user": "swarm_user"
     *       }
     *     },
     *     "lastSeen": 39
     *   }
     *   ```
     *
     * @apiUsageExample Paginating a comment listing
     *
     *   To obtain the next page of a comments list (based on the previous example):
     *
     *   ```bash
     *   curl -u "username:password" "https://my-swarm-host/api/v8/comments\
     *   ?topic=reviews/911&max=2&fields=id,body,time,user&after=39"
     *   ```
     *
     *   Swarm responds with the second page of results, if any comments are present after the last seen comment:
     *
     *   ```json
     *   {
     *     "topic": "reviews/911",
     *     "comments": {
     *       "260": {
     *         "id": 260,
     *         "body": "Reprehensible do lore flank ham hock.",
     *         "time": 1461164349,
     *         "user": "bruno"
     *       },
     *       "324": {
     *         "id": 324,
     *         "body": "Sinter lo-fi temporary, nihilist tote bag mustache swag consequence interest flexible.",
     *         "time": 1461164349,
     *         "user": "bruno"
     *       }
     *     },
     *     "lastSeen": 324
     *   }
     *   ```
     *
     * @apiSuccessExample Successful Response:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "topic": "",
     *       "comments": {
     *         "51": {
     *           "id": 51,
     *           "attachments": [],
     *           "body": "Short loin ground round sin reprehensible, venison west participle triple.",
     *           "context": [],
     *           "edited": null,
     *           "flags": [],
     *           "likes": [],
     *           "taskState": "comment",
     *           "time": 1461164347,
     *           "topic": "reviews/885",
     *           "updated": 1461164347,
     *           "user": "bruno"
     *         }
     *       },
     *       "lastSeen": 51
     *     }
     *
     *     `lastSeen` can often be used as an offset for pagination, by using the value
     *     in the `after` parameter of subsequent requests.
     *
     * @apiSuccessExample When no results are found, the `comments` array is empty:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "topic": "jobs/job000011",
     *       "comments": [],
     *       "lastSeen": null
     *     }
     *
     * @return  JsonModel
     */
    public function getList()
    {
        $request = $this->getRequest();
        $topic   = $request->getQuery('topic');
        $fields  = $request->getQuery('fields');
        $context = $request->getQuery('context');
        $query   = array(
            'after'          => $request->getQuery('after'),
            'max'            => $request->getQuery('max', 100),
            'limitVersion'   => isset($context['version']),
            'context'        => isset($context['version']) ? array('version' => $context['version']) : null,
            'ignoreArchived' => $request->getQuery('ignoreArchived'),
            'tasksOnly'      => $request->getQuery('tasksOnly'),
            'taskStates'     => $request->getQuery('taskStates'),
        );

        try {
            $result = $this->forward('Comments\Controller\Index', 'index', array('topic' => $topic), $query);
        } catch (\Exception $e) {
            $this->getResponse()->setStatusCode(200);
            $result = array(
                'comments' => array(),
                'lastSeen' => null,
            );
        }

        return $this->getResponse()->isOk()
            ? $this->prepareSuccessModel($result, $fields)
            : $this->prepareErrorModel($result);
    }

    /**
     * @SWG\Api(
     *     path="comments/",
     *     @SWG\Operation(
     *         method="POST",
     *         summary="Add A Comment",
     *         notes="Add a comment to a topic (such as a review or a job)",
     *         nickname="addComment",
     *         @SWG\Parameter(
     *             name="topic",
     *             description="Topic to comment on.
     *                          Examples: `reviews/1234`, `changes/1234` or `jobs/job001234`.",
     *             paramType="form",
     *             type="string",
     *             required=true
     *         ),
     *         @SWG\Parameter(
     *             name="body",
     *             description="Content of the comment.",
     *             paramType="form",
     *             type="string",
     *             required=true
     *         ),
     *         @SWG\Parameter(
     *             name="taskState",
     *             description="Optional task state of the comment. Valid values when adding a comment are `comment`
     *                          and `open`. This creates a plain comment or opens a task, respectively.",
     *             paramType="form",
     *             type="string",
     *             required=false,
     *             defaultValue="comment"
     *         ),
     *         @SWG\Parameter(
     *             name="flags[]",
     *             description="Optional flags on the comment. Typically set to `closed` to archive a comment.",
     *             paramType="form",
     *             type="array",
     *             required=false,
     *             @SWG\Items("string")
     *         ),
     *         @SWG\Parameter(
     *             name="context[file]",
     *             description="File to comment on. Valid only for `changes` and `reviews` topics.
     *                          Example: `//depot/main/README.txt`.",
     *             paramType="form",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="context[leftLine]",
     *             description="Left-side diff line to attach the inline comment to.  Valid only for `changes` and
     *                          `reviews` topics. If this is specified, `context[file]` must also be specified.",
     *             paramType="form",
     *             type="integer",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="context[rightLine]",
     *             description="Right-side diff line to attach the inline comment to.  Valid only for `changes` and
     *                          `reviews` topics. If this is specified, `context[file]` must also be specified.",
     *             paramType="form",
     *             type="integer",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="context[content]",
     *             description="Optionally provide content of the specified line and its four preceding lines. This
     *                          is used to specify a short excerpt of context in case the lines being commented
     *                          on change during the review.
     *
     *                          When not provided, Swarm makes an effort to build the content on its own - as this
     *                          involves file operations, it could become slow.",
     *             paramType="form",
     *             type="array",
     *             required=false,
     *             @SWG\Items("string")
     *         ),
     *         @SWG\Parameter(
     *             name="context[version]",
     *             description="With a `reviews` topic, this field specifies which version to attach the comment to.",
     *             paramType="form",
     *             type="integer",
     *             required=false
     *         )
     *     )
     * )
     *
     * @apiUsageExample Create a comment on a review
     *
     *   To create a comment on a review:
     *
     *   ```bash
     *   curl -u "username:password" \
     *        -d "topic=reviews/2" \
     *        -d "body=This is my comment. It is an excellent comment. It contains a beginning, a middle, and an end." \
     *        "https://my-swarm-host/api/v8/comments"
     *   ```
     *
     *   JSON Response:
     *
     *   ```json
     *   {
     *     "comment": {
     *       "id": 42,
     *       "attachments": [],
     *       "body": "This is my comment. It is an excellent comment. It contains a beginning, a middle, and an end.",
     *       "context": [],
     *       "edited": null,
     *       "flags": [],
     *       "likes": [],
     *       "taskState": "comment",
     *       "time": 123456789,
     *       "topic": "reviews/2",
     *       "updated": 123456790,
     *       "user": "username"
     *     }
     *   }
     *   ```
     *
     * @apiUsageExample Open a task on a review
     *
     *   To create a comment on a review, and flag it as an open task:
     *
     *   ```bash
     *   curl -u "username:password" \
     *        -d "topic=reviews/2" \
     *        -d "taskState=open" \
     *        -d "body=If you could go ahead and attach a cover page to your TPS report, that would be great." \
     *        "https://my-swarm-host/api/v8/comments"
     *   ```
     *
     *   JSON Response:
     *
     *   ```json
     *   {
     *     "comment": {
     *       "id": 43,
     *       "attachments": [],
     *       "body": "If you could go ahead and attach a cover page to your TPS report, that would be great.",
     *       "context": [],
     *       "edited": null,
     *       "flags": [],
     *       "likes": [],
     *       "taskState": "open",
     *       "time": 123456789,
     *       "topic": "reviews/2",
     *       "updated": 123456790,
     *       "user": "username"
     *     }
     *   }
     *   ```
     *
     * @apiSuccessExample Successful Response contains Comment entity:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "comment": {
     *         "id": 42,
     *         "attachments": [],
     *         "body": "Best. Comment. EVER!",
     *         "context": [],
     *         "edited": null,
     *         "flags": [],
     *         "likes": [],
     *         "taskState": "comment",
     *         "time": 123456789,
     *         "topic": "reviews/2",
     *         "updated": 123456790,
     *         "user": "bruno"
     *       }
     *     }
     *
     * @param   mixed   $data
     * @return  JsonModel
     */
    public function create($data)
    {
        $defaults = array('topic' => '', 'body' => '', 'context' => '', 'taskState' => 'comment', 'flags' => array());

        try {
            $data = $this->filterCommentContext($data) + $defaults;
        } catch (\Exception $e) {
            $this->getResponse()->setStatusCode(400);

            return new JsonModel(
                array(
                    'error'   => 'Provided context could not be filtered.',
                    'details' => array('context' => $e->getMessage())
                )
            );
        }

        // explicitly control the query params we forward to the legacy endpoint
        // if new features get added, we don't want them to suddenly appear
        $services = $this->getServiceLocator();
        $query    = array(
            'bundleTopicComments' => false,
            'user'                => $services->get('user')->getId(),
        ) + array_intersect_key($data, $defaults);

        $result = $this->forward(
            'Comments\Controller\Index',
            'add',
            null,
            null,
            $query
        );

        if (!$result->getVariable('isValid')) {
            $this->getResponse()->setStatusCode(400);
            return $this->prepareErrorModel($result);
        }

        return $this->prepareSuccessModel($result);
    }

    /**
     * @SWG\Api(
     *     path="comments/{id}",
     *     @SWG\Operation(
     *         method="PATCH",
     *         summary="Edit A Comment",
     *         notes="Edit a comment",
     *         nickname="editComment",
     *         @SWG\Parameter(
     *             name="id",
     *             description="ID of the comment to be edited",
     *             paramType="path",
     *             type="integer",
     *             required=true
     *         ),
     *         @SWG\Parameter(
     *             name="topic",
     *             description="Topic to comment on.
     *                          Examples: `reviews/1234`, `changes/1234` or `jobs/job001234`.",
     *             paramType="form",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="body",
     *             description="Content of the comment.",
     *             paramType="form",
     *             type="string",
     *             required=true
     *         ),
     *         @SWG\Parameter(
     *             name="taskState",
     *             description="Optional task state of the comment. Note that certain transitions (such as moving from
     *                          `open` to `verified`) are not possible without an intermediate step (`addressed`, in
     *                          this case).
     *                          Examples: `comment` (not a task), `open`, `addressed`, `verified`.",
     *             paramType="form",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="flags[]",
     *             description="Optional flags on the comment. Typically set to `closed` to archive a comment.",
     *             paramType="form",
     *             type="array",
     *             required=false,
     *             @SWG\Items("string")
     *         )
     *     )
     * )
     *
     * @apiUsageExample Edit and archive a comment on a review
     *
     *   To edit and archive a comment on a review:
     *
     *   ```bash
     *   curl -u "username:password" \
     *        -X PATCH \
     *        -d "flags[]=closed" \
     *        -d "body=This comment wasn't as excellent as I may have lead you to believe. A thousand apologies." \
     *        "https://my-swarm-host/api/v8/comments/42"
     *   ```
     *
     *   JSON Response:
     *
     *   ```json
     *   {
     *     "comment": {
     *       "id": 42,
     *       "attachments": [],
     *       "body": "This comment wasn't as excellent as I may have lead you to believe. A thousand apologies.",
     *       "context": [],
     *       "edited": 123466790,
     *       "flags": ["closed"],
     *       "likes": [],
     *       "taskState": "comment",
     *       "time": 123456789,
     *       "topic": "reviews/2",
     *       "updated": 123456790,
     *       "user": "username"
     *     }
     *   }
     *   ```
     *
     * @apiUsageExample Flag a task as addressed on a review
     *
     *   To flag an open task as addressed on a review:
     *
     *   ```bash
     *   curl -u "username:password" \
     *        -X PATCH \
     *        -d "taskState=addressed" \
     *        "https://my-swarm-host/api/v8/comments/43"
     *   ```
     *
     *   JSON Response:
     *
     *   ```json
     *   {
     *     "comment": {
     *       "id": 43,
     *       "attachments": [],
     *       "body": "If you could go ahead and attach a cover page to your TPS report, that would be great.",
     *       "context": [],
     *       "edited": 123466790,
     *       "flags": ["closed"],
     *       "likes": [],
     *       "taskState": "comment",
     *       "time": 123456789,
     *       "topic": "reviews/2",
     *       "updated": 123456790,
     *       "user": "username"
     *     }
     *   }
     *   ```
     *
     * @apiSuccessExample Successful Response contains Comment entity:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "comment": {
     *         "id": 1,
     *         "attachments": [],
     *         "body": "Best. Comment. EVER!",
     *         "context": [],
     *         "edited": 123466790,
     *         "flags": [],
     *         "likes": [],
     *         "taskState": "comment",
     *         "time": 123456789,
     *         "topic": "reviews/42",
     *         "updated": 123456790,
     *         "user": "bruno"
     *       }
     *     }
     *
     * @param   int     $id
     * @param   mixed   $data
     * @return  JsonModel
     */
    public function patch($id, $data)
    {
        $this->getRequest()->setMethod(Request::METHOD_POST);

        // explicitly control the query params we forward to the legacy endpoint
        // if new features get added, we don't want them to suddenly appear
        $services = $this->getServiceLocator();
        $query    = array(
                'bundleTopicComments' => false,
                'user'                => $services->get('user')->getId(),
            ) + array_intersect_key($data, array_flip(array('topic', 'body', 'taskState', 'flags')));
        $result   = $this->forward(
            'Comments\Controller\Index',
            'edit',
            array('comment' => $id),
            null,
            $query
        );

        if (!$result->getVariable('isValid')) {
            $this->getResponse()->setStatusCode(400);
            return $this->prepareErrorModel($result);
        }

        return $this->prepareSuccessModel($result);
    }

    /**
     * Extends parent to provide special preparation of comment data
     *
     * @param   JsonModel|array     $model              A model to adjust prior to rendering
     * @param   string|array        $limitEntityFields  Optional comma-separated string (or array) of fields
     *                                                  When provided, limits entity output to specified fields.
     * @return  JsonModel           The adjusted model
     */
    public function prepareSuccessModel($model, $limitEntityFields = null)
    {
        $model = parent::prepareSuccessModel($model);

        // clean up model to minimize superfluous data
        unset($model->messages);
        unset($model->taskTransitions);
        unset($model->topic);

        // make adjustments to 'comment' entity if present
        $comment = $model->getVariable('comment');
        if ($comment) {
            $model->setVariable('comment', $this->normalizeComment($comment, $limitEntityFields));
        }

        // if a list of comments is present, normalize each one
        $comments = $model->getVariable('comments');
        if ($comments) {
            $comments = array_values($comments);
            foreach ($comments as $key => $comment) {
                $comments[$key] = $this->normalizeComment($comment, $limitEntityFields);
            }

            $model->setVariable('comments', $comments);
        }

        return $model;
    }

    protected function normalizeComment($comment, $limitEntityFields = null)
    {
        return $this->limitEntityFields($this->sortEntityFields($comment), $limitEntityFields);
    }

    /**
     * Examines provided context to determine if any parts are missing and need to be filled in
     *
     * @param   $comment    array                       the full comment structure
     * @throws  \InvalidArgumentException               if an invalid argument was provided
     * @throws  \P4\Spec\Exception\NotFoundException    if no such change or review exists.
     * @return array        the full comment structure, with context filtered for Swarm consumption
     */
    protected function filterCommentContext($comment)
    {
        // extract context and exit early if there's nothing to do
        $context = isset($comment['context']) ? $comment['context'] : array();
        if (!$context || !is_array($comment['context'])) {
            unset($comment['context']);
            return $comment;
        }

        // extract the topic information and limit context normalization to Changes and Reviews
        if (!preg_match('#(changes|reviews)/([0-9]+)#', isset($comment['topic']) ? $comment['topic'] : '', $matches)) {
            unset($comment['context']);
            return $comment;
        }

        $group = $matches[1];
        $id    = $matches[2];

        // ensure that a path has been provided
        if (!isset($context['file'])) {
            throw new \InvalidArgumentException("File path is required when specifying inline comment context.");
        }

        $services = $this->getServiceLocator();
        $p4       = $services->get('p4');
        $review   = null;
        $change   = null;

        // if commenting on a review, fetch the review and inject its ID into the context
        if ($group === 'reviews') {
            $review            = Review::fetch($id, $p4);
            $context['review'] = $review->getId();
            unset($context['change']);
        }

        // if commenting on a change, fetch the change and inject its ID into the context
        if ($group === 'changes') {
            $change            = Change::fetchById($id, $p4);
            $context['change'] = $change->getId();
            unset($context['review']);
        }

        // fetch valid review versions and determine which version is being commented on (default to latest)
        $validVersions = $review ? $review->get('versions') : array();
        $version       = isset($context['version']) ? $context['version'] : null;
        $version       = $version ? (int)$version : count($validVersions);
        if ($review && !$review->hasVersion($version)) {
            throw new \InvalidArgumentException("Specified version was not found in this review.");
        }

        // if content has already been provided, check that it is a valid array of strings,
        // then set the leftLine/rightLine/version and exit with sorted context fields
        if (isset($context['content']) && is_array($context['content']) && count($context['content']) > 0) {
            foreach ($context['content'] as $value) {
                if (!is_string($value)) {
                    throw new \InvalidArgumentException('Context content must be an array of strings.');
                }
            }

            if ($review && $version) {
                $context['version'] = $version;
            }

            $context['leftLine']  = isset($context['leftLine'])  ? $context['leftLine']  : null;
            $context['rightLine'] = isset($context['rightLine']) ? $context['rightLine'] : null;
            $comment['context']   = $this->sortContextFields($context);
            return $comment;
        }

        if ($review && $version) {
            $change             = Change::fetchById($review->getChangeOfVersion($version), $p4);
            $context['version'] = $version;
        }

        // find the data for the specified file in the change
        $fileData = current(
            array_filter(
                $change->getFileData(true),
                function ($item) use ($context) {
                    return $item['depotFile'] === $context['file'];
                }
            )
        );

        if (!$fileData) {
            throw new \InvalidArgumentException('File path not found in specified review/change.');
        }

        $action    = isset($fileData['action'])   ? $fileData['action']         : 'edit';
        $leftLine  = isset($context['leftLine'])  ? (int) $context['leftLine']  : null;
        $rightLine = isset($context['rightLine']) ? (int) $context['rightLine'] : null;
        $rightPath = $fileData['depotFile'];
        $pending   = $change->isPending();
        $rev       = $pending ? $change->getId() : $fileData['rev'];
        $maxLines  = 5;
        $lines     = $this->fetchDiffSnippet($action, $leftLine, $rightLine, $rightPath, $pending, $rev, $maxLines);

        // if the line is not found in the diff and we have a line number,
        // we check for context using fileAction() in the Files module
        $lineNumber = isset($context['rightLine']) ? $context['rightLine'] : null;
        $lineNumber = !$lineNumber && isset($context['leftLine']) ? $context['leftLine'] : $lineNumber;
        if (!$lines && $lineNumber) {
            $lines = $this->fetchFullFileSnippet($rightPath, $lineNumber, $maxLines);
        }

        // ensure that, at most, only $maxLines of content are attached to the context
        // if no lines are matched, convert the context to a file-level comment
        if (count($lines) > 0) {
            $context['leftLine']  = $leftLine  ?: null;
            $context['rightLine'] = $rightLine ?: null;
            $context['content']   = array_map(
                function ($value) {
                    return substr($value, 0, 256);
                },
                array_slice($lines, 0, $maxLines)
            );
        } else {
            unset($context['leftLine']);
            unset($context['rightLine']);
            unset($context['content']);
        }

        $comment['context'] = $this->sortContextFields($context);

        return $comment;
    }

    protected function fetchDiffSnippet($action, $leftLine, $rightLine, $rightPath, $pending, $rev, $maxLines)
    {
        $leftRev    = $pending ? '' : '#' . ($rev - 1);
        $diffParams = array(
            'right'  => $rightPath . ($pending ? '@=' . $rev : '#' . $rev),
            'left'   => $rightPath . $leftRev,
            'action' => $action,
        );

        if ($leftRev === '#0' || $action == 'add') {
            unset($diffParams['left']);
        }

        $diffResult = $this->forward(
            'Files\Controller\Index',
            'diff',
            null,
            $diffParams
        );

        $diff      = $diffResult->getVariable('diff', array());
        $lines     = array();
        $foundLine = false;

        // scan through the diff to find if any of the chunks match the provided line
        // number, maintaining a buffer of the most recent $maxLines lines examined,
        // using array_shift to discard older lines.
        // when moving to the next diff chunk, if we haven't found a matching line,
        // reset the $lines buffer.
        foreach ($diff['lines'] as $currentLine) {
            if ($currentLine['type'] === 'meta') {
                $lines = $foundLine ? $lines : array();
                continue;
            }

            if ($foundLine) {
                continue;
            }

            // add the current line to the buffer
            array_push($lines, $currentLine['value']);

            if ($leftLine && $currentLine['leftLine'] === $leftLine) {
                $foundLine = true;
            }

            if ($rightLine && $currentLine['rightLine'] === $rightLine) {
                $foundLine = true;
            }

            // remove a line from the back of the buffer, if it contains more than $maxLines
            if (count($lines) > $maxLines) {
                array_shift($lines);
            }
        }

        return $foundLine ? $lines : array();
    }

    protected function fetchFullFileSnippet($rightPath, $lineNumber, $maxLines)
    {
        $lines      = array();
        $fileResult = $this->forward(
            'Files\Controller\Index',
            'file',
            array(
                'path' => $rightPath,
            ),
            array(
                'lines'  => array(
                    'start' => $lineNumber - ($maxLines - 1) > 0 ? $lineNumber - ($maxLines - 1) : 1,
                    'end'   => $lineNumber
                ),
                'view'   => true,
                'format' => 'json',
            )
        );

        if ($fileResult instanceof CallbackResponse) {
            $lines = array_values(json_decode($fileResult->getContent(), true));
        }

        // reformat the lines
        // - include a leading space that indicates the lines are not an add or an edit
        // - remove newline characters
        foreach ($lines as $key => $line) {
            $lines[$key] = ' ' . preg_replace('/(\r\n|\n)$/', '', $line);
        }

        return $lines;
    }

    /**
     * Helper to order context fields according to expectations
     *
     * @param   array   $context    the context keys/values to sort (shallow)
     * @return  array   the sorted keys/values
     */
    protected function sortContextFields(array $context)
    {
        $sortedContext = array();
        $contextOrder  = array('file', 'leftLine', 'rightLine', 'content', 'change', 'review', 'version');

        foreach ($contextOrder as $key) {
            if (array_key_exists($key, $context)) {
                $sortedContext[$key] = $context[$key];
                unset($context[$key]);
            }
        }

        // return with any leftover keys at the end
        return $sortedContext + $context;
    }
}
