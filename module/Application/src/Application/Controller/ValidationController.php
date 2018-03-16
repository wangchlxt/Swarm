<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Application\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\Validator\EmailAddress;
use Zend\View\Model\JsonModel;

/**
 * Class ValidationController
 *
 * This is a placeholder for any validation actions that will be used by ajax front end calls. Currently
 * supported validations are:
 *
 *  - email address
 *
 * @package Application\Controller
 */
class ValidationController extends AbstractActionController
{
    public function emailAddressAction()
    {
        // Get any configured validation
        $services               = $this->getServiceLocator();
        $config                 = $services->get('config');
        $emailValidationOptions = isset($config['mail']['validator']['options'])
            ? $config['mail']['validator']['options'] : array();

        // Allow post and get
        $emailAddress = $this->getRequest()->isPost()
            ? $this->getRequest()->getPost('emailAddress')
            : $this->getRequest()->getQuery('emailAddress');

        $validator = new EmailAddress($emailValidationOptions);
        return new JsonModel(
            array( "valid" => $validator->isValid($emailAddress),"messages" => $validator->getMessages())
        );
    }
}
