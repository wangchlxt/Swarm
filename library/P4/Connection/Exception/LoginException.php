<?php
/**
 * Exception to be thrown when a login attempt fails.
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace P4\Connection\Exception;

class LoginException extends \P4\Exception
{
    const   IDENTITY_NOT_FOUND = -1;
    const   IDENTITY_AMBIGUOUS = -2;
    const   CREDENTIAL_INVALID = -3;
}
