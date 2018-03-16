<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Queue\Listener;

use P4\Connection\ConnectionInterface;
use Record\Exception\NotFoundException as RecordNotFoundException;
use Record\Key\GenericKey;
use Zend\EventManager\AbstractListenerAggregate;
use Zend\EventManager\Event;
use Zend\EventManager\EventManagerInterface;
use Zend\ServiceManager\ServiceLocatorInterface as ServiceLocator;

class Ping extends AbstractListenerAggregate
{
    protected $services   = null;
    protected $pingRecord = null;

    const PING_KEY         = 'swarm-ping';
    const PING_FILE        = 'ping';
    const PING_LAPSE_TIME  = 30;
    const LOG_ERROR_PREFIX = 'Cannot send ping: ';

    /**
     * Ensure we get a service locator on construction.
     *
     * @param   ServiceLocator  $services   the service locator to use
     */
    public function __construct(ServiceLocator $services)
    {
        $this->services = $services;
    }

    /**
     * Attach the listener to send/receive queue ping.
     *
     * @param  EventManagerInterface    $events
     */
    public function attach(EventManagerInterface $events)
    {
        $this->listeners[] = $events->attach(
            'worker.loop',
            array($this, 'sendPing')
        );

        $this->listeners[] = $events->attach(
            'task.ping',
            array($this, 'receivePing')
        );
    }

    /**
     * Send the ping by accessing an archive file in depot. This will fire off the archive trigger (if present)
     * that will create a specific task we process in receivePing() method.
     *
     * @param   Event  $event   send ping event
     */
    public function sendPing(Event $event)
    {
        $services = $this->services;
        $p4Admin  = $services->get('p4_admin');
        $time     = $p4Admin->getServerTime();
        $ping     = $this->getPingRecord($p4Admin);

        // send ping once per lapse; exit early if within the same lapse
        if ($time - (int) $ping->get('sendTime') <= static::PING_LAPSE_TIME) {
            return;
        }

        // we are going to send the ping, remember the time
        $this->savePingValues(array('sendTime' => $time));

        // prepare filespec for the ping file
        $storage  = $services->get('depot_storage');
        $pingFile = $storage->absolutize(static::PING_FILE);

        try {
            // check if the file is already in the depot
            $result = $p4Admin->run('fstat', array('-Oc', '-TlbrType,depotFile,headAction', $pingFile));

            // if the ping file is not present, add it
            // the ping file needs to be +X, but we first add it as a regular text file and then retype it
            // adding the file as +X right away would fail if the archive trigger is not present, but it would
            // increase the change counter every time we try it
            // retype to +X will still fail if the archive trigger is not present, but the change counter will
            // not be increased
            if ($result->getData(0, 'depotFile') !== $pingFile || $result->getData(0, 'headAction') === 'delete') {
                $storage->write(
                    $pingFile,
                    'Placeholder file for testing Swarm triggers. Do not modify the content of this file.'
                );
            }

            // check if the head revision of the file is +X and run retype if not
            if ($result->getData(0, 'lbrType') !== 'text+X') {
                $p4Admin->run('retype', array('-l', '-t', 'text+X', $pingFile . '#head'));
            }

            // fire off the ping trigger by reading the ping file
            $result = $p4Admin->run('print', array($pingFile));

            // ping was sent successfully, clear any previous errors otherwise they will
            // stay present if we don't receive the ping back
            $this->savePingValues(array('error' => null));
        } catch (\Exception $e) {
            // turn '... - must refer to client ...' error into a friendlier message
            // this error is thrown when the ping file is not mapped in client view (i.e. when pingFile
            // points to a depot that doesn't exist or when access to the depot is removed in protections)
            $message = stripos($e->getMessage(), '- must refer to client') !== false
                ? 'Please ensure that ' . $pingFile . ' is writable.'
                : $e->getMessage();

            // if the error is different from the previous one,
            // log it as error event, otherwise log it as debug
            // then store the error message in the ping key
            $logLevel = $message !== $ping->get('error') ? 'err' : 'debug';
            $services->get('logger')->$logLevel(static::LOG_ERROR_PREFIX . $message);
            $this->savePingValues(array('error' => $message));
        }
    }

    /**
     * Receive ping by updating the ping record with the time we received the ping.
     *
     * @param   Event  $event   receive ping event
     */
    public function receivePing(Event $event)
    {
        $p4Admin = $this->services->get('p4_admin');
        $this->savePingValues(
            array(
                'error'       => null,
                'receiveTime' => $p4Admin->getServerTime()
            )
        );
    }

    /**
     * Helper method to set and save values on the ping key.
     *
     * @param   array  $values  values to set and immediately save on the ping key
     */
    protected function savePingValues(array $values)
    {
        $services = $this->services;
        $p4Admin  = $services->get('p4_admin');
        $ping     = $this->getPingRecord($p4Admin);

        try {
            $ping->set($values)->save();
            $this->pingRecord = $ping;
        } catch (\Exception $e) {
            $services->get('logger')->err($e);
        }
    }

    /**
     * Get the ping record. The ping record is cached and we fetch new instance only if
     * the receive ping time is stale. If the record doesn't exist, create the new instance,
     * set the id and return it.
     *
     * @param   ConnectionInterface     $connection     connection to use
     * @return  GenericKey              ping instance
     */
    protected function getPingRecord(ConnectionInterface $connection)
    {
        $time = $connection->getServerTime();
        $ping = $this->pingRecord;

        // cache the ping record and re-fetch it only if receive ping time is stale
        if (!$ping || $time - (int) $ping->get('receiveTime') > static::PING_LAPSE_TIME) {
            try {
                $ping = GenericKey::fetch(static::PING_KEY, $connection);
            } catch (RecordNotFoundException $e) {
                $ping = new GenericKey($connection);
                $ping->setId(static::PING_KEY);
            }
            $this->pingRecord = $ping;
        }

        return $ping;
    }
}
