<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Application\View\Http;

use Zend\Mvc\MvcEvent;
use Zend\View\Model\JsonModel;

class RouteNotFoundStrategy extends \Zend\Mvc\View\Http\RouteNotFoundStrategy
{
    /**
     * Extended to leave JSON models alone
     *
     * @param  MvcEvent $e
     * @return void
     */
    public function prepareNotFoundViewModel(MvcEvent $e)
    {
        if ($e->getResult() instanceof JsonModel) {
            return;
        }

        return parent::prepareNotFoundViewModel($e);
    }
}
