<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Groups\Validator;

use Application\Validator\ConnectedAbstractValidator;
use P4\Connection\AbstractConnection;
use Groups\Model\Group;
use Projects\Model\Project;
use Zend\Validator\Exception as ValidatorException;

/**
 * Check if the given list of ids represents existing Perforce groups.
 */
class Groups extends ConnectedAbstractValidator
{
    const INVALID_TYPE = 'invalidType';
    const UNKNOWN_IDS  = 'unknownIds';

    protected $messageTemplates = array(
        self::INVALID_TYPE => "Group ids must be strings",
        self::UNKNOWN_IDS  => "Unknown group id(s): %ids%"
    );

    protected $messageVariables = array(
        'ids' => 'unknownIds'
    );

    protected $unknownIds;
    /**
     * If true 'swarm-project-xxx' is treated as a valid group. If false projects
     * will raise an error.
     * @var bool
     */
    private $allowProject = true;

    /**
     * Returns true if $value is an id for an existing group or if it contains a list of ids
     * representing existing groups in Perforce.
     *
     * @param   string|array    $value  id or list of ids to check
     * @return  boolean         true if value is id or list of ids of existing groups, false otherwise
     */
    public function isValid($value)
    {
        $p4    = $this->getConnection();
        $value = (array) $value;

        if (in_array(false, array_map('is_string', $value))) {
            $this->error(self::INVALID_TYPE);
            return false;
        }

        $unknownIds = array();
        foreach ($value as $id) {
            if (!Group::exists($id, $p4) || ($this->allowProject == false && Project::isProjectName($id))) {
                $unknownIds[] = $id;
            }
        }

        if (count($unknownIds)) {
            $this->unknownIds = implode(', ', $unknownIds);
            $this->error(self::UNKNOWN_IDS);
            return false;
        }

        return true;
    }

    /**
     * If $allowProject 'swarm-project-xxx' is treated as a valid group. If false projects
     * will raise an error.
     * @param $allowProject
     */
    public function setAllowProject($allowProject)
    {
        $this->allowProject = $allowProject;
    }
}
