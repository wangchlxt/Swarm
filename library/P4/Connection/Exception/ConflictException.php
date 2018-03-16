<?php
/**
 * Exception to be thrown when a resolve error occurs.
 * Holds the associated Connection instance and result object.
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace P4\Connection\Exception;

use P4\Spec\Change;

class ConflictException extends CommandException
{
    /**
     * Returns a Change object for the changelist the conflict files live in.
     *
     * @return  Change  object for the changelist with conflict files
     */
    public function getChange()
    {
        preg_match(
            '/submit -c ([0-9]+)/',
            implode($this->getResult()->getErrors()),
            $matches
        );

        return Change::fetchById($matches[1], $this->getConnection());
    }
}
