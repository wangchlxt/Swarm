<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Users\View\Helper;

use Zend\View\Helper\AbstractHelper;
use Notifications\Settings;

class NotificationSettings extends AbstractHelper
{
    const TITLE = "title";
    const ID    = "id";

    const IS_SELF_REVIEW_STATE     = 'I change the state of a review';
    const REVIEW_NEW               = 'a review is requested';
    const REVIEW_FILES             = 'files in the review are updated';
    const REVIEW_TESTS             = 'tests on the review have finished';
    const REVIEW_VOTE              = 'a vote is cast on a review';
    const REVIEW_STATE             = 'the state of the review changes';
    const REVIEW_JOIN_LEAVE        = 'someone joins or leaves the review';
    const REVIEW_COMMENT_NEW       = 'a comment is made on the review';
    const REVIEW_COMMENT_LIKED     = 'Someone likes one of my comments';
    const REVIEW_COMMENT_UPDATE    = 'a comment on the review is updated';
    const REVIEW_CHANGELIST_COMMIT = 'a review or change is committed';

    const IS_SELF_TITLE      = 'I cause the action, and';
    const IS_AUTHOR_TITLE    = 'I am the author, and';
    const IS_MEMBER_TITLE    = 'I am a member, and';
    const IS_REVIEWER_TITLE  = 'I am a reviewer, and';
    const IS_MODERATOR_TITLE = 'I am a moderator, and';

    /**
     * This is not ideal. In order for values to be picked up for .po generation strings
     * must be specifically mentioned with a call to a function that returns a string
     * (even though it will actually never be called).
     * We are forced to repeat the values for consts above.
     */
    private static function msgIds()
    {
        NotificationSettings::t('a review is requested');
        NotificationSettings::t('I change the state of a review');
        NotificationSettings::t('a review is requested');
        NotificationSettings::t('files in the review are updated');
        NotificationSettings::t('tests on the review have finished');
        NotificationSettings::t('a vote is cast on a review');
        NotificationSettings::t('the state of the review changes');
        NotificationSettings::t('someone joins or leaves the review');
        NotificationSettings::t('a comment is made on the review');
        NotificationSettings::t('Someone likes one of my comments');
        NotificationSettings::t('a comment on the review is updated');
        NotificationSettings::t('a review or change is committed');
        NotificationSettings::t('I cause the action, and');
        NotificationSettings::t('I am the author, and');
        NotificationSettings::t('I am a member, and');
        NotificationSettings::t('I am a reviewer, and');
        NotificationSettings::t('I am a moderator, and');
    }

    /**
     * Dummy translation.
     * @param $value
     * @return mixed
     */
    private static function t($value)
    {
        return $value;
    }

    // These are the settings to be displayed on user page.
    // If you add additional notification setting here ensure
    // you add them to userSettings as well.
    public static $userSettingsDisplay = array(
        Settings::IS_SELF => array (
            'settings'  => array (
                array (
                    self::ID    => Settings::REVIEW_STATE,
                    self::TITLE => self::IS_SELF_REVIEW_STATE,
                )
            )
        ),

        Settings::IS_AUTHOR => array (
            self::TITLE => self::IS_AUTHOR_TITLE,
            'settings'  => array (
                array (
                    self::ID    => Settings::REVIEW_NEW,
                    self::TITLE => self::REVIEW_NEW,
                ),
                array (
                    self::ID    => Settings::REVIEW_FILES,
                    self::TITLE => self::REVIEW_FILES,
                ),
                array (
                    self::ID    => Settings::REVIEW_TESTS,
                    self::TITLE => self::REVIEW_TESTS,
                ),
                array (
                    self::ID    => Settings::REVIEW_VOTE,
                    self::TITLE => self::REVIEW_VOTE,
                ),
                array (
                    self::ID    => Settings::REVIEW_STATE,
                    self::TITLE => self::REVIEW_STATE,
                ),
                array (
                    self::ID    => Settings::REVIEW_CHANGELIST_COMMIT,
                    self::TITLE => self::REVIEW_CHANGELIST_COMMIT,
                ),
                array (
                    self::ID    => Settings::REVIEW_COMMENT_NEW,
                    self::TITLE => self::REVIEW_COMMENT_NEW,
                ),
                array (
                    self::ID    => Settings::REVIEW_COMMENT_UPDATE,
                    self::TITLE => self::REVIEW_COMMENT_UPDATE,
                ),
            )
        ),
        Settings::IS_COMMENTER => array (
            'settings'  => array (
                array (
                    self::ID    => Settings::REVIEW_COMMENT_LIKED,
                    self::TITLE => self::REVIEW_COMMENT_LIKED,
                ),
            )
        ),

        Settings::IS_MEMBER => array (
            self::TITLE => self::IS_MEMBER_TITLE,
            'settings'  => array (
                array (
                    self::ID    => Settings::REVIEW_NEW,
                    self::TITLE => self::REVIEW_NEW,
                ),
                array (
                    self::ID    => Settings::REVIEW_CHANGELIST_COMMIT,
                    self::TITLE => self::REVIEW_CHANGELIST_COMMIT,
                ),
            )
        ),

        Settings::IS_REVIEWER => array (
            self::TITLE => self::IS_REVIEWER_TITLE,
            'settings' => array (
                array (
                    self::ID    => Settings::REVIEW_FILES,
                    self::TITLE => self::REVIEW_FILES,
                ),
                array (
                    self::ID    => Settings::REVIEW_TESTS,
                    self::TITLE => self::REVIEW_TESTS,
                ),
                array (
                    self::ID    => Settings::REVIEW_VOTE,
                    self::TITLE => self::REVIEW_VOTE,
                ),
                array (
                    self::ID    => Settings::REVIEW_STATE,
                    self::TITLE => self::REVIEW_STATE,
                ),
                array (
                    self::ID    => Settings::REVIEW_JOIN_LEAVE,
                    self::TITLE => self::REVIEW_JOIN_LEAVE,
                ),
                array (
                    self::ID    => Settings::REVIEW_CHANGELIST_COMMIT,
                    self::TITLE => self::REVIEW_CHANGELIST_COMMIT,
                ),
                array (
                    self::ID    => Settings::REVIEW_COMMENT_NEW,
                    self::TITLE => self::REVIEW_COMMENT_NEW,
                ),
                array (
                    self::ID    => Settings::REVIEW_COMMENT_UPDATE,
                    self::TITLE => self::REVIEW_COMMENT_UPDATE,
                )
            )
        ),

        Settings::IS_MODERATOR => array (
            self::TITLE => self::IS_MODERATOR_TITLE,
            'settings'  => array (
                array (
                    self::ID    => Settings::REVIEW_FILES,
                    self::TITLE => self::REVIEW_FILES,
                ),
                array (
                    self::ID    => Settings::REVIEW_TESTS,
                    self::TITLE => self::REVIEW_TESTS,
                ),
                array (
                    self::ID    => Settings::REVIEW_CHANGELIST_COMMIT,
                    self::TITLE => self::REVIEW_CHANGELIST_COMMIT,
                ),
            )
        ),
        // Is_Followers currently not used.
        /*Settings::IS_FOLLOWER => array (
            self::TITLE => 'I\'m a "Follower", notify me when: ',
            'settings'  => array (
                array (
                    self::ID    => Settings::REVIEW_FILES,
                    self::TITLE => self::REVIEW_FILES,
                ),
            )
        ),*/
    );

    // This array is required for the settings to be built and
    // passed to helper.
    public static $userSettings = array(
        Settings::REVIEW_NEW => array (
            'settings'  => array (
                array (
                    self::ID    => Settings::IS_AUTHOR,
                ),
                array (
                    self::ID    => Settings::IS_MEMBER,
                )
            )
        ),
        Settings::REVIEW_COMMENT_NEW => array (
            'settings'  => array (
                array (
                    self::ID    => Settings::IS_AUTHOR,
                ),
                array (
                    self::ID    => Settings::IS_REVIEWER,
                ),
            )
        ),
        Settings::REVIEW_COMMENT_UPDATE => array (
            'settings'  => array (
                array (
                    self::ID    => Settings::IS_AUTHOR,
                ),
                array (
                    self::ID    => Settings::IS_REVIEWER,
                ),
            )
        ),
        Settings::REVIEW_COMMENT_LIKED => array (
            'settings' => array (
                array (
                    self::ID    => Settings::IS_COMMENTER,
                )
            )
        ),
        Settings::REVIEW_FILES => array (
            'settings'  => array (
                array (
                    self::ID    => Settings::IS_AUTHOR,
                ),
                array (
                    self::ID    => Settings::IS_REVIEWER,
                ),
                array (
                    self::ID    => Settings::IS_MODERATOR,
                ),
            )
        ),
        Settings::REVIEW_TESTS => array (
            'settings'  => array (
                array (
                    self::ID    => Settings::IS_AUTHOR,
                ),
                array (
                    self::ID    => Settings::IS_REVIEWER,
                ),
                array (
                    self::ID    => Settings::IS_MODERATOR,
                )

            )
        ),
        Settings::REVIEW_VOTE => array (
            'settings'  => array (
                array (
                    self::ID    => Settings::IS_AUTHOR,
                ),
                array (
                    self::ID    => Settings::IS_REVIEWER,
                )
            )
        ),
        Settings::REVIEW_STATE => array (
            'settings'  => array (
                array (
                    self::ID    => Settings::IS_SELF,
                ),
                array (
                    self::ID    => Settings::IS_AUTHOR,
                ),
                array (
                    self::ID    => Settings::IS_REVIEWER,
                )
            )
        ),
        Settings::REVIEW_JOIN_LEAVE => array (
            'settings' => array (
                array (
                    self::ID    => Settings::IS_REVIEWER,
                )
            )
        ),
        Settings::REVIEW_CHANGELIST_COMMIT => array (
            'settings'  => array (
                array (
                    self::ID    => Settings::IS_AUTHOR,
                ),
                array (
                    self::ID    => Settings::IS_REVIEWER,
                ),
                array (
                    self::ID    => Settings::IS_MODERATOR,
                ),
                array (
                    self::ID    => Settings::IS_MEMBER,
                )
            )
        ),
    );

    /**
     * Provides a table of setting for the user page current viewing.
     *
     * @param $settings     // get the settings passed in.
     * @return HTML Object  // return back the HTML object of the table
     */
    public function __invoke($settings)
    {
        return '<table id="notificationTable" class="table table-hover table-striped">'
        . $this->buildTableBody($this->getView(), $settings). '</table>';
    }

    /**
     * Build the table body based on user settings otherwise use default settings
     *
     * @param $view         To allow us to use the translate on the title we require the view.
     * @param $settings     Both the users and default settings are pass in
     * @return HTML Object  Return the table populated with User and default settings
     */
    public function buildTableBody($view, $settings)
    {
        // Empty out the body ready to be used.
        $body = '';


        // For each of the settings defined in this Class loop them and build the table.
        foreach (self::$userSettingsDisplay as $key => $values) {
            $class = 'level2';
            // Check if the title value is set as some options are stand alone options.
            if (isset($values['title'])) {
                $body = $body . '<tr class="level1">'
                    . '<th colspan="2">' . $view->te($values['title']) . '</th>'
                    . '</tr>';
            } else {
                $class = 'level1';
            }
            // for each of the options within this group build the rows for them.
            foreach ($values['settings'] as $options) {
                // The user setting value.
                $settingsValues = $settings[$options['id']][$key];
                // Checks for settings to be applied.
                $notificationDisabled = $settingsValues['disabled'] === 'disabled'
                    ? ' class="notificationDisabled"' : '';
                // Incase option is disabled we set the option to the default value.
                $currentSetting = $settingsValues['disabled'] === 'disabled' ? $settingsValues['default']
                    : $settingsValues['value'];
                // Build the rows and populate with settings.
                $body = $body
                    . '<tr class="'.$class.'">'
                    .'<td'. $notificationDisabled . '>'
                    .  $view->te($options['title'])
                    . '</td>'
                    . '<td class="pull-right">'
                    . '<input id="' . $options['id'] . '_' . $key . '" '
                    . 'name="' .$options['id'] . '_' . $key . '" type="checkbox" data-default="'
                    . $settingsValues['default'] . '" ' . $currentSetting . ' ' . $settingsValues['disabled']
                    . ">"
                    . '</td>'
                    . '</tr>';
            }
        }
        return  '<tbody>' . $body . '</tbody>';
    }
}
