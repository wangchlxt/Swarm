<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Mail;

use Activity\Model\Activity;
use Notifications\Settings;
use P4\Spec\Change;
use P4\Spec\Exception\NotFoundException;
use Record\Exception\NotFoundException as RecordNotFoundException;
use Projects\Model\Project;
use Users\Model\User;
use Groups\Model\Group;
use Groups\Model\Config as GroupConfig;
use Zend\Mail\Message;
use Zend\Mime\Message as MimeMessage;
use Zend\Mime\Part as MimePart;
use Zend\Mvc\MvcEvent;
use Zend\Stdlib\StringUtils;
use Zend\Validator\EmailAddress;
use Zend\View\Model\ViewModel;
use Zend\View\Resolver\TemplatePathStack;

class Module
{
    /**
     * Connect to queue events to send email notifications
     *
     * @param   Event   $event  the bootstrap event
     * @return  void
     */
    public function onBootstrap(MvcEvent $event)
    {
        $application = $event->getApplication();
        $services    = $application->getServiceManager();
        $manager     = $services->get('queue');
        $events      = $manager->getEventManager();
        $mailBuilder = $this;

        // send email notifications for task events that prepare mail data.
        // we use a very low priority so that others can influence the message.
        $events->attach(
            '*',
            function ($event) use ($application, $services, $mailBuilder) {
                if ($message = $mailBuilder->buildMessage($event, $services)) {
                    // This event is requesting an email, move on
                    try {
                        $mailer = $services->get('mailer');
                        $mailer->send($message);

                        // in debug mode, report the subject and recipient information
                        $services->get('logger')->debug(
                            'Mail:Email sent. Subject: ' . $message->getSubject(),
                            array(
                                'recipients' => array_keys(
                                    array_map(
                                        function ($address) {
                                            $address->getEmail();
                                        },
                                        array_merge(
                                            iterator_to_array($message->getTo()),
                                            iterator_to_array($message->getBcc())
                                        )
                                    )
                                )
                            )
                        );

                        // if we have the option, disconnect to avoid timeouts
                        // unit tests don't have this method so we have to gate the call
                        if (method_exists($mailer, 'disconnect')) {
                            $mailer->disconnect();
                        }
                    } catch (\Exception $e) {
                        $services->get('logger')->err($e);
                    }
                }
            },
            -200
        );
    }

    public function buildMessage(\Zend\EventManager\Event $event, \Zend\ServiceManager\ServiceManager $services)
    {
        $mail = $event->getParam('mail');
        if (!is_array($mail)) {
            return;
        }
        $logger   = $services->get('logger');
        $activity = $event->getParam('activity');
        // Activity will not be set for batched comments, so consider action to be undetermined
        $mailAction       = $activity ? $activity->get('action') : Settings::UNDETERMINED;
        $restrictByChange = $event->getParam('restrictByChange', $activity ? $activity->get('change') : null);
        $logger->info(
            "Mail:Looking for mail to send with " . $event->getName() . ' ( ' . $event->getParam('id') . ')'
        );

        // ignore 'quiet' events.
        $data  = (array) $event->getParam('data') + array('quiet' => null);
        $quiet = $event->getParam('quiet', $data['quiet']);
        if ($quiet === true || in_array('mail', (array) $quiet)) {
            $logger->info("Mail:Mail event is silent(notifications are being batched), returning.");
            return;
        }
        // normalize and validate message configuration
        $mail += array(
            'author'       => null,
            'to'           => null,
            'toUsers'      => null,
            'review'       => null,
            'subject'      => null,
            'cropSubject'  => false,
            'fromAddress'  => null,
            'fromName'     => null,
            'fromUser'     => null,
            'messageId'    => null,
            'inReplyTo'    => null,
            'htmlTemplate' => null,
            'textTemplate' => null,
            'projects'     => array(),
            'references'   => null
        );
        // Information used to build x-swarm... headers
        $reviewAuthor = null;

        // detect bad templates, clear them (to avoid later errors) and log it
        $invalidTemplates = array();
        foreach (array('htmlTemplate', 'textTemplate') as $templateKey) {
            if ($mail[$templateKey] && !is_readable($mail[$templateKey])) {
                $invalidTemplates[] = $mail[$templateKey];
                $mail[$templateKey] = null;
            }
        }
        if (count($invalidTemplates)) {
            $logger->err(
                'Mail:Invalid mail template(s) specified: ' . implode(', ', $invalidTemplates)
            );
        }

        if (!$mail['htmlTemplate'] && !$mail['textTemplate']) {
            $logger->err("Mail:Cannot send mail. No valid templates specified.");
            // Add more diagnostics for this
            $logger->warn(
                'Mail-Diagnostics: Additional information for support when there are no valid mail templates. '
                . count($invalidTemplates) . " invalid template(s) found. "
                . "Mail parameters [" . str_replace(array("\n", "\r"), '', var_export($mail, true)) . ']'
                . 'There are probably earlier ERR messages relating to constructing the mail data.'
            );
            foreach ($invalidTemplates as $invalidTemplate) {
                $logger->warn(
                    'Mail-Diagnostics: '
                    . "Template " . $invalidTemplate . ( is_readable($invalidTemplate) ? " readable" : " not readable" )
                );
            }
            return;
        }

        // normalize mail configuration, start by ensuring all of the keys are at least present
        $configs = $services->get('config') + array('mail' => array());
        $config  = $configs['mail'] +
            array(
                'sender'         => null,
                'recipients'     => null,
                'subject_prefix' => null,
                'use_bcc'        => null,
                'use_replyto'    => true
            );

        // if we are configured not to email events involving restricted changes
        // and this event has a change to restrict by, dig into the associated change.
        // if the associated change ends up being restricted, bail.
        if ((!isset($configs['security']['email_restricted_changes'])
                || !$configs['security']['email_restricted_changes'])
            && $restrictByChange
        ) {
            // try and re-use the event's change if it has a matching id otherwise do a fetch
            $change = $event->getParam('change');
            if (!$change instanceof Change || $change->getId() != $restrictByChange) {
                try {
                    $change = Change::fetchById($restrictByChange, $services->get('p4_admin'));
                } catch (NotFoundException $e) {
                    // if we cannot fetch the change, we have to assume
                    // it's restricted and bail out of sending email
                    return;
                }
            }

            // if the change is restricted, don't email just bail
            if ($change->getType() == Change::RESTRICTED_CHANGE) {
                return;
            }
        }

        // if sender has no value use the default
        $config['sender'] = $config['sender'] ?: 'notifications@' . $configs['environment']['hostname'];
        $logger->debug("Mail:Using sender of " . $config['sender']);

        // if subject prefix was specified or is an empty string, use it.
        // for unspecified or null subject prefixes we use the default.
        $config['subject_prefix'] = $config['subject_prefix'] || $config['subject_prefix'] === ''
            ? $config['subject_prefix'] : '[Swarm]';

        // as a convenience, listeners may specify to/from as usernames
        // and we will resolve these into the appropriate email addresses.
        $to = (array) $mail['to'];
        $logger->trace(
            "Mail: mailTo list passed in: " . var_export($to, true)
        );
        $toUsers      = array_unique((array) $mail['toUsers']);
        $participants = array();
        $groups       = array();
        $users        = array();
        $seen         = array();
        // This will allow us to work out action roles later
        $expandedFromList = array();
        if (count($toUsers)) {
            $p4Admin = $services->get('p4_admin');

            // Expand users from groups that are not using a mailing list, including project members.
            $logger->debug(
                "Mail: To user list before expansion is [" . implode(", ", $toUsers) . ']. '
            );
            $participants = Module::expandParticipants($toUsers, $p4Admin, $groups, $expandedFromList, $seen, $logger);
            $logger->debug(
                "Mail: Participant list after expansion is [" . implode(", ", $participants) . ']. '
                . "Expansion list is [" . var_export($expandedFromList, true) . "]"
            );

            // Get all of the user objects
            $users = User::fetchAll(
                array(
                    User::FETCH_BY_NAME => array_unique(array_merge($participants, (array) $mail['fromUser']))
                )
            );
        }

        if (is_array($participants)) {
            $logger->trace(
                "Mail: List of Participants is " . var_export($participants, true)
            );

            // Include the configured email validator options.
            $validator = new EmailAddress(
                isset($config['mail']['validator'])
                    ? $config['mail']['validator']['options']
                    : array()
            );

            // make sure that it is ok to send an email to the given recipients
            foreach ($participants as $toUser) {
                // check if this participant is a group
                $isGroup = Group::isGroupName($toUser);
                // Get the participant data
                $participant = $isGroup
                    ? (isset($groups[$toUser]) ? $groups[$toUser] : '')
                    : (isset($users[$toUser]) ? $users[$toUser] : '');
                // If we have participant data move on to checking if they want email.
                if ($participant !== '') {
                    // Closure for getting participant email.
                    $email = function ($isGroup, $participant) {
                        return $isGroup ? $participant->getConfig()->get('emailAddress') : $participant->getEmail();
                    };
                    // Closure for getting participant notification settings.
                    $notifications = function ($isGroup, $participant) {
                        return $isGroup ? $participant->getConfig()->getNotificationSettings()
                            : $participant->getConfig()->getUserNotificationSettings();
                    };
                    // Closure to check if participant wants email or not based on settings.
                    $isMailEnabled = function ($isGroup, $settings, $mailAction, $notificationOptions) {
                        return $isGroup ?
                            $settings->isMailEnabledForGroup($mailAction, $notificationOptions)
                            : $settings->isMailEnabledForUser($mailAction, $notificationOptions);
                    };
                    // Moving the review object out of the getFilterToList
                    $review = $event->getParam('review');
                    if ($review) {
                        if ($review->isValidAuthor()) {
                            $authorUser   = $review->getAuthorObject();
                            $reviewAuthor = $authorUser->getId() . ' (' . $authorUser->getFullName() . ')';
                        } else {
                            // In case author has been deleted
                            $reviewAuthor = $review->getRawValue('author');
                        }
                    }

                    // Now run the email checking process.
                    $to = array_merge(
                        $to,
                        $this->getFilteredToList(
                            $participant,
                            $toUser,
                            $services,
                            $validator,
                            $event,
                            $email,
                            $users,
                            $expandedFromList,
                            $notifications,
                            $isMailEnabled,
                            $review,
                            $isGroup ? 'Group' : 'User'
                        )
                    );
                    $logger->trace(
                        "Mail: The new merged To list: " . var_export($to, true)
                    );
                }
            }
        }
        if (isset($users[$mail['fromUser']])) {
            $fromUser            = $users[$mail['fromUser']];
            $mail['fromAddress'] = $fromUser->getEmail()    ?: $mail['fromAddress'];
            $mail['fromName']    = $fromUser->getFullName() ?: $mail['fromName'];
        }

        // remove any duplicate or empty recipient addresses
        $to = array_unique(array_filter($to, 'strlen'));

        // if we don't have any recipients, nothing more to do
        if (!$to && !$config['recipients']) {
            $logger->warn("Mail:Not sending email to address list and config['recipients'] are empty");
            return;
        }

        // if explicit recipients have been configured (e.g. for testing),
        // log the computed list of recipients for debug purposes.
        if ($config['recipients']) {
            $logger->debug('Mail:Mail recipients: ' . implode(', ', $to));
        }

        // prepare view for rendering message template
        // customize view resolver to only look for the specific
        // templates we've been given (note we cloned view, so it's ok)
        $renderer = clone $services->get('ViewManager')->getRenderer();
        $resolver = new TemplatePathStack;
        $resolver->addPaths(array(dirname($mail['htmlTemplate']), dirname($mail['textTemplate'])));
        $renderer->setResolver($resolver);
        $viewModel = new ViewModel(
            array(
                'services'  => $services,
                'event'     => $event,
                'activity'  => $activity
            )
        );

        // message has up to two parts (html and plain-text)
        $parts = array();
        if ($mail['textTemplate']) {
            $viewModel->setTemplate(basename($mail['textTemplate']));
            $text       = new MimePart($renderer->render($viewModel));
            $text->type = 'text/plain; charset=UTF-8';
            $parts[]    = $text;
        }
        if ($mail['htmlTemplate']) {
            $viewModel->setTemplate(basename($mail['htmlTemplate']));
            $html       = new MimePart($renderer->render($viewModel));
            $html->type = 'text/html; charset=UTF-8';
            $parts[]    = $html;
        }

        // prepare subject by applying prefix, collapsing whitespace,
        // trimming whitespace or dashes and optionally cropping
        $subject = $config['subject_prefix'] . ' ' . $mail['subject'];
        if ($mail['cropSubject']) {
            $utility  = StringUtils::getWrapper();
            $length   = strlen($subject);
            $subject  = $utility->substr($subject, 0, (int) $mail['cropSubject']);
            $subject  = trim($subject, "- \t\n\r\0\x0B");
            $subject .= strlen($subject) < $length ? '...' : '';
        }
        $subject = preg_replace('/\s+/', " ", $subject);
        $subject = trim($subject, "- \t\n\r\0\x0B");

        // Allow thread indexing to be disabled via the mail config
        $threadIndex = null;
        if (!isset($config['index-conversations']) || $config['index-conversations']) {
            // prepare thread-index header for outlook/exchange
            // - thread-index is 6-bytes of FILETIME followed by a 16-byte GUID
            // - time can vary between messages in a thread, but the GUID can't
            // - current time in FILETIME format is the number of 100 nanosecond
            //   intervals since the win32 epoch (January 1, 1601 UTC)
            // - GUID is inReplyTo header(or message id for a new thread) md5'd and packed into 16 bytes
            // - the time and GUID are then combined and base-64 encoded
            $fileTime = (time() + 11644473600) * 10000000;
            // Nn = unsigned long, unsigned short, big endian
            $fileTime = pack('Nn', $fileTime >> 32, $fileTime >> 16);
            // H* = hex string, high nibble first, all chars
            $guid        = pack('H*', md5($mail['inReplyTo'] ?: ($mail['messageId'])));
            $threadIndex = base64_encode($fileTime . $guid);
            $logger->debug(
                "Mail: file time[" . bin2hex($fileTime) . "] inReplyTo[" . $mail['inReplyTo']
                . "] messageID[" . $mail['messageId'] . "] md5[" . md5($mail['inReplyTo'] ?: ($mail['messageId']))
                . "] guid[" . bin2Hex($guid) . "], index[" . $threadIndex . "]"
            );
        }
        // build the mail message
        $body = new MimeMessage();
        $body->setParts($parts);
        $message    = new Message();
        $recipients = $config['recipients'] ?: $to;
        if ($config['use_bcc']) {
            $message->setTo($config['sender'], 'Unspecified Recipients');
            $message->addBcc($recipients);
        } else {
            $message->addTo($recipients);
        }
        $message->setSubject($subject);
        $message->setFrom($config['sender'], $mail['fromName']);
        if ($config['use_replyto']) {
            $message->addReplyTo($mail['fromAddress'] ?: $config['sender'], $mail['fromName']);
        } else {
            $message->addReplyTo('noreply@' . $configs['environment']['hostname'], 'No Reply');
        }
        $message->setBody($body);
        $message->setEncoding('UTF-8');
        $message->getHeaders()->addHeaders(
            array_filter(
                array(
                    'Message-ID'            => $mail['messageId'],
                    'In-Reply-To'           => $mail['inReplyTo'],
                    'References'            => $mail['references'],
                    'Thread-Index'          => $threadIndex,
                    'Thread-Topic'          => $subject,
                    'X-Swarm-Project'       => implode(",", $mail['projects']),
                    'X-Swarm-Host'          => $configs['environment']['hostname'],
                    'X-Swarm-Version'       => VERSION,
                    'X-Swarm-Review-Id'     => isset($review) && $review ? $review->getId() : null,
                    'X-Swarm-Review-Author' => $reviewAuthor,
                )
            )
        );
        // set alternative multi-part if we have both html and text templates
        // so that the client knows to show one or the other, not both
        if ($mail['htmlTemplate'] && $mail['textTemplate']) {
            $message->getHeaders()->get('content-type')->setType('multipart/alternative');
        }

        return $message;
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

    public static function getActionRoles(
        $logger,
        $mailAction,
        $review,
        $participant,
        $expandedFromList,
        User $activityUser,
        $author,
        $type,
        Project $project = null
    ) {
        // List of roles that relate this user to the activity being notified
        $roleOptions   = array();
        $participantID = $type === 'Group' ? GroupConfig::KEY_PREFIX.$participant->getId() : $participant->getId();
        $logger->trace(
            'Mail:getActionRoles: participantId [' . $participantID . ']. '
            . 'Expanded[' . (isset($expandedFromList[$participantID]) ? $expandedFromList[$participantID] : '') . ']'
        );

        // Firstly deal with things outside the scope of projects and actions
        // Is the email going to a follower of the user that carried out the action?
        if ($activityUser->getConfig()->isFollower($participantID)) {
            $roleOptions[] = Settings::IS_FOLLOWER; // Should probably introduce IS_USER_FOLLOWER as a new role
        }

        // Set is_self if the activity user and current user are the same.
        if ($activityUser->getId() === $participantID) {
            $roleOptions[] = Settings::IS_SELF;
        }

        // Is the recipient the author of the item which triggered the notification?
        if ($review && $review->isValidAuthor() && $review->getAuthorObject()->getId() == $participantID) {
            $roleOptions[] = MailAction::COMMENT_LIKED === $mailAction
                ? Settings::IS_COMMENTER
                : Settings::IS_AUTHOR;
        } elseif ($author === $participantID) {
            // As comment_liked only has role of is_commenter we don't need to assign author as well, as this could
            // enable emails when is_commenter is disabled.
            $roleOptions[] = MailAction::COMMENT_LIKED === $mailAction
                ? Settings::IS_COMMENTER
                : Settings::IS_AUTHOR;
        }

        // Unpack the project data
        $members    = array();
        $moderators = array();
        if ($project) {
            $members    = $project->getUsersAndSubgroups();
            $moderators = $project->getModeratorsWithGroups();
            $logger->trace(
                'Mail:getActionRoles: '.$project->getId().' Members [' . var_export($members, true)
                . '] Project Moderators [' . var_export($moderators, true)
                . ']'
            );
            // Is this user a follower of this project
            if ($project && $project->isFollowing($participantID)) {
                $roleOptions[] = Settings::IS_FOLLOWER;
            }
        }

        // Next deal with things that relate to the action being carried out
        switch ($mailAction) {
            // Comment liked action
            case MailAction::COMMENT_LIKED:
                break;
            // Comment related actions.
            case MailAction::COMMENT_ADDED:
            case MailAction::COMMENT_EDITED:
                // Reviewer
                $reviewers = $review ? $review->getReviewers() : array();
                $logger->trace('Mail:getActionRoles: reviewers [' . implode(", ", $reviewers) . ']');
                if ($review && in_array($participantID, $reviewers)) {
                    $roleOptions[] = Settings::IS_REVIEWER;
                } elseif (isset($expandedFromList[$participantID])) {
                    // This participant was expanded from a group (or a project)
                    $inheritedId = $expandedFromList[$participantID];
                    $logger->trace(
                        'Mail:getActionRoles: participantId [' . $participantID
                        . '], inherited from [' . $inheritedId . '] '
                        . ( Group::isGroupName($inheritedId)
                            ? "Group in reviewers list(".(in_array($inheritedId, $reviewers) ? "true" : "false").")"
                            : "not a group" )
                    );
                    if (Group::isGroupName($inheritedId) && in_array($inheritedId, $reviewers)) {
                        // The original group was a reviewer, so I must be
                        $roleOptions[] = Settings::IS_REVIEWER;
                    }
                }
                break;
            // Review related actions
            case MailAction::REVIEW_APPROVED:
            case MailAction::REVIEW_ARCHIVED:
            case MailAction::REVIEW_REQUESTED:
            case MailAction::REVIEW_REJECTED:
            case MailAction::REVIEW_NEEDS_REVIEW:
            case MailAction::REVIEW_NEEDS_REVISION:
            case MailAction::REVIEW_UPDATED_FILES:
            case MailAction::REVIEW_VOTED_UP:
            case MailAction::REVIEW_VOTED_DOWN:
            case MailAction::REVIEW_CLEARED_VOTE:
            case MailAction::REVIEW_JOINED:
            case MailAction::REVIEW_LEFT:
            case MailAction::REVIEW_TESTS:
            case MailAction::CHANGE_COMMITTED:
            case MailAction::REVIEW_OPENED_ISSUE:
            case MailAction::REVIEW_MAKE_REQUIRED_VOTE:
            case MailAction::REVIEW_MAKE_OPTIONAL_VOTE:
            case MailAction::REVIEW_EDITED_REVIEWERS:
                // Moderator of the project
                $logger->trace(
                    'Mail:getActionRoles: moderators users ['
                    . implode(", ", isset($moderators['Users'])?$moderators['Users']:array()) . '], '
                    . ' groups [' . implode(", ", isset($moderators['Groups'])?$moderators['Groups']:array()) . ']'
                );
                $logger->trace(
                    'Mail:getActionRoles: Moderator role checking ['.$participantID.'] is in Expanded list ['
                    . (isset($expandedFromList[$participantID]) ? 'true' : 'false') . '] | Are there moderator Groups ['
                    . (isset($moderators['Groups']) ? 'true' : 'false') . '] | is a Group ['
                    . (Group::isGroupName($participantID) ? 'true' : 'false') . ']'
                );
                if (in_array($participantID, isset($moderators['Users'])?$moderators['Users']:array())) {
                    $roleOptions[] = Settings::IS_MODERATOR;
                } elseif (isset($expandedFromList[$participantID]) && isset($moderators['Groups'])) {
                    // This participant was expanded from a group (or a project)
                    $inheritedId = Module::getInheritedId($participantID, $expandedFromList, $moderators['Groups']);
                    $logger->trace(
                        'Mail:getActionRoles: participantId [' . $participantID
                        . '], inherited from [' . $inheritedId . '] '
                        . ( Group::isGroupName($inheritedId)
                            ? "Group in moderators list("
                                . (in_array($inheritedId, $moderators['Groups']) ? "true" : "false") . ")"
                            : "not a group" )
                    );
                    if (Group::isGroupName($inheritedId) && in_array($inheritedId, $moderators['Groups'])) {
                        // The original group was a moderator, so I must be
                        $roleOptions[] = Settings::IS_MODERATOR;
                    } elseif (Group::isGroupName($participantID)
                        && in_array(Group::getGroupName($participantID), $moderators['Groups'])) {
                        // If a group has been inherited from a project, check that the group is not in the
                        // moderators group list
                        $roleOptions[] = Settings::IS_MODERATOR;
                    }
                } elseif (Group::isGroupName($participantID)
                    && in_array(
                        Group::getGroupName($participantID),
                        isset($moderators['Groups'])?$moderators['Groups']:array()
                    )
                ) {
                    $roleOptions[] = Settings::IS_MODERATOR;
                }
                // Reviewer
                $reviewers = $review ? $review->getReviewers() : array();
                if ($review && in_array($participantID, $reviewers)) {
                    $roleOptions[] = Settings::IS_REVIEWER;
                } elseif (isset($expandedFromList[$participantID])) {
                    // This participant was expanded from a group (or a project)
                    $inheritedId = Module::getInheritedId($participantID, $expandedFromList, $reviewers);
                    if (Group::isGroupName($inheritedId) && in_array($inheritedId, $reviewers)) {
                        // The original group was a reviewer, so I must be
                        $roleOptions[] = Settings::IS_REVIEWER;
                    }
                }
                // Member of the project
                if (in_array($participant->getId(), isset($members['Users'])?$members['Users']:array())) {
                    // We don't want is member for tests, new tasks and vote change.
                    if ($mailAction != MailAction::REVIEW_TESTS ||
                        $mailAction != MailAction::REVIEW_OPENED_ISSUE ||
                        $mailAction != MailAction::REVIEW_MAKE_REQUIRED_VOTE ||
                        $mailAction != MailAction::REVIEW_MAKE_OPTIONAL_VOTE
                    ) {
                        $roleOptions[] = Settings::IS_MEMBER;
                    }
                } elseif (isset($expandedFromList[$participantID]) && isset($members['Groups'])) {
                    // This participant was expanded from a group (or a project)
                    $inheritedId = Module::getInheritedId($participantID, $expandedFromList, $members['Groups']);
                    if (in_array(Group::getGroupName($inheritedId), $members['Groups']) ||
                        strpos($inheritedId, Project::KEY_PREFIX) !== false) {
                        // The original group was a member, so I must be
                        $roleOptions[] = Settings::IS_MEMBER;
                    } elseif (Group::isGroupName($participantID)
                        && in_array(Group::getGroupName($participantID), $members['Groups'])) {
                        // If a group has been inherited from a project, check that the group is not in the
                        // members group list
                        $roleOptions[] = Settings::IS_MEMBER;
                    }
                } elseif (Group::isGroupName($participantID)
                    && in_array(
                        Group::getGroupName($participantID),
                        isset($members['Groups'])?$members['Groups']:array()
                    )
                ) {
                    $roleOptions[] = Settings::IS_MEMBER;
                }
                // If someone leaves or is removed from a review they don't have a role.
                // Due to this assign them the is_reviewer role as they where a reviewer
                // before being removed.
                if ($mailAction === MailAction::REVIEW_LEFT && empty($roleOptions)) {
                    $roleOptions[] = Settings::IS_REVIEWER;
                }
                break;
            default:
                // Other actions, as roles are used as a filter, no value means send anyway
                break;
        }
        return $roleOptions;
    }

    /**
     * Flatten an array into a printable string, for debug
     * @param $name    - the name of the property
     * @param $printMe - the value to print, which may be an array
     * @return string  - a bracketed string representation of the value
     */
    private function arrayDebugAsString($name, $printMe)
    {
        if (is_array($printMe)) {
            $result = "[$name";
            foreach ($printMe as $key => $value) {
                $result .= $this->arrayDebugAsString($key.":", $value);
            }
            $result .= "]";
        } else {
            $result = "[$name$printMe]";
        }
        return $result;
    }

    /**
     * Combine the configured instanve name with the server id for multiserver setup
     * @param $mailConfig
     * @return mixed
     */
    public static function getInstanceName($mailConfig)
    {
        return $mailConfig['instance_name'] . (null === P4_SERVER_ID ? '' :('-'.P4_SERVER_ID));
    }

    /**
     * Analyse the to list for an email and expand it using the rules:
     *
     *  - projects, get the immediate members (users and groups) and treat these as standard groups/users
     *    with a role of IS_MEMBER
     *  - groups, if the group is _not_ using a mailing list expand all users with a role of reviewer unless the group
     *    was derived from a project
     *  - users, simple case just add the to the list
     *
     * @param $toUsers            - the original toUser array
     * @param $p4Admin            -  a p4 connection
     * @param array $groups       - somewhere to stash group objects
     * @param array $expansionMap - map expanded users to parent
     * @param array $seen         - short term memory to prevent recursion
     * @param $logger             - the logger
     * @return array the list of direct recipients
     */
    public static function expandParticipants(
        $toUsers,
        $p4Admin,
        &$groups = array(),
        &$expansionMap = array(),
        &$seen = array(),
        $logger = null
    ) {
        $participants = array();

        $expandedUserMap = array_map(
            function ($participant) use ($p4Admin, &$groups, &$expansionMap, &$seen, $logger) {
            // Am I a project?
                if (Project::isProjectName($participant) && !in_array($participant, $seen)) {
                    // Seen this one now, don't forget
                    $seen[] = $participant;
                    // Get the immediate project members
                    $projectName = Project::getProjectName($participant);
                    try {
                        $projectUsersAndSubgroups = Project::fetch(
                            $projectName,
                            $p4Admin
                        )->getUsersAndSubgroups();
                        // Flatten the Users/Groups into a list of Mail/Module friendly id values
                        $projectMembers = array_merge(
                            $projectUsersAndSubgroups['Users'],
                            array_map(
                                function ($memberId) {
                                    // Members of a project could potentially be another project
                                    if (Project::isProjectName($memberId)) {
                                        return $memberId;
                                    } else {
                                        return GroupConfig::KEY_PREFIX . $memberId;
                                    }
                                },
                                $projectUsersAndSubgroups['Groups']
                            )
                        );
                        // Remember that these were project members
                        $expansionMap += array_merge($expansionMap, array_fill_keys($projectMembers, $participant));
                        // Now expand the immediate participants
                        return Module::expandParticipants(
                            $projectMembers,
                            $p4Admin,
                            $groups,
                            $expansionMap,
                            $seen,
                            $logger
                        );
                    } catch (RecordNotFoundException $e) {
                        if ($logger) {
                            $logger->warn("Project: $projectName was not found, notifications will not be sent.");
                        }
                    }
                }
                $id = Group::getGroupName($participant);
                if (Group::isGroupName($participant) && Group::exists($id)) {
                    $group = Group::fetchById($id, $p4Admin);
                    // Remember the group object for later
                    $groups[$participant] = $group;
                    if (!$group->getConfig()->get('useMailingList')) {
                        $groupMembers    = $group->fetchUsersAndSubgroups($id);
                        $subGroupMembers = array();
                        // Seen this one now, don't forget
                        $seen[] = GroupConfig::KEY_PREFIX.$id;
                        foreach ($groupMembers['Groups'] as $subgroup) {
                            $member = $subgroup;
                            if (!Project::isProjectName($member)) {
                                $member = GroupConfig::KEY_PREFIX.$subgroup;
                            }
                            if (!in_array($member, $seen)) {
                                // Remember that these were group members
                                $expansionMap[$member] = $participant;
                                // Map the subgroups separately
                                $subGroupMembers[] = Module::expandParticipants(
                                    array($member),
                                    $p4Admin,
                                    $groups,
                                    $expansionMap,
                                    $seen
                                );
                            }
                        }
                        // Remember that these were group members
                        $expansionMap += array_fill_keys($groupMembers['Users'], $participant);
                        return array_merge($groupMembers['Users'], $subGroupMembers);
                    }
                }
                return $participant;
            },
            $toUsers
        );
        // Now flatten any subgroup expansions into actual id values
        array_walk_recursive(
            $expandedUserMap,
            function ($v, $k) use (&$participants) {
                $participants[] = $v;
            }
        );
        return array_unique($participants);
    }

    /**
     * Traverse the inherited id map to follow a child back up to its ulitmate parent, which will be the id
     * that actually performed a role in an activity.
     * @param $participantId
     * @param $expandedFromList
     * @param $actors           This will be Reviewers Moderators or Members
     * @return mixed
     */
    public static function getInheritedId($participantId, $expandedFromList, $actors = array())
    {
        $inheritedId = $participantId;
        while (isset($expandedFromList[$inheritedId]) && !in_array($inheritedId, $actors)) {
            $actors[]    = $inheritedId;
            $inheritedId = $expandedFromList[$inheritedId];
        }
        return $inheritedId;
    }

    /**
     * Apply the preference matrix to an email address returning a, possible empty, array depending upon
     * whether the email address is an appropriate candidate for this email.
     *
     * @param $participant
     * @param $toUser
     * @param $services
     * @param $validator
     * @param $event
     * @param $email
     * @param $users
     * @param $notifications
     * @param $isMailEnabled
     * @param $review
     * @param string $type
     * @return array
     */
    private function getFilteredToList(
        $participant,
        $toUser,
        $services,
        $validator,
        $event,
        $email,
        $users,
        $expandedFromList,
        $notifications,
        $isMailEnabled,
        $review,
        $type = 'User'
    ) {
        $to         = array();
        $isGroup    = Group::isGroupName($toUser);
        $mail       = $event->getParam('mail');
        $activity   = $event->getParam('activity');
        $logger     = $services->get('logger');
        $configs    = $services->get('config') + array('mail' => array());
        $mailAction = $activity ? $activity->get('action') : Settings::UNDETERMINED;

        // Activity will not be set for batched comments, so consider the from to be the initiator/author
        $activityUserId = $activity && $activity->get('user')
            ? $activity->get('user')
            : (isset($mail['fromUser'])
                ? $mail['fromUser']
                : $mail['author']);

        // Protect against not being able to find a user
        $activityUser = isset($users[$activityUserId])
            ? $users[$activityUserId] : new \Users\Model\User();
        $toEmail      = call_user_func_array($email, array($isGroup, $participant));
        if ($validator->isValid($toEmail)) {
            // Email address is in an acceptable format, check notification settings
            $logger->debug("Mail: $toEmail is formatted correctly, checking settings");
            $wantsEmail  = false;
            $projectList = isset($mail['projects']) ? $mail['projects'] : array();
            $logger->debug("Mail: Projects attribute set to " . implode(', ', $projectList));
            $settings           = new Settings($configs, $logger);
            $participantOptions = call_user_func_array($notifications, array($isGroup, $participant));
            $logger->debug(
                "Mail: $type preferences are set to "
                . $this->arrayDebugAsString("", $participantOptions)
            );
            $settingsOptions = $type === 'User' ? Settings::USER_OPTION : Settings::GROUP_OPTION;

            if (count($projectList) > 0) {
                // There are projects, so we need to take membership into account
                $projects = Project::fetchAll(
                    array(Project::FETCH_BY_IDS => $projectList),
                    $services->get('p4_admin')
                );
                // Iterate through the projects, checking preferences for each one
                foreach ($projects as $project) {
                    $logger->debug(
                        "Mail: Checking whether $toUser(" . $participant->getId() . ') wants an email for '
                        . 'project[' . $project->getName() . '], '
                        . ($review ? ('review[' . $review->getId() . '], ') : '')
                        . 'action[' . $mailAction . '/' . $activityUser->getId() . '], '
                        . 'author['. $mail['author'] . ']'
                    );
                    $notificationOptions = array(
                        $settingsOptions => $participantOptions,
                        Settings::ROLES_OPTION   => Module::getActionRoles(
                            $logger,
                            $mailAction,
                            $review,
                            $participant,
                            $expandedFromList,
                            $activityUser,
                            $mail['author'],
                            $type,
                            $project
                        ),
                        Settings::PROJECT_OPTION => $project
                    );
                    $logger->debug(
                        "Mail: Options are $type"
                        . $this->arrayDebugAsString("", $participantOptions) . ", "
                        . "Roles[" . implode(", ", $notificationOptions[Settings::ROLES_OPTION]) . "], "
                        . "Project[" . $notificationOptions[Settings::PROJECT_OPTION]->getName() . "]"
                    );

                    if ($wantsEmail = true ===
                        call_user_func_array(
                            $isMailEnabled,
                            array(
                                $isGroup,
                                $settings,
                                $mailAction,
                                $notificationOptions
                            )
                        )
                    ) {
                        // Once we know that an email is being sent, processing can move on
                        $logger->debug(
                            "Mail: Found an enabled combination for $toUser, "
                            . $mailAction . " and "
                            . $project->getName() .", moving on"
                        );
                        break;
                    }
                }
            } else {
                // No projects, only check user settings
                $logger->debug(
                    "Mail: Message has no project scope, checking whether "
                    . "$toUser (" . $participant->getId() . ') wants an email for '
                    . ($review ? ('review[' . $review->getId() . '], ') : '')
                    . 'action[' . $mailAction . '/' . $activityUser->getId() . '], '
                    . 'author['. $mail['author'] . ']'
                );
                $notificationOptions = array(
                    $settingsOptions => $participantOptions,
                    Settings::ROLES_OPTION   => Module::getActionRoles(
                        $logger,
                        $mailAction,
                        $event->getParam('review'),
                        $participant,
                        $expandedFromList,
                        $activityUser,
                        $mail['author'],
                        $type
                    )
                );

                $logger->debug(
                    "Mail: Options are User["
                    . $this->arrayDebugAsString("", $participantOptions) . "], "
                    . "Roles[" . implode(", ", $notificationOptions[Settings::ROLES_OPTION]) . "]"
                );

                $wantsEmail = true ===
                    call_user_func_array(
                        $isMailEnabled,
                        array(
                            $isGroup,
                            $settings,
                            $mailAction,
                            $notificationOptions
                        )
                    );
            }

            if ($wantsEmail) {
                // Add the participant if when combination was true
                $logger->debug("Mail: $toUser does want an email for " . $mailAction . ".");
                $to[] = $toEmail;
            } else {
                $logger->debug("Mail: $toUser does not want this email.");
            }
        } else {
            $logger->warn(
                "Mail: Email cannot be sent to $toEmail : " .
                implode(".", $validator->getMessages())
            );
        }

        return $to;
    }
}
