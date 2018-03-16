<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Groups\Filter;

use Application\Filter\ArrayValues;
use Application\Filter\FormBoolean;
use Application\Filter\StringToId;
use Application\InputFilter\InputFilter;
use Application\Validator\FlatArray as FlatArrayValidator;
use Application\Validator\IsArray as ArrayValidator;
use Groups\Model\Group as GroupModel;
use Groups\View\Helper\NotificationSettings;
use P4\Connection\ConnectionInterface as Connection;
use P4\Validate\GroupName as GroupNameValidator;
use Zend\Validator\EmailAddress;

class Group extends InputFilter
{
    protected $verifyNameAsId = false;

    /**
     * Enable/disable behavior where the name must produce a valid id.
     *
     * @param   bool    $verifyNameAsId     optional - pass true to verify the name makes a good id
     * @return  Group   provides fluent interface
     */
    public function verifyNameAsId($verifyNameAsId = null)
    {
        // doubles as an accessor
        if (func_num_args() === 0) {
            return $this->verifyNameAsId;
        }

        $this->verifyNameAsId = (bool) $verifyNameAsId;

        // if id comes from the name, then name is required and id is not
        $this->get('Group')->setRequired(!$this->verifyNameAsId);
        $this->get('name')->setRequired($this->verifyNameAsId);

        return $this;
    }

    /**
     * Generate an id from the given name.
     *
     * @param   string  $name   the name to turn into an id
     * @return  string  the resulting id
     */
    public function nameToId($name)
    {
        $toId = new StringToId;
        return $toId($name);
    }

    /**
     * Extends parent to add all of the group filters and setup the p4 connection.
     *
     * @param   Connection  $p4     connection to use for validation
     */
    public function __construct(Connection $p4, $emailValidatorOptions, $useMailingList)
    {
        $filter     = $this;
        $reserved   = array('add', 'edit', 'delete');
        $translator = $p4->getService('translator');

        // validate id for uniqueness on add, unless id comes from name
        // in that case the name field does all the validation for us
        $this->add(
            array(
                'name'       => 'Group',
                'required'   => true,
                'filters'    => array('trim'),
                'validators' => array(
                    array(
                        'name'    => '\Application\Validator\Callback',
                        'options' => array(
                            'callback' => function ($value) use ($p4, $reserved, $filter, $translator) {
                                // if adding and name does not inform id, check if the group already exists
                                if ($filter->isAdd() && !$filter->verifyNameAsId()
                                    && (in_array($value, $reserved) || GroupModel::exists($value, $p4))
                                ) {
                                    return $translator->t('This Group ID is taken. Please pick a different Group ID.');
                                }

                                // check if the group name is valid
                                $validator = new \P4\Validate\GroupName;
                                if ($filter->isAdd() && !$validator->isValid($value)) {
                                    $messages = $validator->getMessages();
                                    return array_shift($messages);
                                }
                                return true;
                            }
                        )
                    )
                )
            )
        );

        // if id comes from name, then we need to ensure name produces a usable/unique id
        $this->add(
            array(
                'name'       => 'name',
                'required'   => false,
                'filters'    => array('trim'),
                'validators' => array(
                    array(
                        'name'    => 'NotEmpty',
                        'options' => array(
                            'message' => $translator->t("Name is required and can't be empty.")
                        )
                    ),
                    array(
                        'name'    => '\Application\Validator\Callback',
                        'options' => array(
                            'callback' => function ($value) use ($p4, $reserved, $filter, $translator) {
                                // nothing to do if name does not inform the id
                                if (!$filter->verifyNameAsId()) {
                                    return true;
                                }

                                $id = $filter->nameToId($value);
                                if (!$id) {
                                    return $translator->t('Name must contain at least one letter or number.');
                                }

                                // check if the group id is valid
                                $validator = new GroupNameValidator;
                                if (!$validator->isValid($id)) {
                                    $messages = $validator->getMessages();
                                    return array_shift($messages);
                                }

                                // when adding, check if the group already exists
                                if ($filter->isAdd() && (in_array($id, $reserved) || GroupModel::exists($id, $p4))) {
                                    return $translator->t('This name is taken. Please pick a different name.');
                                }

                                return true;
                            }
                        )
                    )
                )
            )
        );

        // add users field
        $this->add(
            array(
                'name'              => 'Users',
                'continue_if_empty' => true,
                'filters'           => array(new ArrayValues),
                'validators' => array(
                    array(
                        'name'                   => '\Application\Validator\FlatArray',
                        'break_chain_on_failure' => true
                    ),
                    array(
                        'name'    => '\Application\Validator\Callback',
                        'options' => array(
                            'callback' => function ($value, $context) use ($translator) {
                                $context += array('Owners' => array(), 'Users' => array(), 'Subgroups' => array());
                                return $context['Owners'] || $context['Users'] || $context['Subgroups']
                                    ? true
                                    : $translator->t('Group must have at least one owner, user or subgroup.');
                            }
                        )
                    )
                )
            )
        );

        // add subgroups field
        $this->add(
            array(
                'name'       => 'Subgroups',
                'required'   => false,
                'filters'    => array(new ArrayValues),
                'validators' => array(new FlatArrayValidator)
            )
        );

        // add owners field
        $this->add(
            array(
                'name'       => 'Owners',
                'required'   => false,
                'filters'    => array(new ArrayValues),
                'validators' => array(new FlatArrayValidator)
            )
        );

        // ensure description is a string
        $this->add(
            array(
                'name'       => 'description',
                'required'   => false,
                'filters'    => array(array('name' => 'StringTrim')),
                'validators' => array(
                    array(
                        'name'    => '\Application\Validator\Callback',
                        'options' => array(
                            'callback' => function ($value) use ($translator) {
                                return is_string($value) ?: $translator->t("Description must be a string.");
                            }
                        )
                    )
                )
            )
        );

        // ensure use mailing list is true/false
        $this->add(
            array(
                'name'       => 'useMailingList',
                'required'   => false,
                'filters'    => array(array('name'    => '\Application\Filter\FormBoolean')),
                'validators' => array(
                    array(
                        'name'     => '\Zend\Validator\Callback',
                        'options'  => array(
                            'callback' => function ($value) {
                                $fb = new FormBoolean();
                                return $fb->filter($value);
                            }
                        )
                    )
                )
            )
        );

        // A hidden field to allow use to keep track of an email address (so we can detect when the field
        // is cleared but they have chosen to not use an email address)
        $this->add(
            array(
                'name'     => 'hiddenEmailAddress',
                'required' => false,
            )
        );

        // ensure emailAddress is valid
        $this->add(
            array(
                'name'     => 'emailAddress',
                'required' => ($useMailingList === "on" || $useMailingList === "true" || $useMailingList === true),
                'continue_if_empty' => true,
                'validators'  => array(
                    // Override the default NotEmpty output with custom message.
                    array(
                        'name'                   => 'NotEmpty',
                        // If this validator proves that the value is invalid do not carry on with
                        // any future chained reviews
                        'break_chain_on_failure' => true,
                        'options'                => array(
                            'message' => $translator->t("Email is required when sending to a mailing list.")
                        )
                    ),
                    array(
                        'name'    => '\Application\Validator\Callback',
                        'options' => array(
                            'callback' => function ($value) use ($emailValidatorOptions) {
                                // invalid values need to be returned directly to the validator
                                $validator = new EmailAddress($emailValidatorOptions);
                                return $validator->isValid($value) ?: implode(". ", $validator->getMessages());
                            }
                        )
                    )
                )
            )
        );

        // ensure emailFlags is an array containing keys for the flags we want to set
        $this->add(
            array(
                'name'     => 'emailFlags',
                'required' => false,
                'filters'  => array(
                    array(
                        'name'    => 'Callback',
                        'options' => array(
                            'callback' => function ($value) {
                                // invalid values need to be returned directly to the validator
                                $flatArrayValidator = new FlatArrayValidator;
                                if (!$flatArrayValidator->isValid($value)) {
                                    return $value;
                                }

                                return array(
                                    'reviews' => isset($value['reviews']) ? $value['reviews'] : false,
                                    'commits' => isset($value['commits']) ? $value['commits'] : false,
                                );
                            }
                        )
                    )
                ),
                'validators' => array(
                    array(
                        'name'    => '\Application\Validator\Callback',
                        'options' => array(
                            'callback' => function ($value) use ($translator) {
                                $flatArrayValidator = new FlatArrayValidator;
                                return $flatArrayValidator->isValid($value)
                                    ?: $translator->t("Email flags must be an associative array of scalar values.");
                            }
                        )
                    )
                )
            )
        );

        // ensure notification settings is an array containing keys for the flags we want to set
        $this->add(
            array(
                'name'     => 'group_notification_settings',
                'required' => false,
                'filters'  => array(
                    array(
                        'name'    => 'Callback',
                        'options' => array(
                            'callback' => function ($value) {
                                // invalid values need to be returned directly to the validator
                                $flatArrayValidator = new FlatArrayValidator;
                                if (!$flatArrayValidator->isValid($value)) {
                                    return $value;
                                }
                                // Convert from the flat array to a nested one based on the view helper
                                return NotificationSettings::buildFromFlatArray($value);
                            }
                        )
                    )
                ),
                'validators' => array(
                    array(
                        'name'    => '\Application\Validator\Callback',
                        'options' => array(
                            'callback' => function ($value) use ($translator) {
                                $arrayValidator = new ArrayValidator;
                                return $arrayValidator->isValid($value)
                                    ?: $translator->t("Notification settings must be an array.");
                            }
                        )
                    )
                )
            )
        );
    }
}
