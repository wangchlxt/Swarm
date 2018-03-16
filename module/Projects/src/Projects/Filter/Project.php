<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Projects\Filter;

use Application\Filter\StringToId;
use Application\Filter\ArrayValues;
use Application\InputFilter\InputFilter;
use Application\Validator\FlatArray as FlatArrayValidator;
use Groups\Model\Group;
use Groups\Model\Config as GroupConfig;
use Groups\Validator\Groups as GroupsValidator;
use P4\Connection\ConnectionInterface as Connection;
use Projects\Model\Project as ProjectModel;
use Projects\Validator\BranchPath as BranchPathValidator;
use Users\Validator\Users as UsersValidator;

class Project extends InputFilter
{
    /**
     * Extends parent to add all of the project filters and setup the p4 connection.
     *
     * @param   Connection  $p4     connection to use for validation
     */
    public function __construct(Connection $p4)
    {
        $toId       = new StringToId;
        $reserved   = array('add', 'edit', 'delete');
        $translator = $p4->getService('translator');

        // declare id, but make it optional and rely on name validation.
        // you can place the 'name' into id for adds and it will auto-filter it.
        $this->add(
            array(
                 'name'      => 'id',
                 'required'  => false,
                 'filters'   => array($toId)
            )
        );

        // ensure name is given and produces a usable/unique id.
        $filter = $this;
        $this->add(
            array(
                'name'          => 'name',
                'filters'       => array('trim'),
                'validators'    => array(
                    array(
                        'name'      => 'NotEmpty',
                        'options'   => array(
                            'message' => "Name is required and can't be empty."
                        )
                    ),
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback' => function ($value) use ($p4, $toId, $reserved, $filter, $translator) {
                                $id = $toId($value);
                                if (!$id) {
                                    return $translator->t('Name must contain at least one letter or number.');
                                }

                                // if it isn't an add, we assume the caller will take care
                                // of ensuring existence.
                                if (!$filter->isAdd()) {
                                    return true;
                                }

                                // try to get project (including deleted) matching the name
                                $matchingProjects = ProjectModel::fetchAll(
                                    array(
                                        ProjectModel::FETCH_INCLUDE_DELETED => true,
                                        ProjectModel::FETCH_BY_IDS          => array($id)
                                    ),
                                    $p4
                                );

                                if ($matchingProjects->count() || in_array($id, $reserved)) {
                                    return $translator->t('This name is taken. Please pick a different name.');
                                }

                                return true;
                            }
                        )
                    )
                )
            )
        );

        // add members field
        $this->add(
            array(
                 'name'              => 'members',
                 'continue_if_empty' => true,
                 'filters'           => array(new ArrayValues),
                 'validators'    => array(
                     array(
                         'name'                   => '\Application\Validator\FlatArray',
                         'break_chain_on_failure' => true
                     ),
                     new UsersValidator(array('connection' => $p4)),
                     array(
                         'name'      => '\Application\Validator\Callback',
                         'options'   => array(
                             'callback' => function ($value, $context) use ($translator) {
                                 return $value || (isset($context['subgroups']) && $context['subgroups'])
                                     ? true
                                     : $translator->t('Project must have at least one member or subgroup.');
                             }
                         )
                     )
                 )
            )
        );

        // add subgroups field (only if the server supports managing groups)
        if ($p4->isServerMinVersion('2012.1')) {
            $this->add(
                array(
                     'name'             => 'subgroups',
                     'required'         => false,
                     'filters'          => array(new ArrayValues),
                     'validators'       => array(
                         array(
                             'name'                   => '\Application\Validator\FlatArray',
                             'break_chain_on_failure' => true
                         ),
                         new GroupsValidator(array('connection' => $p4))
                     )
                )
            );
        }

        // add owners field
        $this->add(
            array(
                 'name'             => 'owners',
                 'required'         => false,
                 'filters'          => array(new ArrayValues),
                 'validators'       => array(
                     array(
                         'name'                   => '\Application\Validator\FlatArray',
                         'break_chain_on_failure' => true
                     ),
                     new UsersValidator(array('connection' => $p4))
                 )
            )
        );

        // add private field
        $this->add(
            array(
                 'name'              => 'private',
                 'required'          => false,
                 'continue_if_empty' => true,
                 'filters'  => array(
                     array(
                         'name'  => 'Callback',
                         'options'   => array(
                             'callback'  => function ($value) {
                                 // invalid values need to be returned directly to the validator
                                 // otherwise convert valid values to boolean
                                 return is_scalar($value) ? (bool) $value : $value;
                             }
                         )
                     )
                 ),
                 'validators' => array(
                     array(
                         'name'    => '\Application\Validator\Callback',
                         'options' => array(
                             'callback' => function ($value) use ($translator) {
                                 return is_scalar($value)
                                     ?: $translator->t("Private must be a scalar value.");
                             }
                         )
                     )
                 )
            )
        );

        // ensure default is an array
        $this->add(
            array(
                'name' => 'defaults',
                'required' => false,
                'continue_if_empty' => false,
                'filters'  => array(
                    array(                    'name'  => 'Callback',
                        'options'   => array(
                            'callback'  => function ($value) {
                                // treat empty string as empty array
                                $value = empty($value) ? array() : $value;

                                // normalize the posted default details to only contain our expected keys
                                $defaults   = array(
                                    'reviewers' => array()
                                );
                                foreach ((array) $value as $name => $default) {
                                    if (isset($defaults[$name])) {
                                        if ($name === 'reviewers' && !empty($default)) {
                                            // If default reviewers have been passed as strings e.g. ['super']
                                            // convert to ['super' => array()], otherwise use the value given
                                            foreach ($default as $defaultKey => $defaultValue) {
                                                if (is_array($defaultValue)) {
                                                    $defaults[$name][$defaultKey] = $defaultValue;
                                                } else {
                                                    $defaults[$name][$defaultValue] = array();
                                                }
                                            }
                                        }
                                    }
                                }
                                return $defaults;
                            }
                        )
                    )
               ),
                'validators'       => array(
                    array(
                        'name'    => '\Application\Validator\Callback',
                        'options' => array(
                            'callback' => function ($value) use ($p4, $translator) {
                                // Verify defaults
                                if (!is_array($value)) {
                                    return $translator->t(
                                        "'defaults' must be an array but is '%s'.",
                                        array($value)
                                    );
                                }
                                if (isset($value['reviewers'])) {
                                    $usersValidator  = new UsersValidator(array('connection' => $p4));
                                    $groupsValidator = new GroupsValidator(array('connection' => $p4));
                                    // There are defaults verify that default reviewers are users or groups
                                    foreach ((array)$value['reviewers'] as $id => $defaultReviewer) {
                                        if (Group::isGroupName($id)) {
                                            if (!$groupsValidator->isValid(Group::getGroupName($id))) {
                                                return implode(' ', $groupsValidator->getMessages());
                                            }
                                        } elseif (!$usersValidator->isValid($id)) {
                                            return implode(' ', $usersValidator->getMessages());
                                        }
                                    }
                                }
                                return $value;
                            }
                        )
                    )
                )
            )
        );

        // ensure description is a string
        $this->add(
            array(
                 'name'             => 'description',
                 'required'         => false,
                 'continue_if_empty' => true,
                 'filters'          => array(array('name' => 'StringTrim')),
                 'validators'       => array(
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

        // ensure branches is an array
        $this->add(
            array(
                'name'     => 'branches',
                'required' => false,
                'filters'  => array(
                    array(
                        'name'  => 'Callback',
                        'options'   => array(
                            'callback'  => function ($value) use ($toId) {
                                // treat empty string as null
                                $value = $value === '' ? null : $value;

                                // exit early if we have not received an array of arrays (or empty array)
                                // the validator will handle these - or in the case of null, simply won't run
                                if (!is_array($value) || in_array(false, array_map('is_array', $value))) {
                                    return $value;
                                }

                                // normalize the posted branch details to only contain our expected keys
                                // also, generate an id (based on name) for entries lacking one
                                $normalized = array();
                                $defaults   = array(
                                    'id'                => null,
                                    'name'              => null,
                                    'paths'             => '',
                                    'moderators'        => array(),
                                    'moderators-groups' => array(),
                                    'defaults'          => array('reviewers' => array())
                                );
                                foreach ((array) $value as $branch) {
                                    $branch = (array) $branch + $defaults;
                                    $branch = array_intersect_key($branch, $defaults);

                                    $branch['id'] = $toId->filter($branch['name']);

                                    // turn paths text input into an array
                                    // trim and remove any empty entries
                                    $paths           = $branch['paths'];
                                    $paths           = is_array($paths) ? $paths : preg_split("/[\n\r]+/", $paths);
                                    $branch['paths'] = array_filter(array_map('trim', $paths), 'strlen');

                                    // Check that the defaults reviewers for a given branch is set and ensure it isn't
                                    // empty as '' can be seen as set but empty.
                                    if (isset($branch['defaults']['reviewers'])
                                        && !empty($branch['defaults']['reviewers'])
                                    ) {
                                        // If default reviewers have been passed as strings e.g. ['super']
                                        // convert to ['super' => array()], otherwise use the value given
                                        foreach ($branch['defaults']['reviewers'] as $defaultKey => $defaultValue) {
                                            if (is_array($defaultValue)) {
                                                $branch['defaults']['reviewers'][$defaultKey] = $defaultValue;
                                            } else {
                                                unset($branch['defaults']['reviewers'][$defaultKey]);
                                                $branch['defaults']['reviewers'][$defaultValue] = array();
                                            }
                                        }
                                    } else {
                                        $branch['defaults']['reviewers'] = array();
                                    }
                                    $normalized[]    = $branch;
                                }

                                return $normalized;
                            }
                        )
                    )
                ),
                'validators' => array(
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($value) use ($p4, $translator) {
                                if (!is_array($value)) {
                                    return "Branches must be passed as an array.";
                                }

                                // ensure all branches have a name and id.
                                // also ensure that no id is used more than once.
                                $ids        = array();
                                $branchPath = new BranchPathValidator(array('connection' => $p4));
                                foreach ($value as $branch) {
                                    if (!is_array($branch)) {
                                        return $translator->t("All branches must be in array form.");
                                    }

                                    if (!strlen($branch['name'])) {
                                        return "All branches require a name.";
                                    }

                                    // given our normalization, we assume an empty id results from a bad name
                                    if (!strlen($branch['id'])) {
                                        return $translator->t(
                                            'Branch name must contain at least one letter or number.'
                                        );
                                    }

                                    if (in_array($branch['id'], $ids)) {
                                        return $translator->t("Two branches cannot have the same id.") . ' '
                                           . $translator->t("'%s' is already in use.", array($branch['id']));
                                    }

                                    // validate branch paths
                                    if (!$branchPath->isValid($branch['paths'])) {
                                        return $translator->t("Error in '%s' branch: ", array($branch['name']))
                                           . implode(' ', $branchPath->getMessages());
                                    }

                                    // verify branch moderators
                                    $usersValidator = new UsersValidator(array('connection' => $p4));
                                    if (!$usersValidator->isValid($branch['moderators'])) {
                                        return implode(' ', $usersValidator->getMessages());
                                    }

                                    // verify branch moderators-groups
                                    if (isset($branch['moderators-groups'])
                                        && !is_array($branch['moderators-groups'])) {
                                        return $translator->t(
                                            "Error in '%s' branch 'moderators-groups' must be an array but is '%s'.",
                                            array($branch['name'], $branch['moderators-groups'])
                                        );
                                    }
                                    $groupsValidator = new GroupsValidator(
                                        array('connection' => $p4, 'allowProject' => false)
                                    );
                                    if (!$groupsValidator->isValid($branch['moderators-groups'])) {
                                        return implode(' ', $groupsValidator->getMessages());
                                    }

                                    // Verify defaults
                                    if (isset($branch['defaults']) && !is_array($branch['defaults'])) {
                                        return $translator->t(
                                            "Error in '%s' branch 'defaults' must be an array but is '%s'.",
                                            array($branch['name'], $branch['defaults'])
                                        );
                                    }
                                    if (isset($branch['defaults']) && isset($branch['defaults']['reviewers'])) {
                                        // There are defaults verify that default reviewers are users or groups
                                        foreach ($branch['defaults']['reviewers'] as $id => $defaultReviewer) {
                                            if (Group::isGroupName($id)) {
                                                if (!$groupsValidator->isValid(Group::getGroupName($id))) {
                                                    return implode(' ', $groupsValidator->getMessages());
                                                }
                                            } elseif (!$usersValidator->isValid($id)) {
                                                return implode(' ', $usersValidator->getMessages());
                                            }
                                        }
                                    }
                                    $ids[] = $branch['id'];
                                }
                                return true;
                            }
                        )
                    )
                )
            )
        );

        // ensure jobview is properly formatted
        // to start with we are only supporting one or more field=value pairs or blank.
        $this->add(
            array(
                'name'              => 'jobview',
                'required'          => false,
                'continue_if_empty' => true,
                'filters'           => array(array('name' => 'StringTrim')),
                'validators'        => array(
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($value) use ($translator) {
                                if (!is_string($value)) {
                                    return "Job filter must be a string.";
                                }

                                if (!strlen($value)) {
                                    return true;
                                }

                                $filters = preg_split('/\s+/', $value);
                                foreach ($filters as $filter) {
                                    if (!preg_match('/^([^=()|]+)=([^=()|]+)$/', $filter)) {
                                        return $translator->t(
                                            "Job filter only supports field=value conditions and the '*' wildcard."
                                        );
                                    }
                                }

                                return true;
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
                                    'change_email_project_users'   => isset($value['change_email_project_users'])
                                        ? $value['change_email_project_users']
                                        : true,
                                    'review_email_project_members' => isset($value['review_email_project_members'])
                                        ? $value['review_email_project_members']
                                        : true,
                                );
                            }
                        )
                    )
                ),
                'validators' => array(
                    array(
                        'name'    => '\Application\Validator\Callback',
                        'options' => array(
                            'callback' => function ($value) {
                                $flatArrayValidator = new FlatArrayValidator;
                                return $flatArrayValidator->isValid($value)
                                    ?: "Email flags must be an associative array of scalar values.";
                            }
                        )
                    )
                )
            )
        );

        // ensure tests is an array with expected keys
        $this->add(
            array(
                'name'      => 'tests',
                'required'  => false,
                'filters'   => array(
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
                                    'enabled'    => isset($value['enabled'])  ? (bool) $value['enabled'] : false,
                                    'url'        => isset($value['url'])      ? $value['url']            : null,
                                    'postBody'   => isset($value['postBody']) ? trim($value['postBody']) : null,
                                    'postFormat' => isset($value['postFormat'])
                                        ? trim(strtoupper($value['postFormat']))
                                        : ProjectModel::FORMAT_URL,
                                );
                            }
                        )
                    )
                ),
                'validators'   => array(
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($value) {
                                $flatArrayValidator = new FlatArrayValidator;
                                if (!$flatArrayValidator->isValid($value)) {
                                    return "Tests must be an associative array of scalar values.";
                                }
                                if (!is_null($value['url']) && !is_string($value['url'])) {
                                    return 'URL for tests must be a string.';
                                }
                                if ($value['enabled'] && !strlen($value['url'])) {
                                    return 'URL for tests must be provided if tests are enabled.';
                                }
                                if (!is_null($value['postBody']) && !is_string($value['postBody'])) {
                                    return 'POST Body for tests must be a string.';
                                }

                                // we only support URL and JSON encoded data
                                $format = $value['postFormat'];
                                if ($format != ProjectModel::FORMAT_URL && $format != ProjectModel::FORMAT_JSON) {
                                    return 'POST data for tests must be URL or JSON encoded.';
                                }

                                // validate based on format
                                $body = $value['postBody'] ?: '';
                                if ($format == ProjectModel::FORMAT_URL) {
                                    parse_str($body, $data);
                                    if (strlen($body) && !count($data)) {
                                        return 'POST data expected to be URL encoded, but could not be decoded.';
                                    }
                                } else {
                                    $data = @json_decode($body, true);
                                    if (strlen($body) && is_null($data)) {
                                        return 'POST data expected to be JSON encoded, but could not be decoded.';
                                    }
                                }

                                return true;
                            }
                        )
                    )
                 ),
            )
        );

        // ensure deploy is an array with 'enabled' and 'url' keys
        $this->add(
            array(
                'name'      => 'deploy',
                'required'  => false,
                'filters'   => array(
                    array(
                        'name'  => 'Callback',
                        'options'   => array(
                            'callback'  => function ($value) {
                                // invalid values need to be returned directly to the validator
                                $flatArrayValidator = new FlatArrayValidator;
                                if (!$flatArrayValidator->isValid($value)) {
                                    return $value;
                                }

                                return array(
                                    'enabled' => isset($value['enabled']) ? (bool) $value['enabled'] : false,
                                    'url'     => isset($value['url'])     ? $value['url']            : null,
                                );
                            }
                        )
                    )
                ),
                'validators'   => array(
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($value) {
                                $flatArrayValidator = new FlatArrayValidator;
                                if (!$flatArrayValidator->isValid($value)) {
                                    return "Deployment settings must be an associative array of scalar values.";
                                }
                                if (!is_null($value['url']) && !is_string($value['url'])) {
                                    return 'URL for deploy must be a string.';
                                }
                                if ($value['enabled'] && !strlen($value['url'])) {
                                    return 'URL for deploy must be provided if deployment is enabled.';
                                }

                                return true;
                            }
                        )
                    )
                ),
            )
        );
    }
}
