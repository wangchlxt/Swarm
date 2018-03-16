<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Comments;

use Activity\Model\Activity;
use Application\Filter\Linkify;
use Attachments\Model\Attachment;
use Comments\Model\Comment as CommentModel;
use Groups\Model\Config as GroupConfig;
use Mail\MailAction;
use Mail\Module as Mail;
use P4\File\File;
use P4\Spec\Change;
use P4\Spec\Job;
use Projects\Model\Project;
use Reviews\Model\Review;
use Users\Model\User;
use Groups\Model\Group;
use Zend\Mvc\MvcEvent;

class Module
{
    /**
     * Connect to queue event manager to handle new comments.
     *
     * @param   MvcEvent    $event  the bootstrap event
     * @return  void
     */
    public function onBootstrap(MvcEvent $event)
    {
        $application = $event->getApplication();
        $services    = $application->getServiceManager();
        $events      = $services->get('queue')->getEventManager();


        // when a comment is created, fetch it and prepare notifications.
        $events->attach(
            'task.comment',
            function ($event) use ($services) {
                $logger   = $services->get('logger');
                $p4Admin  = $services->get('p4_admin');
                $config   = $services->get('config');
                $keywords = $services->get('review_keywords');
                $id       = $event->getParam('id');
                $data     = $event->getParam('data') + array(
                    'user'         => null,
                    'previous'     => array(),
                    'current'      => array(),
                    'sendComments' => array(),
                    'quiet'        => null
                );
                $projects = array();

                $logger->info('Processing ' . $event->getName() . ' for ' . $id);
                $quiet     = $event->getParam('quiet', $data['quiet']);
                $sendBatch = is_array($data['sendComments']) && count($data['sendComments']);

                try {
                    // fetch comment record
                    $comment = CommentModel::fetch($id, $p4Admin);
                    $context = $comment->getFileContext();
                    $event->setParam('comment', $comment);

                    // there are several types of comment activity - compare new against old to see what happened
                    $data['current'] = $data['current'] ?: $comment->get();
                    $commentAction   = CommentModel::deriveAction($data['current'], $data['previous']);
                    $event->setParam('commentAction', $commentAction);

                    // exit early if the comment was not modified or it was unliked
                    if (in_array($commentAction, array(CommentModel::ACTION_NONE, CommentModel::ACTION_UNLIKE))) {
                        // might still need to send delayed comments (user likely ended batch with no edits)
                        if ($sendBatch) {
                            $services->get('queue')->addTask(
                                'comment.batch',
                                $comment->get('topic'),
                                array('sendComments' => $data['sendComments'])
                            );
                        }

                        return;
                    }
                    // determine the action to report
                    $action    = MailAction::COMMENT_ADDED;
                    $taskState = $data['current']['taskState'];
                    if ($commentAction === CommentModel::ACTION_ADD && $taskState !== CommentModel::TASK_COMMENT) {
                        $action = 'opened an issue on';
                    } elseif ($commentAction === CommentModel::ACTION_STATE_CHANGE) {
                        $oldState    = isset($data['previous']['taskState'])
                            ? $data['previous']['taskState']
                            : null;
                        $transitions = array(
                            CommentModel::TASK_COMMENT   => 'cleared',
                            CommentModel::TASK_OPEN      => $oldState === CommentModel::TASK_COMMENT
                                ? 'opened'
                                : 'reopened',
                            CommentModel::TASK_ADDRESSED => 'addressed',
                            CommentModel::TASK_VERIFIED  => 'verified'
                        );

                        $action = isset($transitions[$taskState])
                            ? $transitions[$taskState] . ' an issue on'
                            : 'changed task state on';
                    } elseif ($commentAction === CommentModel::ACTION_EDIT) {
                        $action = MailAction::COMMENT_EDITED;
                    } elseif ($commentAction === CommentModel::ACTION_LIKE) {
                        $action = MailAction::COMMENT_LIKED;
                    }

                    // prepare comment info for activity streams
                    $activity = new Activity;
                    $activity->set(
                        array(
                            'type'          => 'comment',
                            'user'          => $data['user'] ?: $comment->get('user'),
                            'action'        => $action,
                            'target'        => $comment->get('topic'),
                            'description'   => $comment->get('body'),
                            'topic'         => $comment->get('topic'),
                            'depotFile'     => $context['file'],
                            'time'          => $comment->get('updated')
                        )
                    );
                    $event->setParam('activity', $activity);

                    // prepare attachment info for comment notification emails
                    if ($comment->get('attachments')) {
                        $event->setParam(
                            'attachments',
                            Attachment::fetchAll(
                                array(Attachment::FETCH_BY_IDS => $comment->get('attachments')),
                                $p4Admin
                            )
                        );
                    }

                    // default mail message subject is simply the topic name.
                    $subject = $comment->get('topic');

                    // enhance activity and mail info if we recognize the topic type
                    $to    = array();
                    $topic = $comment->get('topic');

                    // setup default value of In-Reply-To header
                    $inReplyTo = '<topic-' . $topic . '@' . Mail::getInstanceName($config['mail']) . '>';
                    $type      = 'comment';


                    // start by priming mentions with valid users in this new comment
                    // later we'll also add in @mentions from other locations
                    $mentions = Linkify::getCallouts($comment->get('body'));

                    // handle change comments
                    if (strpos($topic, 'changes/') === 0) {
                        $change = $context['change'] ?: str_replace('changes/', '', $topic);
                        $target = 'change ' . $change;
                        $hash   = 'comments';
                        if ($context['file']) {
                            $line    = isset($context['line']) ? ", line " .  $context['line'] : '';
                            $target .= " (" . File::decodeFilespec($context['name']) . $line . ")";
                            $hash    = $context['md5'] . ',c' . $comment->getId();
                        }

                        $activity->set('target', $target);
                        $activity->set('link',   array('change', array('change' => $change, 'fragment' => $hash)));

                        try {
                            $change = Change::fetchById($change, $p4Admin);

                            // set 'change' field on activity, we want to ensure its the change id
                            // in theory it might be different from $change in case the change was renumbered
                            // and we got it from the topic as topics keep reference to the original id
                            $activity->set('change', $change->getId());

                            // change author, @mentions and project(s) should be notified
                            $to[]     = $change->getUser();
                            $mentions = array_merge($mentions, Linkify::getCallouts($change->getDescription()));
                            $activity->addFollowers($change->getUser());
                            $activity->addProjects(Project::getAffectedByChange($change, $p4Admin));

                            // enhance mail subject to use the change description (will be cropped)
                            $subject = 'Change @' . $change->getId() . ' - '
                                      . $keywords->filter($change->getDescription());
                        } catch (\Exception $e) {
                            $services->get('logger')->err($e);
                        }
                    }

                    // handle review comments
                    if (strpos($topic, 'reviews/') === 0) {
                        $review = $context['review'] ?: str_replace('reviews/', '', $topic);
                        $target = 'review ' . $review;
                        $hash   = 'comments';
                        if ($context['file']) {
                            $line    = isset($context['line']) ? ", line " .  $context['line'] : '';
                            $target .= " (" . File::decodeFilespec($context['name']) . $line . ")";
                            $hash    = $context['md5'] . ',c' . $comment->getId();
                        }

                        $activity->set('target', $target);
                        $activity->set('link',   array('review', array('review' => $review, 'fragment' => $hash)));

                        try {
                            $review = Review::fetch($review, $p4Admin);
                            $event->setParam('review', $review);

                            // associate activity with review's head change so we can filter for restricted changes
                            $activity->set('change', $review->getHeadChange());

                            // add any folks that were @*mentioned as required reviewers
                            $review->addRequired(
                                User::filter(Linkify::getCallouts($comment->get('body'), true), $p4Admin)
                            );
                            // add any groups that were @@*mentioned as required reviewers
                            $review->addRequired(
                                Group::filter(Linkify::getCallouts($comment->get('body'), true), $p4Admin)
                            );
                            // Add any groups that where @@!mentions as require one.
                            $review->addRequired(
                                Group::filter(Linkify::getCallouts($comment->get('body'), false, true), $p4Admin),
                                "1"
                            );

                            // comment author and, valid, @mentioned users should be participants
                            $review->addParticipant($comment->get('user'))
                                   ->addParticipant(User::filter($mentions, $p4Admin))
                                   ->addParticipant(Group::filter($mentions, $p4Admin))
                                   ->save();

                            // review comments should appear on the review stream
                            $activity->addStream('review-' . $review->getId());

                            // all review participants excluding those with notifications disabled should be notified
                            $disabled = $review->getParticipantsData('notificationsDisabled');
                            $to       = array_diff($review->getParticipants(), array_keys($disabled));
                            $activity->addFollowers($review->getParticipants());
                            $activity->addProjects($review->getProjects());

                            // enhance mail subject to use the review description (will be cropped)
                            $subject = 'Review @' . $review->getId() . ' - '
                                      . $keywords->filter($review->get('description'));
                        } catch (\Exception $e) {
                            $services->get('logger')->err($e);
                        }
                    }

                    // handle job comments
                    if (strpos($topic, 'jobs/') === 0) {
                        $job    = str_replace('jobs/', '', $topic);
                        $target = $job;
                        $hash   = 'comments';

                        $activity->set('target', $target);
                        $activity->set('link',   array('job', array('job' => $job, 'fragment' => $hash)));

                        try {
                            $job = Job::fetchById($job, $p4Admin);

                            // add author, modifier and possibly others to the email recipients list
                            // we find users by looping through all job's defined fields and looking
                            // for default value of '$user'
                            $fields = $job->getSpecDefinition()->getFields();
                            foreach ($fields as $key => $field) {
                                if (isset($field['default']) && $field['default'] === '$user') {
                                    $to[] = $job->get($key);
                                }
                            }

                            // notify users mentioned in job's description
                            $to = array_merge($to, Linkify::getCallouts($job->getDescription()));

                            // associated change(s) users should also be notified
                            if (count($job->getChanges())) {
                                foreach ($job->getChangeObjects() as $change) {
                                    $to[] = $change->getUser();
                                }
                            }

                            // enhance mail subject to use the job description (will be cropped)
                            $subject = $job->getId() . ' - ' . $job->getDescription();
                        } catch (\Exception $e) {
                            $services->get('logger')->err($e);
                        }
                    }

                    // every user that participates in this comment thread
                    // should be notified of this activity (excluding author).
                    try {
                        // if the topic isn't a review we want to included any previous commenters and mentioned users.
                        // if the topic is a review, we skip this step and simply rely on the review participants,
                        // otherwise we might erroneously add back in removed reviewers
                        if (strpos($topic, 'reviews/') !== 0) {
                            // examine every comment on this topic to include:
                            // - all users who posted a comment to the topic
                            // - all users who were mentioned in a comment on this topic
                            $comments = CommentModel::fetchAll(array('topic' => $topic), $p4Admin);
                            $users    = array();
                            foreach ($comments as $entry) {
                                $users[]  = $entry->get('user');
                                $mentions = array_merge($mentions, Linkify::getCallouts($entry->get('body')));
                            }

                            $to = array_merge($to, $users);
                        }

                        // knock back the to list to only unique, valid ids
                        $to = array_unique(array_merge($to, $mentions));
                        // Get each group and ensure group and user exist and are real.
                        $userTo  = User::filter($to, $p4Admin);
                        $groupTo = Group::filter($to, $p4Admin);
                        // Allow the mail module to process anything we are unsure of
                        $otherTo = array_diff($to, $userTo, $groupTo);
                        // Gather up the potential recipients and make mail module friendly keys
                        $to = array_merge(
                            $userTo,
                            array_map(
                                function ($group) {
                                    return GroupConfig::KEY_PREFIX . Group::getGroupName($group);
                                },
                                $groupTo
                            ),
                            $otherTo
                        );
                        // if we're emailing you its activity stream worthy, add em
                        $activity->addFollowers($to);

                        // if receiving emails for own actions is not enabled
                        // don't email the person who carried out the action
                        $to = $config['mail']['notify_self']
                            ? $to
                            : array_diff($to, array($data['user'] ?: $comment->get('user')));

                        // if it's a new like, just email the comment author
                        if ($commentAction === CommentModel::ACTION_LIKE) {
                            // remove like author from the recipients
                            $to = array_diff($to, array($data['user'] ?: $comment->get('user')));

                            // only email the comment author if they are already a recipient
                            // this avoids emailing users who like their own comments
                            $to = array_intersect($to, array($comment->get('user')));
                        };

                        // configure mail notification - only email for adds, edits and new likes
                        $actionsToEmail = array(
                            CommentModel::ACTION_ADD,
                            CommentModel::ACTION_EDIT,
                            CommentModel::ACTION_LIKE
                        );


                        // setup default header values
                        $createdMessageID =  $comment->createMessageId();
                        $messageId        = '<' . $createdMessageID
                            . ( $commentAction !== CommentModel::ACTION_ADD
                                ? ( '-' . $commentAction . '-' . time() ) : "" )
                            . '@' . Mail::getInstanceName($config['mail']) . '>';
                        $inReplyTo        = '<topic-' . $topic  . '@' . Mail::getInstanceName($config['mail']) . '>';

                        // try to find previous comment in this topic, so that we can sub-thread comments
                        $previousComment = $comment->getPreviousComment($p4Admin);
                        if (null !== $previousComment) {
                            $inReplyTo = '<' . $previousComment->createMessageId()
                                . '@' . Mail::getInstanceName($config['mail']) . '>';
                        }
                        // Make comment likes replies to the original comment
                        if (CommentModel::ACTION_LIKE === $commentAction) {
                            $inReplyTo = '<' . $createdMessageID . '@' . Mail::getInstanceName($config['mail']) . '>';
                        }

                        if (in_array($commentAction, $actionsToEmail)) {
                            $event->setParam(
                                'mail',
                                array(
                                    'author'        => $comment->get('user'),
                                    'subject'       => $subject,
                                    'cropSubject'   => 80,
                                    'toUsers'       => $to,
                                    'fromUser'      => $data['user'] ?: $comment->get('user'),
                                    'messageId'     => $messageId,
                                    'inReplyTo'     => $inReplyTo,
                                    'projects'      => array_keys($activity->getProjects()),
                                    'htmlTemplate'  => __DIR__ . '/view/mail/comment-html.phtml',
                                    'textTemplate'  => __DIR__ . '/view/mail/comment-text.phtml',
                                )
                            );
                            $logger->debug("Added email details to " . $event->getName() . ' for ' . $id);
                        }

                        // create batch task if we were instructed to send notification for delayed comments
                        // we do it after this task has updated related records so the batch task can pull
                        // out fresh data
                        if ($sendBatch) {
                            $logger->debug('Queuing batch notification for ' . $id);
                            $services->get('queue')->addTask(
                                'comment.batch',
                                $topic,
                                array('sendComments' => $data['sendComments'])
                            );

                            // silence email as the batch task will include this comment in the aggregated notification
                            if ($quiet !== true) {
                                $quiet = array_merge((array) $quiet, array('mail'));
                                $event->setParam('quiet', $quiet);
                            }
                        }
                        $logger->debug("Finished processing " . $event->getName() . " for " . $id);
                    } catch (\Exception $e) {
                        $services->get('logger')->err($e);
                    }
                } catch (\Exception $e) {
                    $services->get('logger')->err($e);
                }
            },
            100
        );

        $events->attach(
            'task.comment.batch',
            function ($event) use ($services) {
                $logger   = $services->get('logger');
                $config   = $services->get('config');
                $p4Admin  = $services->get('p4_admin');
                $keywords = $services->get('review_keywords');
                $topic    = $event->getParam('id');
                $data     = $event->getParam('data') + array('sendComments' => null);
                $logger->debug("Processing " . $event->getName() . " for " . $topic);

                try {
                    // we need the review model - bail if we can't fetch it
                    if (strpos($topic, 'reviews/') !== 0) {
                        throw new \RuntimeException("Unexpected topic for comment batch ($topic).");
                    }
                    $review = Review::fetch(str_replace('reviews/', '', $topic), $p4Admin);

                    // we need the comment records - bail if we have none
                    $commentIds = (array) array_keys($data['sendComments']);
                    $comments   = CommentModel::fetchAll(
                        array(CommentModel::FETCH_BY_IDS => $commentIds),
                        $p4Admin
                    );
                    if (!$comments->count()) {
                        throw new \RuntimeException("No valid comments in comment batch.");
                    }

                    // preserve the order that the comments appear in the batch
                    $comments->sortBy('id', array($comments::SORT_FIXED => $commentIds));

                    // set event parameters for use in the templates
                    $attachments = CommentModel::fetchAttachmentsByComments($comments, $p4Admin);
                    $event->setParam('attachments',  $attachments);
                    $event->setParam('comments',     $comments);
                    $event->setParam('review',       $review);
                    $event->setParam('sendComments', $data['sendComments']);

                    // Set the activity ensuring that the type and action is set.
                    $activity = new Activity;
                    $activity->set(
                        array(
                            'type'          => 'comment',
                            'user'          => '',
                            'action'        => MailAction::COMMENT_EDITED,
                            'target'        => '',
                            'description'   => '',
                            'topic'         => '',
                            'depotFile'     => '',
                            'time'          => ''
                        )
                    );
                    $event->setParam('activity', $activity);

                    // since all comments are on the same review, we set 'restrictByChange'
                    // to the review's head change to enable filtering by restricted changes
                    $event->setParam('restrictByChange', $review->getHeadChange());

                    // notify all review participants excluding the comment author
                    $user = $comments->first()->get('user');

                    // Add the participants to the to list.
                    $to = $review->getParticipants();

                    // if receiving emails for own actions is not enabled
                    // don't email the person who carried out the action
                    $to = $config['mail']['notify_self'] ? $to : array_diff($to, array($user));

                    // set parameters for the mail listener
                    $subject = 'Review @' . $review->getId() . ' - '
                             . $keywords->filter($review->get('description'));
                    $event->setParam(
                        'mail',
                        array(
                            'author'       => $comments->first()->get('user'),
                            'subject'      => $subject,
                            'cropSubject'  => 80,
                            'toUsers'      => $to,
                            'fromUser'     => $user,
                            'projects'     => array_keys($review->getProjects()),
                            'messageId'    =>
                                '<comments-batch-' . $topic . '-'
                                . time() . '@' . Mail::getInstanceName($config['mail']) . '>',
                            'inReplyTo'    => '<topic-' . $topic . '@' . Mail::getInstanceName($config['mail']) . '>',
                            'htmlTemplate' => __DIR__ . '/view/mail/batch-comments-html.phtml',
                            'textTemplate' => __DIR__ . '/view/mail/batch-comments-text.phtml',
                        )
                    );
                    $logger->debug("Added email details to " . $event->getName() . ' for ' . $topic);
                    $logger->debug("Finished processing " . $event->getName() . " for " . $topic);
                } catch (\Exception $e) {
                    $services->get('logger')->err($e);
                }
            }
        );
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function getAutoloaderConfig()
    {
        return array(
            'Zend\Loader\StandardAutoloader' => array(
                'namespaces' => array(
                    __NAMESPACE__ => __DIR__ . '/src/' . __NAMESPACE__,
                ),
            ),
        );
    }
}
