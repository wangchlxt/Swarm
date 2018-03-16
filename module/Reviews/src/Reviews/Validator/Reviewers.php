<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Reviews\Validator;

use Application\Validator\ConnectedAbstractValidator;
use Users\Validator\Users as UserValidator;
use Groups\Validator\Groups as GroupValidator;
use Groups\Model\Group;

/**
 * Validator for the psuedo secret fields 'reviewers' and 'requiredReviewers' that handles
 * users and groups. Uses the existing Users and Groups validators.
 * @package Reviews\Validator
 */
class Reviewers extends ConnectedAbstractValidator
{
    const GROUP_PREFIX = 'swarm-group-';
    private $connectionOption;

    public function __construct($p4)
    {
        $this->connectionOption = array('connection' => $p4);
        parent::__construct();
    }

    /**
     * Returns true if $value is an id for an existing user/group or if it contains a list of ids
     * representing existing users/groups in Perforce.
     *
     * @param   string|array    $value  id or list of ids to check
     * @return  boolean         true if value is id or list of ids of existing users/groups, false otherwise
     */
    public function isValid($value)
    {
        $groupsValid                       = true;
        $usersValid                        = true;
        $quorumValid                       = true;
        $value                             = (array) $value;
        $ids                               = array();
        $this->abstractOptions['messages'] = array();

        // $values will be either a list of group/user ids for 'reviewers' and 'requiredReviewers'
        // 0 => 'swarm-group-group1'
        // 1 => 'swarm-group-group1'
        // or ids pointing to quorum for reviewerQuorum
        // 'swarm-group-group1' => 1
        foreach ($value as $key => $item) {
            if (!is_numeric($key)) {
                array_push($ids, $key);
                if (!is_numeric($item) || (int) $item !== 1) {
                    array_push($this->abstractOptions['messages'], "Quorum value must be 1");

                    $quorumValid = false;
                }
            } else {
                array_push($ids, $item);
            }
        }

        $users = array_filter(
            array_map(
                function ($id) {
                    return Group::isGroupName($id) === false ? $id : null;
                },
                $ids
            )
        );
        $groups = array_map(
            function ($id) {
                return Group::getGroupName($id);
            },
            array_diff($ids, $users)
        );

        if (count($groups)) {
            $groupsValid = $this->doValidate(new GroupValidator($this->connectionOption), $groups);
        }

        if (count($users)) {
            $usersValid = $this->doValidate(new UserValidator($this->connectionOption), $users);
        }

        return $groupsValid && $usersValid && $quorumValid;
    }

    /**
     * Runs the validation
     * @param $validator the validator
     * @param $value the value(s)
     * @return boolean true if value is valid
     */
    private function doValidate($validator, $value)
    {
        $valid = $validator->isValid($value);
        if (!$valid) {
            $this->abstractOptions['messages'] =
                array_merge($this->abstractOptions['messages'], $validator->getMessages());
        }
        return $valid;
    }
}
