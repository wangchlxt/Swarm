<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Comments\Controller;

use Application\Permissions\Exception\ForbiddenException;
use Application\Permissions\Protections;
use Attachments\Model\Attachment;
use Comments\Model\Comment;
use P4\Spec\Change;
use P4\Spec\Exception\NotFoundException as SpecNotFoundException;
use Record\Exception\NotFoundException as RecordNotFoundException;
use Reviews\Model\Review;
use Users\Model\User;
use Application\InputFilter\InputFilter;
use Zend\Json\Json;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;
use Zend\View\Model\ViewModel;
use Zend\Stdlib\Parameters;

class IndexController extends AbstractActionController
{
    /**
     * Index action to return rendered comments for a given topic.
     *
     * @return  ViewModel
     */
    public function indexAction()
    {
        $topic   = trim($this->getEvent()->getRouteMatch()->getParam('topic'), '/');
        $request = $this->getRequest();
        $query   = $request->getQuery();
        $format  = $query->get('format');

        // determine version-specific information
        $context      = $query->get('context');
        $limitVersion = (bool) $query->get('limitVersion', false);

        // send 404 if no topic is provided for non-JSON request
        if (!strlen($topic) && $format !== 'json') {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        // if the topic is provided and relates to a review or a change, ensure it's accessible
        if (strlen($topic)) {
            $this->restrictAccess($topic);
        }

        // handle requests for JSON
        if ($format === 'json') {
            $services   = $this->getServiceLocator();
            $p4Admin    = $services->get('p4_admin');
            $ipProtects = $services->get('ip_protects');
            $comments   = Comment::fetchAll(
                $this->getFetchAllOptions($topic, $query),
                $p4Admin,
                $ipProtects
            );

            // if a version has been provided and this is a review topic,
            // filter out any comments that don't have a matching version
            if ($limitVersion && strpos($topic, 'reviews/') === 0) {
                $version = $context['version'];
                $comments->filterByCallback(
                    function (Comment $comment) use ($version) {
                        $context = $comment->getContext();
                        return isset($context['version']) && $context['version'] == $version;
                    }
                );
            }

            // prepare comments for output
            $preparedComments = $comments->toArray();

            // handle the case when only tasks are requested
            $tasksOnly = $query->get('tasksOnly');
            if ($tasksOnly && $tasksOnly !== 'false') {
                $view = $services->get('viewrenderer');
                foreach ($preparedComments as $id => &$comment) {
                    // prepare comment url
                    $fileContext    = $comments[$id]->getFileContext();
                    $comment['url'] = $fileContext['file']
                        ? '#' . $view->escapeUrl($fileContext['md5']) . ',c' . $view->escapeUrl($id)
                        : '#comments';
                }
            }

            return new JsonModel(
                array(
                    'topic'    => $topic,
                    'comments' => $preparedComments,
                    'lastSeen' => $comments->getProperty('lastSeen')
                )
            );
        }

        $view = new ViewModel(
            array(
                'topic'     => $topic,
                'version'   => $limitVersion && isset($context['version']) ? $context['version'] : false,
                'tasksOnly' => $query->get('tasksOnly')
            )
        );

        $view->setTerminal(true);
        return $view;
    }

    /**
     * Action to add a new comment.
     *
     * @return  JsonModel
     */
    public function addAction()
    {
        $request  = $this->getRequest();
        $services = $this->getServiceLocator();
        $services->get('permissions')->enforce('authenticated');

        // if the topic relates to a review or change, ensure it's accessible
        $this->restrictAccess($request->getPost('topic'));

        $p4Admin  = $services->get('p4_admin');
        $user     = $services->get('user');
        $comments = $services->get('viewhelpermanager')->get('comments');
        $filter   = $this->getCommentFilter($user, 'add', array(Comment::TASK_COMMENT, Comment::TASK_OPEN));
        $delay    = $request->getPost('delayNotification', false);
        $posted   = $request->getPost()->toArray();

        $filter->setData($posted);
        $isValid = $filter->isValid();
        if ($isValid) {
            $comment = new Comment($p4Admin);
            $comment->set($filter->getValues())
                    ->save();

            // delay comment email notification if we are instructed to do so;
            // otherwise collect previously delayed notifications to send
            $sendComments = $this->handleDelayedComments($comment, $delay);

            // push comment into queue for further processing.
            // note that we don't send individual notifications for delayed comments
            $queue = $services->get('queue');
            $queue->addTask(
                'comment',
                $comment->getId(),
                array(
                    'current'      => $comment->get(),
                    'quiet'        => $delay ? array('mail') : null,
                    'sendComments' => $sendComments
                )
            );
        }

        $data = array(
            'isValid'  => $isValid,
            'messages' => $filter->getMessages(),
            'comment'  => $isValid ? $comment->get() : null,
        );

        if ($request->getPost('bundleTopicComments', true)) {
            $context = isset($posted['context']) && strlen($posted['context'])
                ? Json::decode($posted['context'], Json::TYPE_ARRAY)
                : null;
            $version = $request->getPost('limitVersion') && isset($context['version'])
                ? $context['version']
                : null;

            $data['comments'] = $isValid
                ? $comments($filter->getValue('topic'), null, $version)
                : null;
        }

        return new JsonModel($data);
    }

    /**
     * Action to edit a comment
     *
     * @return JsonModel
     */
    public function editAction()
    {
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return new JsonModel(
                array(
                    'isValid'   => false,
                    'error'     => 'Invalid request method. HTTP POST required.'
                )
            );
        }

        // start by ensuring the user is at least logged in
        $services = $this->getServiceLocator();
        $user     = $services->get('user');
        $services->get('permissions')->enforce('authenticated');

        // attempt to retrieve the specified comment
        // translate invalid/missing id's into a 404
        try {
            $id      = $this->getEvent()->getRouteMatch()->getParam('comment');
            $p4Admin = $services->get('p4_admin');
            $comment = Comment::fetch($id, $p4Admin);
        } catch (RecordNotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }

        if (!isset($comment)) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        // if a topic was not provided, try to fetch the comment and use the comment's topic
        $topic = $this->request->getPost('topic') ?: $comment->get('topic');

        // if the topic relates to a review or a change, ensure it's accessible
        $this->restrictAccess($topic);

        // users can only edit the content of comments they own
        $isContentEdit = $request->getPost('body') !== null || $request->getPost('attachments') !== null;
        if ($isContentEdit && $comment->get('user') !== $user->getId()) {
            $this->getResponse()->setStatusCode(403);
            return;
        }

        // users cannot add/remove likes on archived comments
        $isLike = $request->getPost('addLike') || $request->getPost('removeLike');
        if ($isLike && in_array('closed', $comment->getFlags())) {
            $this->getResponse()->setStatusCode(403);
            return;
        }

        $filter   = $this->getCommentFilter($user, 'edit', array_keys($comment->getTaskTransitions()));
        $comments = $services->get('viewhelpermanager')->get('comments');
        $posted   = $request->getPost()->toArray();
        $filter->setValidationGroupSafe(array_keys($posted));

        // if the user has selected verify and archive, add the appropriate flag
        if (isset($posted['taskState']) && $posted['taskState'] == Comment::TASK_VERIFIED_ARCHIVE) {
            $posted['addFlags'] = 'closed';
        }

        $filter->setData($posted);
        $isValid = $filter->isValid();
        if ($isValid) {
            $old      = $comment->get();
            $filtered = $filter->getValues();

            // add/remove likes and flags are not stored fields
            unset(
                $filtered['addLike'],
                $filtered['removeLike'],
                $filtered['addFlags'],
                $filtered['removeFlags']
            );

            $comment->set($filtered);

            // add/remove likes and any flags that the user passed
            $comment
                ->addLike($filter->getValue('addLike'))
                ->removeLike($filter->getValue('removeLike'))
                ->addFlags($filter->getValue('addFlags'))
                ->removeFlags($filter->getValue('removeFlags'))
                ->set('edited', $isContentEdit ? time() : $comment->get('edited'))
                ->save();

            // for content edits, handle delayed notifications
            // this means we delay email notifications when instructed to do so
            // and collect delayed comments for sending when ending a batch
            $delay        = $request->getPost()->get('delayNotification', false);
            $sendComments = $isContentEdit
                ? $this->handleDelayedComments($comment, $delay)
                : null;

            // push comment update into queue for further processing
            $queue = $services->get('queue');
            $queue->addTask(
                'comment',
                $comment->getId(),
                array(
                    'user'         => $user->getId(),
                    'previous'     => $old,
                    'current'      => $comment->get(),
                    'quiet'        => $delay ? array('mail') : null,
                    'sendComments' => $sendComments
                )
            );
        } else {
            $this->getResponse()->setStatusCode(400);
        }

        $data = array(
            'isValid'         => $isValid,
            'messages'        => $filter->getMessages(),
            'taskTransitions' => $comment->getTaskTransitions(),
            'comment'         => $comment->get(),
        );

        if ($request->getPost('bundleTopicComments', true)) {
            $context = isset($posted['context']) && strlen($posted['context'])
                ? Json::decode($posted['context'], Json::TYPE_ARRAY)
                : null;
            $version = $request->getPost('limitVersion') && isset($context['version'])
                ? $context['version']
                : null;

            $data['comments'] = $comments($comment->get('topic'), null, $version);
        }

        return new JsonModel($data);
    }

    /**
     * Return the filter for data to add comments.
     *
     * @param   User            $user           the current authenticated user.
     * @param   string          $mode           one of 'add' or 'edit'
     * @param   array           $transitions    transitions being validated against
     * @return  InputFilter     filter for adding comments data
     */
    protected function getCommentFilter(User $user, $mode, array $transitions = array())
    {
        $services       = $this->getServiceLocator();
        $ipProtects     = $services->get('ip_protects');
        $filter         = new InputFilter;
        $flagValidators = array(
            array(
                'name'      => '\Application\Validator\IsArray'
            ),
            array(
                'name'      => '\Application\Validator\Callback',
                'options'   => array(
                    'callback'  => function ($value) {
                        if (in_array(false, array_map('is_string', $value))) {
                            return 'flags must be set as strings';
                        }

                        return true;
                    }
                )
            )
        );
        $userValidator = array(
            'name'      => '\Application\Validator\Callback',
            'options'   => array(
                'callback'  => function ($value) use ($user) {
                    if ($value !== $user->getId()) {
                        return 'Not logged in as %s';
                    }

                    return true;
                }
            )
        );

        // ensure user is provided and refers to the active user
        $filter->add(
            array(
                'name'          => 'user',
                'required'      => true,
                'validators'    => array($userValidator)
            )
        );

        $filter->add(
            array(
                'name'      => 'topic',
                'required'  => true
            )
        );

        $filter->add(
            array(
                'name'      => 'context',
                'required'  => false,
                'filters'   => array(
                    array(
                        'name'      => '\Zend\Filter\Callback',
                        'options'   => array(
                            'callback'  => function ($value) {
                                if (is_array($value)) {
                                    return $value;
                                }

                                return $value !== null && strlen($value)
                                    ? Json::decode($value, Json::TYPE_ARRAY)
                                    : null;
                            }
                        )
                    )
                ),
                'validators' => array(
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($value) use ($ipProtects) {
                                // deny if user doesn't have list access to the context file
                                $file = isset($value['file']) ? $value['file'] : null;
                                if ($file && !$ipProtects->filterPaths($file, Protections::MODE_LIST)) {
                                    return "No permission to list the associated file.";
                                }

                                return true;
                            }
                        )
                    )
                )
            )
        );

        $filter->add(
            array(
                'name'          => 'attachments',
                'required'      => false,
                'validators'    => array(
                    array(
                        'name'          => '\Application\Validator\Callback',
                        'options'       => array(
                            'callback'  => function ($value) use ($services) {
                                // allow empty value
                                if (empty($value)) {
                                    return true;
                                }

                                // error on invalid input (e.g., a string)
                                if (!is_array($value)) {
                                    return false;
                                }

                                // ensure all IDs are true integers and correspond to existing attachments
                                foreach ($value as $id) {
                                    if (!ctype_digit((string) $id)) {
                                        return false;
                                    }
                                }

                                if (count(Attachment::exists($value, $services->get('p4_admin'))) != count($value)) {
                                    return "Supplied attachment(s) could not be located on the server";
                                }

                                return true;
                            }
                        )
                    )
                )
            )
        );

        $filter->add(
            array(
                'name'      => 'body',
                'required'  => true
            )
        );

        $filter->add(
            array(
                'name'          => 'flags',
                'required'      => false,
                'validators'    => $flagValidators
            )
        );

        $filter->add(
            array(
                'name'              => 'delayNotification',
                'required'          => false,
                'continue_if_empty' => true,
            )
        );

        $filter->add(
            array(
                'name'       => 'taskState',
                'required'   => false,
                'validators' => array(
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($value) use ($mode, $transitions) {
                                if (!in_array($value, $transitions, true)) {
                                    return 'Invalid task state transition specified. '
                                         . 'Valid transitions are: ' . implode(', ', $transitions);
                                }
                                return true;
                            }
                        )
                    )
                )
            )
        );

        // in edit mode don't allow user, topic, or context
        // but include virtual add/remove flags and add/remove like fields
        if ($mode == 'edit') {
            $filter->remove('user');
            $filter->remove('topic');
            $filter->remove('context');

            $filter->add(
                array(
                    'name'          => 'addFlags',
                    'required'      => false,
                    'validators'    => $flagValidators
                )
            );

            $filter->add(
                array(
                    'name'          => 'removeFlags',
                    'required'      => false,
                    'validators'    => $flagValidators
                )
            );

            $filter->add(
                array(
                    'name'          => 'addLike',
                    'required'      => false,
                    'validators'    => array($userValidator)
                )
            );

            $filter->add(
                array(
                    'name'          => 'removeLike',
                    'required'      => false,
                    'validators'    => array($userValidator)
                )
            );
        }

        return $filter;
    }

    /**
     * Helper to ensure that the given topic does not refer to a forbidden change
     * or an inaccessible private project.
     *
     * @param   string  $topic      the topic to check change/review access for
     * @throws  ForbiddenException  if the topic refers to a change or project the user can't access
     */
    protected function restrictAccess($topic)
    {
        // early exit if the topic is not change related
        if (!preg_match('#(changes|reviews)/([0-9]+)#', $topic, $matches)) {
            return;
        }

        $group = $matches[1];
        $id    = $matches[2];

        // if the topic refers to a review, we need to fetch it to determine the change
        // and whether the review belongs only to private projects
        // if the topic refers to a change, it always uses the original change id, but for
        // the access check we need to make sure we use the latest/renumbered id.
        $services = $this->getServiceLocator();
        if ($group === 'reviews') {
            $review = Review::fetch($id, $services->get('p4_admin'));
            $change = $review->getHeadChange();
        } else {
            // resolve original number to latest/submitted change number
            // for 12.1+ we can rely on 'p4 change -O', for older servers, try context param
            $p4     = $services->get('p4');
            $lookup = $id;
            if (!$p4->isServerMinVersion('2012.1')) {
                $context = $this->getRequest()->getQuery('context', array());
                $lookup  = isset($context['change']) ? $context['change'] : $id;
            }

            try {
                $change = Change::fetchById($lookup, $services->get('p4'));
                $change = $id == $change->getOriginalId() ? $change->getId() : false;
            } catch (SpecNotFoundException $e) {
                $change = false;
            }
        }

        if ($change === false
            || !$services->get('changes_filter')->canAccess($change)
            || (isset($review) && !$services->get('projects_filter')->canAccess($review))
        ) {
            throw new ForbiddenException("You don't have permission to access this topic.");
        }
    }

    /**
     * Delay notification for the given comment or collect delayed
     * comments and close the batch if we are sending (delay is false).
     *
     * @param   Comment     $comment    comment to process
     * @param   bool        $delay      delay this comment, false to close the batch
     * @return  array|null  delayed comment data if sending, null otherwise
     */
    protected function handleDelayedComments(Comment $comment, $delay)
    {
        $topic           = $comment->get('topic');
        $userConfig      = $this->getServiceLocator()->get('user')->getConfig();
        $delayedComments = $userConfig->getDelayedComments($topic);

        // nothing to do if we are sending but there are no delayed comments
        if (!$delay && !count($delayedComments)) {
            return null;
        }

        // if not already present, add the comment to delayed comments; in the case of an add,
        // the comment batch time should match the time of the first comment - this should avoid
        // later concluding that the comment was created before the batch
        if (!array_key_exists($comment->getId(), $delayedComments)) {
            $delayedComments[$comment->getId()] = $comment->get('edited')
                ? time()
                : $comment->get('time');
        }

        //make sure that the comment ending the batch has the 'batched' flag set to true
        $comment->set('batched', true)->save();

        $userConfig->setDelayedComments($topic, $delay ? $delayedComments : null)->save();
        return $delay ? null : $delayedComments;
    }

    /**
     * Prepare FetchAll options for searching comments based on a query
     *
     * @param  string       $topic  the topic parameter to be included in options
     * @param  Parameters   $query  query parameters to build options from
     * @return array        the resulting options array
     */
    protected function getFetchAllOptions($topic, Parameters $query)
    {
        $options = array(
            Comment::FETCH_AFTER    => $query->get('after'),
            Comment::FETCH_MAXIMUM  => $query->get('max', 50),
            Comment::FETCH_BY_TOPIC => $topic
        );

        // add filter options.
        // if task states filter is not provided and only tasks are requested then add option to fetch only tasks
        $user           = $query->get('user');
        $taskStates     = $query->get('taskStates');
        $ignoreArchived = $query->get('ignoreArchived');
        $tasksOnly      = $query->get('tasksOnly');

        if (!$taskStates && $tasksOnly && $tasksOnly !== 'false') {
            $taskStates = array(
                Comment::TASK_OPEN,
                Comment::TASK_ADDRESSED,
                Comment::TASK_VERIFIED
            );
        }

        $options += array(
            Comment::FETCH_BY_USER         => $user,
            Comment::FETCH_BY_TASK_STATE   => $taskStates,
            Comment::FETCH_IGNORE_ARCHIVED => $ignoreArchived && $ignoreArchived !== 'false'
        );

        // eliminate blank values to avoid potential side effects
        return array_filter(
            $options,
            function ($value) {
                return is_array($value) ? count($value) : strlen($value);
            }
        );
    }
}
