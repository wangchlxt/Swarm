<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Users\Authentication;

use P4\Connection\Connection;
use P4\Connection\ConnectionInterface;
use P4\Connection\LoginException;
use P4\Spec\User;
use P4\Validate\UserName as UserNameValidator;
use Zend\Authentication\Adapter\AdapterInterface;
use Zend\Authentication\Result;

class Adapter implements AdapterInterface
{
    protected $user;
    protected $password;
    protected $p4;
    protected $userP4;
    protected $config;

    /**
     * Sets username, password and connection for authentication
     *
     * @return void
     */
    public function __construct($user, $password, ConnectionInterface $p4, $config = null)
    {
        $this->user     = $user;
        $this->password = $password;
        $this->p4       = $p4;
        $this->config   = $config;
    }

    /**
     * Performs an authentication attempt
     *
     * @return \Zend\Authentication\Result
     * @throws \Zend\Authentication\Adapter\Exception\ExceptionInterface
     *               If authentication cannot be performed
     */
    public function authenticate()
    {
        // note when we fetch a user against a case insensitive server,
        // the user id may come back with different case.
        // from the fetch point on we use the authoritative user id returned
        // by the server not the user provided value.
        $user      = false;
        $validator = new UserNameValidator;
        if ($validator->isValid($this->user)) {
            $user = User::fetchAll(
                array(
                    User::FETCH_BY_NAME => $this->user,
                    User::FETCH_MAXIMUM => 1
                ),
                $this->p4
            )->first();
        }

        // fail if the user id is invalid or not found
        if (!$user) {
            return new Result(Result::FAILURE_IDENTITY_NOT_FOUND, null);
        }

        // if this is a service/operator user they cannot run the required commands
        // to use swarm; return a failure
        if ($user->getType() == User::SERVICE_USER || $user->getType() == User::OPERATOR_USER) {
            return new Result(Result::FAILURE_UNCATEGORIZED, null);
        }

        // Set remoteAddr to null and then if we have the settings enabled fetch the REMOTE_ADDR
        $remoteAddr = null;
        if (isset($this->config['p4']['proxy_mode']) && $this->config['p4']['proxy_mode'] === true &&
            isset($_SERVER['REMOTE_ADDR'])) {
            $remoteAddr = $_SERVER['REMOTE_ADDR'];
        }

        // authenticate against current p4 server.
        $this->userP4 = Connection::factory(
            $this->p4->getPort(),
            $user->getId(),
            null,
            $this->password,
            null,
            null,
            $remoteAddr,
            $this->p4->getUser(),
            $this->p4->getPassword()
        );

        // if the password looks like it may be a ticket;
        // test it for that case first
        if (preg_match('/^[A-Z0-9]{32}$/', $this->password)) {
            if ($this->userP4->isAuthenticated()) {
                return new Result(
                    Result::SUCCESS,
                    array('id' => $user->getId(), 'ticket' => $this->password)
                );
            }
        }

        // try to login using the password
        // get a host unlocked ticket so we can use it with other services
        try {
            $ticket = $this->userP4->login(true);

            return new Result(
                Result::SUCCESS,
                array('id' => $user->getId(), 'ticket' => $ticket)
            );
        } catch (LoginException $e) {
            return new Result(
                $e->getCode(),
                null,
                array($e->getMessage())
            );
        }
    }

    /**
     * Get the connection instance most recently used to authenticate the user.
     *
     * @return  Connection|null     connection used for login or null if no auth attempted
     */
    public function getUserP4()
    {
        return $this->userP4;
    }
}
