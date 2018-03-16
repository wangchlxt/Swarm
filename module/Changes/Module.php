<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Changes;

use Activity\Model\Activity;
use Application\Config\ConfigManager;
use Application\Filter\Linkify;
use Groups\Model\Config as GroupConfig;
use Groups\Model\Group;
use Mail\MailAction;
use Mail\Module as Mail;
use P4\Connection\Exception\CommandException;
use P4\Spec\Change;
use Projects\Model\Project;
use Reviews\Model\Review;
use Notifications\Settings;
use Users\Model\User;
use Zend\Mvc\MvcEvent;

class Module
{
    /**
     * Connect to queue event manager to handle changes.
     *
     * @param   MvcEvent    $event  the bootstrap event
     * @return  void
     */
    public function onBootstrap(MvcEvent $event)
    {
        $application = $event->getApplication();
        $services    = $application->getServiceManager();
        $events      = $services->get('queue')->getEventManager();
        $logger      = $services->get('logger');

        // subscribe very early to simply fetch the change and verify its worth processing at this time.
        //
        // we delay processing changes owned by 'git-fusion-user'. git-fusion commits changes as itself but
        // re-credits them to the author or pusher. There is a window where changes are still owned by
        // git-fusion-user and we don't want to process them during this period.
        //
        // further, we stop processing for changes against the .git-fusion depot. swarm cannot presently
        // show diffs effectively for the light weight branch work done by git fusion and we also want
        // to hide the changes related to git objects and other git-fusion infrastructure work.
        //
        // the below listener gets in very early (prior to even the impacted projects being calculated) and
        // requeues changes in this state into the future.
        $events->attach(
            array('task.commit', 'task.shelve'),
            function ($event) use ($services, $logger) {
                $p4Admin             = $services->get('p4_admin');
                $id                  = $event->getParam('id');
                $data                = (array) $event->getParam('data') + array('retries' => null);
                $config              = $services->get('config') + array('git_fusion' => array());
                $gitConfig           = $config['git_fusion'];
                $gitConfig          += array('user' => null, 'depot' => null, 'reown' => array());
                $gitConfig['reown'] += array('retries' => null, 'max_wait' => null);

                try {
                    $change = Change::fetchById($id, $p4Admin);
                    $event->setParam('change', $change);

                    // if we don't know where the git-fusion depot is, just process as-is
                    if (!$gitConfig['depot']) {
                        return;
                    }

                    // if the change is under the .git-fusion depot, we don't want activity for it, abort processing
                    try {
                        $flags = array('-e', $id, '-TdepotFile', '-m1', '//' . trim($gitConfig['depot'], '/') . '/...');
                        $flags = array_merge($change->isPending() ? array('-Rs') : array(), $flags);
                        $path  = $p4Admin->run('fstat', $flags)->getData(0, 'depotFile');

                        // if we got a hit this is a .git-fusion depot change and we want to ignore it
                        // stop the event to prevent activity/email/etc from being created and return
                        if ($path) {
                            $event->stopPropagation();
                            return;
                        }
                    } catch (CommandException $e) {
                        // if this is a ".git-fusion depot doesn't exist" type exception just eat it otherwise rethrow
                        if (strpos($e->getMessage(), 'must refer to client') === false) {
                            throw $e;
                        }
                    }

                    // if we don't know who the git-fusion-user is, don't delay processing
                    if (!$gitConfig['user']) {
                        return;
                    }

                    // if the change isn't owned by the git-fusion-user, no need to delay just return
                    if ($change->getUser() != $gitConfig['user']) {
                        return;
                    }

                    // if we've already maxed out our retries, don't delay further just return
                    if ($data['retries'] >= $gitConfig['reown']['retries']) {
                        $logger->err('Max git-fusion reown retries/delay exceeded for change ' . $id);
                        return;
                    }

                    // at this point we have established the change is owned by the git-fusion-user
                    // and it isn't under the .git-fusion depot. we want to abort processing and
                    // re-queue the event to be re-considered in the near future
                    // our delay gets exponentially larger up to a max (by default 60 seconds)
                    // by default, at most we'll re-queue 20 times for a delay of 16 minutes 2 seconds
                    $data['retries'] += 1;
                    $services->get('queue')->addTask(
                        $event->getParam('type'),
                        $event->getParam('id'),
                        $data,
                        time() + min(pow(2, $data['retries']), $gitConfig['reown']['max_wait'])
                    );

                    // stop further processing
                    $event->stopPropagation();
                } catch (\Exception $e) {
                    $logger->err($e);
                }
            },
            300
        );

        // when a change is committed, determine the impacted projects and prepare activity record.
        $events->attach(
            'task.commit',
            function ($event) use ($services, $logger) {
                $p4Admin  = $services->get('p4_admin');
                $keywords = $services->get('review_keywords');
                $change   = $event->getParam('change');
                try {
                    // ignore invalid/pending changes.
                    if (!$change instanceof Change || $change->getStatus() !== 'submitted') {
                        return;
                    }

                    // prepare list of projects affected by the change
                    $impacted = Project::getAffectedByChange($change, $p4Admin);

                    // prepare data model for activity streams
                    $changeId = $change->getId();
                    $activity = new Activity;
                    $activity->set(
                        array(
                            'type'          => 'change',
                            'link'          => array('change', array('change' => $changeId)),
                            'user'          => $change->getUser(),
                            'action'        => MailAction::CHANGE_COMMITTED,
                            'target'        => 'change ' . $changeId,
                            'preposition'   => 'into',
                            'description'   => $keywords->filter($change->getDescription()),
                            'topic'         => 'changes/' . $change->getOriginalId(),
                            'time'          => $change->getTime(),
                            'projects'      => $impacted,
                            'change'        => $changeId
                        )
                    );

                    // ensure any @mention'ed users are included in both the activity and the email
                    $callouts      = Linkify::getCallouts($change->getDescription());
                    $userCallouts  = User::filter($callouts, $p4Admin);
                    $groupCallouts = Group::filter($callouts, $p4Admin);
                    $logger->trace(
                        "Mail: callouts before filtering: " . var_export($callouts, true)
                    );
                    $logger->trace(
                        "Mail: valid user callouts: "       . var_export($userCallouts, true)
                    );
                    $logger->trace(
                        "Mail: valid group callouts: "      . var_export($groupCallouts, true)
                    );
                    $mentions = array_merge($userCallouts, $groupCallouts);
                    $toUsers  = $mentions;
                    $activity->addFollowers($userCallouts);

                    // if this change has an author, include them and link the topic
                    $review = $event->getParam('review');
                    if ($review instanceof Review && $review->get('author')) {
                        $activity->set('topic', 'reviews/' . $review->getId());
                        $toUsers = array_merge($toUsers, array($review->get('author')));
                        $logger->info("Change/Module(task.commit): Adding Author to receive an email.");
                    }

                    // notify members, moderators and followers of affected projects via activity and email
                    if ($impacted) {
                        $projects = Project::fetchAll(array(Project::FETCH_BY_IDS => array_keys($impacted)), $p4Admin);
                        foreach ($projects as $projectId => $project) {
                            $members    = $project->getAllMembers();
                            $followers  = $project->getFollowers($members);
                            $branches   = isset($impacted[$projectId]) ? $impacted[$projectId] : array();
                            $moderators = $branches ? $project->getModerators($branches) : null;

                            $activity->addFollowers($members);
                            $activity->addFollowers($moderators);
                            $activity->addFollowers($followers);

                            $changeEmailFlag = $project->getEmailFlag('change_email_project_users');
                            // Legacy projects may not have the flag so null is considered enabled, otherwise
                            // the value stored is '1' or '0' where '1' is enabled
                            if ($changeEmailFlag === null || $changeEmailFlag === '1') {
                                $toUsers = array_merge(
                                    $toUsers,
                                    array(Project::KEY_PREFIX.$project->getId()),
                                    $followers
                                );
                                // Now that groups have notification preferences, we need to email groups moderators too
                                if ($branches) {
                                    $moderatorsAndGroups = $project->getModeratorsWithGroups($branches);
                                    // Build a mailing list of users and groups (prefixed swarm-group-)
                                    $toUsers = array_merge(
                                        $toUsers,
                                        $moderatorsAndGroups['Users'],
                                        array_map(
                                            function ($group) {
                                                return GroupConfig::KEY_PREFIX.Group::getGroupName($group);
                                            },
                                            $moderatorsAndGroups['Groups']
                                        )
                                    );
                                }
                            }
                        }
                    }

                    // notify members of groups the author is a member of (if the group is configured for it)
                    // note we use no cache for fetching groups as it is much faster for this particular query
                    $groups = Group::fetchAll(
                        array(
                            Group::FETCH_BY_USER  => $change->getUser(),
                            Group::FETCH_INDIRECT => true,
                            Group::FETCH_NO_CACHE => true
                        ),
                        $p4Admin
                    );
                    $logger->debug(
                        'Change/Module(task.commit): Authors is in [' . count($groups) . '] groups.'
                    );
                    foreach ($groups as $group) {
                        $sendCommitEmails = $group->getConfig()->getEmailFlag('commits');
                        $logger->debug(
                            'Change/Module(task.commit): Group ' . $group->getId()
                            . ' wants emails(' . ($sendCommitEmails ? "yes" : "no") . ")."
                        );
                        if ($sendCommitEmails) {
                            // Just add the group to the list of recipients, mali/module deals with mailing list stuff
                            $toUsers[] = GroupConfig::KEY_PREFIX . $group->getId();

                            // get all members - using the cache this time as it's fast for this case
                            $members = Group::fetchAllMembers($group->getId(), false, null, null, $p4Admin);
                            $logger->debug(
                                'Change/Module(task.commit): Members are [' . implode(', ', $members) . '],'
                            );
                            $activity->addFollowers($members);
                        }
                    }

                    // if change was renumbered, update 'change' field on related activity records
                    if ($changeId !== $change->getOriginalId()) {
                        $options = array(Activity::FETCH_BY_CHANGE => $change->getOriginalId());
                        foreach (Activity::fetchAll($options, $p4Admin) as $record) {
                            $record->set('change', $changeId)->save();
                        }
                    }

                    $event->setParam('activity', $activity);
                    $logger->debug(
                        'Change/Module(task.commit): to list is [' . implode(', ', $toUsers) . ']'
                    );

                    $event->setParam('mail',  array('toUsers' => $toUsers));
                } catch (\Exception $e) {
                    $logger->err($e);
                }
            },
            200
        );

        // prepare commit notification for the email module
        // we do this quite late (low-priority) - after the activity module
        // processes this task so we can take advantage of prepared activity data
        $events->attach(
            'task.commit',
            function ($event) use ($services, $logger) {
                $p4Admin  = $services->get('p4_admin');
                $config   = $services->get('config');
                $keywords = $services->get('review_keywords');
                $change   = $event->getParam('change');
                $activity = $event->getParam('activity');
                // if no change or no activity, nothing to do
                if (!$change instanceof Change || !$activity instanceof Activity) {
                    return;
                }

                // normalize notifications config
                $notifications  = isset($config[Settings::NOTIFICATIONS]) ? $config[Settings::NOTIFICATIONS] : array();
                $notifications += array(
                    Settings::HONOUR_P4_REVIEWS     => false,
                    Settings::OPT_IN_REVIEW_PATH    => null,
                    Settings::DISABLE_CHANGE_EMAILS => false
                );

                // if sending change emails is disabled, nothing to do
                if ($notifications[Settings::DISABLE_CHANGE_EMAILS]) {
                    // Set the mail to null, so we don't fail with missing mail template.
                    $event->setParam('mail', null);
                    return;
                }

                try {
                    // determine who to send email notifications to:
                    // - start with the users already set up in the prior task (where the activity was created)
                    // - include users subscribed to review files if that option is explicitly enabled in config
                    // - exclude users who don't review the 'opt_in_review_path' (if set)
                    $mail    = $event->getParam('mail');
                    $toUsers = isset($mail['toUsers']) ? $mail['toUsers'] : array();
                    // Keep the original users from the previous task.commit that determined who was interested in
                    // the project
                    $toUsersFromProjectImpact = $toUsers;

                    $reviewPath = $notifications[Settings::OPT_IN_REVIEW_PATH];
                    if ($notifications[Settings::HONOUR_P4_REVIEWS]) {
                        $data    = $p4Admin->run('reviews', array('-c', $change->getId()))->getData();
                        $toUsers = array_merge($toUsers, array_map('current', $data));
                        $logger->debug(
                            'Changes: The users with "Reviews" that match the filepaths for change #' . $change->getId()
                            . ' are [' . str_replace(array("\n", "\r"), '', var_export($data, true)) . ']'
                        );
                    }
                    if ($reviewPath && is_string($reviewPath)) {
                        $data    = $p4Admin->run('reviews', array($reviewPath))->getData();
                        $toUsers = array_intersect($toUsers, array_map('current', $data));
                        $logger->debug(
                            'Changes: The users with "Reviews" that match the filepaths for opt_in_review_path['
                            . $reviewPath . '] are ['
                            . str_replace(array("\n", "\r"), '', var_export($data, true)) . ']'
                        );
                    }
                    // After we have determined interest from honour and opt_in make sure we also include users
                    // interested in commits on the project
                    $toUsers = array_merge($toUsers, $toUsersFromProjectImpact);
                    $logger->debug(
                        'Changes: After processing the reviews commands responses, the toUsers are now ['
                        . str_replace(array("\n", "\r"), '', var_export($toUsers, true)) . '].'
                    );

                    // collapse multiple occurrences of certain characters (e.g. ascii lines) for the subject
                    $subject = preg_replace('/([=_+@#%^*-])\1+/', '\1', $keywords->filter($change->getDescription()));

                    // check if we have affected projects
                    $projects = array_keys(Project::getAffectedByChange($change, $p4Admin));
                    
                    try {
                        // if this change is being committed on behalf of someone else, include them and link the topic
                        $review = $event->getParam('review');
                        if ($review instanceof Review && $review->get('author')) {
                            $toUsers = array_merge($toUsers, array($review->get('author')));
                            $logger->info("Changes: Adding Author to receive an email.");
                        }
                    } catch (\Exception $ee) {
                        $logger->info(
                            "Changes: Couldn't to get review data when trying to determine author."
                            . $ee->getMessage()
                        );
                    }

                    // configure a message for mail module to deliver
                    $mailParams = array(
                        'author'        => $change->getUser(),
                        'subject'       => 'Commit @' . $change->getId() . ' - ' . $subject,
                        'cropSubject'   => 80,
                        'toUsers'       => $toUsers,
                        'fromUser'      => $activity->get('user') ?: $change->getUser(),
                        'messageId'     =>
                            '<topic-changes/' . $change->getId()
                            . '@' . Mail::getInstanceName($config['mail']) . '>',
                        'projects'      => $projects,
                        'htmlTemplate'  => __DIR__ . '/view/mail/commit-html.phtml',
                        'textTemplate'  => __DIR__ . '/view/mail/commit-text.phtml',
                    );
                    // If the commit is for a review, align it with the thread
                    $review = $event->getParam('review');
                    if (null !== $review) {
                        $mailParams['subject']   = 'Review @' . $review->getId() . ' - ' . $subject;
                        $mailParams['inReplyTo'] = '<topic-reviews/'. $review->getId()
                            . '@' . Mail::getInstanceName($config['mail']) . '>';
                    }
                    $event->setParam('mail', $mailParams);
                } catch (\Exception $e) {
                    $logger->err(
                        'Changes: Failed to add users to recipient list using review daemon configurations. '
                        . 'honor_p4_reviews [' . $notifications[Settings::HONOUR_P4_REVIEWS] . '], '
                        . 'opt_in_review_path [' . $reviewPath . '], '
                        . "\n$e"
                    );
                }
            },
            -190
        );

        $events->attach(
            'task.commit',
            function ($event) use ($services, $logger) {
                // This event handler fires after the other task.commit handlers to add reviewers to a mailto list.
                // If we do not add reviewers here and a reviewer is not mentioned or part of the relevant project
                // then they will never be considered for an email.
                $p4Admin = $services->get('p4_admin');
                $change  = $event->getParam('change');
                try {
                    // ignore invalid/pending changes.
                    if (!$change instanceof Change || $change->getStatus() !== 'submitted') {
                        return;
                    }
                    $mail    = $event->getParam('mail');
                    $toUsers = isset($mail['toUsers']) ? $mail['toUsers'] : array();
                    $reviews = Review::fetchAll(array(Review::FETCH_BY_CHANGE => $change->getId()), $p4Admin);
                    foreach ($reviews as $review) {
                        foreach ($review->getParticipants() as $participant) {
                            if (Group::isGroupName($participant) && Group::exists(Group::getGroupName($participant))) {
                                $toUsers[] = GroupConfig::KEY_PREFIX .
                                    Group::fetchById(Group::getGroupName($participant), $p4Admin)->getId();
                            } else {
                                // If there is no valid author or the participant is not the author, add them in.
                                // Authors and handled later in the chain and we only want to deal with reviewers
                                // here
                                $authorId = $review->isValidAuthor() ? $review->getAuthorObject()->getId() : null;
                                if ($authorId === null || $authorId !== $participant) {
                                    $toUsers[] = $participant;
                                }
                            }
                        }
                    }
                    $mail['toUsers'] = array_unique($toUsers);
                    $event->setParam('mail', $mail);
                } catch (\Exception $e) {
                    $logger->err('Changes: Failed to add reviewers to recipient list for task.commit');
                    $logger->err($e);
                }
            },
            -191
        );

        // since the 'changesave' event has a bug that causes it to fire before the change is actually saved,
        // we schedule a future task that gets run after a 5 second delay
        // NOTE: if you want to subscribe to an event that fires when the change has been saved, you will want to
        //       add a task for the 'changesaved' event
        $events->attach(
            'task.changesave',
            function ($event) use ($services, $logger) {
                $delay = ConfigManager::getValue(
                    $services->get('config'),
                    ConfigManager::WORKER_CHANGE_SAVE_DELAY,
                    5000
                ) / 1000;
                $logger->trace('Delay for task.changesaved is ' . $delay);

                // schedule the task in the future to handle the synchronization
                $services->get('queue')->addTask(
                    'changesaved',
                    $event->getParam('id'),
                    $event->getParam('data'),
                    time() + $delay
                );
            },
            200
        );

        // this task actually does the description synchronization work between reviews and changes
        $events->attach(
            'task.changesaved',
            function ($event) use ($services, $logger) {
                $id      = $event->getParam('id');
                $p4Admin = $services->get('p4_admin');
                $config  = $services->get('config');

                // if we are configured to synchronize descriptions,
                // and there is something to synchronize (it's not a new change)
                if (isset($config['reviews']['sync_descriptions'])
                    && $config['reviews']['sync_descriptions'] === true
                    && $id !== 'default'
                ) {
                    try {
                        $change = Change::fetchById($id, $p4Admin);

                        // find any associated reviews with this change, and ensure they are updated
                        $reviews  = Review::fetchAll(array(Review::FETCH_BY_CHANGE => $id), $p4Admin);
                        $keywords = $services->get('review_keywords');
                        foreach ($reviews as $review) {
                            $description = $review->getDescription();
                            if ($description != $change->getDescription()) {
                                $review->setDescription($keywords->filter($change->getDescription()))->save();
                            } else {
                                continue;
                            }

                            // schedule task.review so @mentions get updated
                            $services->get('queue')->addTask(
                                'review',
                                $review->getId(),
                                array(
                                    'previous'            => array('description' => $description),
                                    'isDescriptionChange' => true
                                )
                            );
                        }
                    } catch (\Exception $e) {
                        $logger->err($e);
                    }
                }
            },
            200
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
