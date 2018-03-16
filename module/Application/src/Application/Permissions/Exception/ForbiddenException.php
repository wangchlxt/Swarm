<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */
namespace Application\Permissions\Exception;

/**
 * This exception indicates the user lacks permission to perform the requested action.
 * In general, this means you're logged in and everything is working fine but you attempted
 * to do something you don't have permissions for.
 */
class ForbiddenException extends Exception
{
}
