<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

use Notifications\Settings;

// Default settings for notifications
return array(
    Settings::NOTIFICATIONS => array(
        Settings::REVIEW_NEW => array(
            Settings::IS_AUTHOR => Settings::ENABLED,
            Settings::IS_MEMBER => Settings::ENABLED
        ),
        Settings::REVIEW_FILES => array(
            Settings::IS_SELF      => Settings::ENABLED,
            Settings::IS_AUTHOR    => Settings::ENABLED,
            Settings::IS_REVIEWER  => Settings::ENABLED,
            Settings::IS_MODERATOR => Settings::ENABLED
        ),
        Settings::REVIEW_VOTE => array(
            Settings::IS_SELF      => Settings::ENABLED,
            Settings::IS_AUTHOR    => Settings::ENABLED,
            Settings::IS_REVIEWER  => Settings::ENABLED,
            Settings::IS_MODERATOR => Settings::ENABLED
        ),
        Settings::REVIEW_REQUIRED_VOTE => array(
            Settings::IS_SELF      => Settings::ENABLED,
            Settings::IS_AUTHOR    => Settings::ENABLED,
            Settings::IS_REVIEWER  => Settings::ENABLED,
            Settings::IS_MODERATOR => Settings::ENABLED
        ),
        Settings::REVIEW_OPTIONAL_VOTE => array(
            Settings::IS_SELF      => Settings::ENABLED,
            Settings::IS_AUTHOR    => Settings::ENABLED,
            Settings::IS_REVIEWER  => Settings::ENABLED,
            Settings::IS_MODERATOR => Settings::ENABLED
        ),
        Settings::REVIEW_STATE => array(
            Settings::IS_SELF      => Settings::DISABLED,
            Settings::IS_AUTHOR    => Settings::ENABLED,
            Settings::IS_REVIEWER  => Settings::ENABLED,
            Settings::IS_MODERATOR => Settings::ENABLED
        ),
        Settings::REVIEW_TESTS => array(
            Settings::IS_AUTHOR    => Settings::ENABLED,
            Settings::IS_REVIEWER  => Settings::ENABLED,
            Settings::IS_MODERATOR => Settings::ENABLED
        ),
        Settings::REVIEW_CHANGELIST_COMMIT => array(
            Settings::IS_AUTHOR    => Settings::ENABLED,
            Settings::IS_REVIEWER  => Settings::ENABLED,
            Settings::IS_MEMBER    => Settings::ENABLED,
            Settings::IS_MODERATOR => Settings::ENABLED
        ),
        Settings::REVIEW_COMMENT_NEW => array(
            Settings::IS_AUTHOR   => Settings::ENABLED,
            Settings::IS_REVIEWER => Settings::ENABLED
        ),
        Settings::REVIEW_COMMENT_UPDATE => array(
            Settings::IS_AUTHOR   => Settings::ENABLED,
            Settings::IS_REVIEWER => Settings::ENABLED
        ),
        Settings::REVIEW_COMMENT_LIKED => array(
            Settings::IS_COMMENTER => Settings::ENABLED
        ),
        Settings::REVIEW_OPENED_ISSUE => array(
            Settings::IS_SELF      => Settings::ENABLED,
            Settings::IS_AUTHOR    => Settings::ENABLED,
            Settings::IS_REVIEWER  => Settings::ENABLED,
            Settings::IS_MODERATOR => Settings::ENABLED
        ),
        Settings::REVIEW_JOIN_LEAVE => array(
            Settings::IS_SELF      => Settings::ENABLED,
            Settings::IS_AUTHOR    => Settings::ENABLED,
            Settings::IS_REVIEWER  => Settings::ENABLED,
            Settings::IS_MODERATOR => Settings::ENABLED
        ),
        Settings::HONOUR_P4_REVIEWS     => false,
        Settings::OPT_IN_REVIEW_PATH    => null,
        Settings::DISABLE_CHANGE_EMAILS => false
    )
);
