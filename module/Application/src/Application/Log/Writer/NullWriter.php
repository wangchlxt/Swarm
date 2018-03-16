<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Application\Log\Writer;

use Zend\Log\Writer\AbstractWriter;

class NullWriter extends AbstractWriter
{
    /**
     * Discard the provided message.
     *
     * @param   array   $event
     */
    protected function doWrite(array $event)
    {
    }
}
