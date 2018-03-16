<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Api\Converter;

use Groups\Model\Config;
use Groups\Model\Group;

/**
 * Class to help converting reviewer data between API friendly and application specific structures.
 * @package Api\Converter
 */
class Reviewers
{

    /**
     * Convert users/groups requirement to expand into separate users
     * and groups arrays. For example
     *
     * 'swarm-group-group1' => array('required' => '1')
     * 'user1'              => array()
     *
     * would be converted to
     *
     * 'groups' => array('group1' => array('required' => '1')
     * 'users'  => array('user1'  => array())
     *
     * @param $source array of user or group keys to a requirement detail.
     * @param string $newUserField field for users, defaults to users
     * @param string $newGroupField field for groups, defaults to groups
     * @return array
     */
    public static function expandUsersAndGroups($source, $newUserField = 'users', $newGroupField = 'groups')
    {
        $converted = array();
        if ($source && !empty($source)) {
            foreach ($source as $name => $detail) {
                if (is_string($name)) {
                    if (Group::isGroupName($name) === true) {
                        $converted[$newGroupField][Group::getGroupName($name)] = $detail;
                    } else {
                        $converted[$newUserField][$name] = $detail;
                    }
                }
            }
        }
        return $converted;
    }

    /**
     * Convert users/groups requirement to collapse single array. For example
     *
     * 'groups' => array('group1' => array('required' => '1')
     * 'users'  => array('user1'  => array())
     *
     * would be converted to
     *
     * 'swarm-group-group1' => array('required' => '1')
     * 'user1'              => array()
     *
     * @param $source array of user or group keys to a requirement detail.
     * @param string $newUserField field for users, defaults to users
     * @param string $newGroupField field for groups, defaults to groups
     * @return array
     */
    public static function collapseUsersAndGroups($source, $userField = 'users', $groupField = 'groups')
    {
        $converted = array();
        if ($source && !empty($source)) {
            if (isset($source[$userField])) {
                foreach ($source[$userField] as $user => $detail) {
                    $converted[$user] = $detail;
                }
            }
            if (isset($source[$groupField])) {
                foreach ($source[$groupField] as $group => $detail) {
                    $converted[Config::KEY_PREFIX . $group] = $detail;
                }
            }
        }
        return empty($converted) ? $source : $converted;
    }
}
