<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */
namespace Application\Connection;

/**
 * This exception indicates we are operating in multi-p4-server
 * mode, but we don't know what server to connect to.
 */
class NoServerSelectedException extends \Exception
{
}
