<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Reviews\Filter;

use Application\InputFilter\InputFilter;
use Groups\Model\Group;
use P4\Connection\ConnectionInterface as Connection;
use Reviews\Model\Review as ReviewModel;
use Users\Model\User;
use Reviews\Validator\Reviewers as ReviewersValidator;
use Users\Validator\Users as UsersValidator;
use Groups\Validator\Groups as GroupsValidator;

/**
 * Defines filters to run for a review.
 * @package Reviews\Filter
 */
class Review extends InputFilter
{
    private $p4;
    private $request;

    /**
     * Review filter constructor.
     * @param ReviewModel $review           the review
     * @param             $request          the request
     * @param             $services         services to get connection etc.
     * @param             $transitions      permitted transitions for the review
     * @param             $canEditReviewers whether reviewers can be edited
     * @param             $canEditAuthor    whether review author can be edited
     */
    public function __construct(
        ReviewModel $review,
        $request,
        $services,
        $transitions,
        $canEditReviewers,
        $canEditAuthor
    ) {

        $this->p4      = $services->get('p4');
        $this->request = $request;

        $p4Admin    = $services->get('p4_admin');
        $translator = $services->get('translator');

        // (PHP 5.3 compatibility forces passing some values in as params as
        // $this-> cannot be used in a callback context)
        $this->addAuthor($canEditAuthor, $translator, $p4Admin);
        $this->addState($transitions, $review);
        $this->addCommitStatus($review);
        $this->addTestStatus();
        $this->addTestDetails();
        $this->addDeployDetails();
        $this->addDeployStatus();
        $this->addDescription();
        $this->addPatchUser();
        $this->addJoin($services);
        $this->addLeave($services);
        $this->addReviewers(false, $canEditReviewers);
        $this->addReviewers(true,  $canEditReviewers);
        $this->addVote();
        $this->addReviewerQuorum($canEditReviewers);
    }

    /**
     * Overrides the populate to specifically unset values that were not supplied in a patch
     * request. Patch should not have to supply everything so we should not validate inputs
     * it did not supply.
     */
    protected function populate()
    {
        parent::populate();
        if ($this->request->isPatch()) {
            foreach (array_keys($this->inputs) as $name) {
                $input = $this->inputs[$name];

                if (!isset($this->data[$name]) ||
                    $this->data[$name] == null ||
                    (is_string($this->data[$name]) && trim($this->data[$name]) === '') ||
                    empty($this->data[$name])) {
                    unset($this->data[$name]);
                }
            }
        }
    }

    /**
     * Add filters for the author field. (PHP 5.3 compatibility forces passing these values in as
     * params as $this-> cannot be used in a callback context)
     * @param $canEditAuthor
     * @param $translator
     * @param $p4Admin
     */
    private function addAuthor($canEditAuthor, $translator, $p4Admin)
    {
        $this->add(
            array(
                'name'              => 'author',
                'required'          => $this->request->isPatch() === false,
                'continue_if_empty' => true,
                'validators'    => array(
                    // Override the default NotEmpty output with custom message.
                    array(
                        'name'                   => 'NotEmpty',
                        // If this validator proves that the value is invalid do not carry on with
                        // any future chained reviews
                        'break_chain_on_failure' => true,
                        'options'                => array(
                            'message' => $translator->t("Author is required and cannot be empty.")
                        )
                    ),
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($value) use ($canEditAuthor, $translator, $p4Admin) {
                                if (!$canEditAuthor) {
                                    return $translator->t('You do not have permission to change author.');
                                }
                                // For a single string translation replacement it would be OK to not supply the
                                // array as $value is used, but I'm choosing to be specific for clarity
                                if (!User::exists($value, $p4Admin)) {
                                    return $translator->t("User ('%s') does not exist.", array($value));
                                }
                                return true;
                            }
                        )
                    )
                )
            )
        );
    }

    /**
     * Add filters for the state field. (PHP 5.3 compatibility forces passing these values in as
     * params as $this-> cannot be used in a callback context)
     * @param $transitions
     * @param $review
     */
    private function addState($transitions, $review)
    {
        // declare state field
        $this->add(
            array(
                'name'          => 'state',
                'required'      => false,
                'validators'    => array(
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($value) use ($transitions, $review) {
                                if (!in_array($value, array_keys($transitions))) {
                                    return "You cannot transition this review to '%s'.";
                                }

                                // if a commit is already going on, error out for second attempt
                                if ($value == 'approved:commit' && $review->isCommitting()) {
                                    return "A commit is already in progress.";
                                }

                                return true;
                            }
                        )
                    )
                )
            )
        );
    }

    /**
     * Add filters for commit status. (PHP 5.3 compatibility forces passing these values in as
     * params as $this-> cannot be used in a callback context)
     * @param $review
     */
    private function addCommitStatus($review)
    {
        $this->add(
            array(
                'name'          => 'commitStatus',
                'required'      => false,
                'validators'    => array(
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($value) use ($review) {
                                // if a commit is already going on, don't allow clearing status
                                if ($review->isCommitting()) {
                                    return "A commit is in progress; can't clear commit status.";
                                }

                                if ($value) {
                                    return "Commit status can only be cleared; not set.";
                                }

                                return true;
                            }
                        )
                    )
                )
            )
        );
    }

    /**
     * Add filters for test status.
     */
    private function addTestStatus()
    {
        $this->add(
            array(
                'name'          => 'testStatus',
                'required'      => false,
                'validators'    => array(
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($value) {
                                if (!in_array($value, array('pass', 'fail'))) {
                                    return "Test status must be 'pass' or 'fail'.";
                                }
                                return true;
                            }
                        )
                    )
                )
            )
        );
    }

    /**
     * Add filters for test details.
     */
    private function addTestDetails()
    {
        $this->add(
            array(
                'name'          => 'testDetails',
                'required'      => false,
                'validators'    => array(
                    array(
                        'name'      => '\Application\Validator\IsArray'
                    ),
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($value) {
                                // if url is set, validate it
                                if (isset($value['url']) && strlen($value['url'])) {
                                    $validator = new \Zend\Validator\Uri;
                                    if (!$validator->isValid($value['url'])) {
                                        return "Url in test details must be a valid uri.";
                                    }
                                }
                                return true;
                            }
                        )
                    )
                )
            )
        );
    }

    /**
     * Add filters for deploy status.
     */
    private function addDeployStatus()
    {
        $this->add(
            array(
                'name'          => 'deployStatus',
                'required'      => false,
                'validators'    => array(
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($value) {
                                if (!in_array($value, array('success', 'fail'))) {
                                    return "Deploy status must be 'success' or 'fail'.";
                                }
                                return true;
                            }
                        )
                    )
                )
            )
        );
    }

    /**
     * Add filters for deploy details.
     */
    private function addDeployDetails()
    {
        $this->add(
            array(
                'name'          => 'deployDetails',
                'required'      => false,
                'validators'    => array(
                    array(
                        'name'      => '\Application\Validator\IsArray'
                    ),
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($value) {
                                // if url is set, validate it
                                if (isset($value['url']) && strlen($value['url'])) {
                                    $validator = new \Zend\Validator\Uri;
                                    if (!$validator->isValid($value['url'])) {
                                        return "Url in deploy details must be a valid uri.";
                                    }
                                }
                                return true;
                            }
                        )
                    )
                )
            )
        );
    }

    /**
     * Add filters for description. We normalize line endings to \n to keep git-fusion happy and trim excess whitespace.
     */
    private function addDescription()
    {
        $this->add(
            array(
                'name'          => 'description',
                'required'      => $this->request->isPatch() === false,
                'filters'       => array(
                    array(
                        'name'      => '\Zend\Filter\Callback',
                        'options'   => array(
                            'callback'  => function ($value) {
                                return preg_replace('/(\r\n|\r)/', "\n", $value);
                            }
                        )
                    ),
                    'trim'
                )
            )
        );
    }

    /**
     * Add filters for patch user pseudo-field for purpose of modifying active participant's properties.
     */
    private function addPatchUser()
    {
        $this->add(
            array(
                'name'          => 'patchUser',
                'required'      => false,
                'filters'       => array(
                    array(
                        'name'      => '\Zend\Filter\Callback',
                        'options'   => array(
                            'callback'  => function ($value) {
                                // note null/false are handled oddly on older filter_var's
                                // so just leave em be if that's what we came in with
                                $value = (array) $value;
                                if (isset($value['required'])
                                    && !is_null($value['required'])
                                    && !is_bool($value['required'])
                                    && !is_numeric($value['required'])
                                ) {
                                    $value['required'] = filter_var(
                                        $value['required'],
                                        FILTER_VALIDATE_BOOLEAN,
                                        FILTER_NULL_ON_FAILURE
                                    );
                                }

                                if (isset($value['notificationsDisabled'])
                                    && !is_null($value['notificationsDisabled'])
                                    && !is_bool($value['notificationsDisabled'])
                                ) {
                                    $value['notificationsDisabled'] = filter_var(
                                        $value['notificationsDisabled'],
                                        FILTER_VALIDATE_BOOLEAN,
                                        FILTER_NULL_ON_FAILURE
                                    );
                                }
                                return $value;
                            }
                        )
                    )
                ),
                'validators'    => array(
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($value) {
                                // ensure at least one known property has been provided
                                $knownProperties = count(
                                    array_intersect_key(
                                        (array)$value,
                                        array_flip(array('required', 'notificationsDisabled'))
                                    )
                                );
                                if ($knownProperties === 0) {
                                    return "You must specify at least one known property.";
                                }

                                // check that required/notificationsDisabled properties contain valid values
                                $existsAndNull = function ($key) use ($value) {
                                    return array_key_exists($key, $value) && $value[$key] === null;
                                };
                                if ($existsAndNull('required')) {
                                    return "Invalid value specified for required field, expecting true or false";
                                }
                                if ($existsAndNull('notificationsDisabled')) {
                                    return "Invalid value specified for notifications field, expecting true or false";
                                }

                                return true;
                            }
                        )
                    )
                )
            )
        );
    }

    /**
     * Add filters for 'join' pseudo-field for purpose of adding active user as a reviewer.
     * (PHP 5.3 compatibility forces passing these values in as params as $this-> cannot be used in a callback context)
     * @param $services
     */
    private function addJoin($services)
    {
        $this->add(
            array(
                'name'          => 'join',
                'required'      => false,
                'validators'    => array(
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($value) use ($services) {
                                if ($value != $services->get('user')->getId()) {
                                    return "Cannot join review, not logged in as %s.";
                                }

                                return true;
                            }
                        )
                    )
                )
            )
        );
    }

    /**
     * Add filters for 'leave' pseudo-field for purpose of removing active user as a reviewer.
     * (PHP 5.3 compatibility forces passing these values in as params as $this-> cannot be used in a callback context)
     * @param $services
     */
    private function addLeave($services)
    {
        $p4connection = $this->p4;
        $this->add(
            array(
                'name'          => 'leave',
                'required'      => false,
                'validators'    => array(
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($value) use ($services, $p4connection) {
                                if ($value != $services->get('user')->getId()) {
                                    $validateGroup = new GroupsValidator(array('connection' => $p4connection));
                                    return $validateGroup->isValid(Group::getGroupName($value))?:
                                        "Cannot leave review, not logged in as %s.";
                                }
                                return true;
                            }
                        )
                    )
                )
            )
        );
    }

    /**
     * Add filters for 'reviewers' and 'requiredReviewers' pseudo-field for purpose of editing the reviewers list.
     * (PHP 5.3 compatibility forces passing these values in as params as $this-> cannot be used in a callback context)
     * @param $isRequired
     * @param $canEditReviewers
     */
    private function addReviewers($isRequired, $canEditReviewers)
    {
        $this->add(
            array(
                'name'          => $isRequired === true ? ReviewModel::REQUIRED_REVIEWERS : ReviewModel::REVIEWERS,
                'required'      => false,
                'filters'       => array(
                    array(
                        'name'      => '\Zend\Filter\Callback',
                        'options'   => array(
                            'callback'  => function ($value) {
                                // throw away any provided keys
                                return array_values((array) $value);
                            }
                        )
                    )
                ),
                'validators'    => array(
                    array(
                        'name'      => '\Application\Validator\IsArray'
                    ),
                    array(
                        'name'      => '\Zend\Validator\Callback',
                        'options'   => array(
                            'callback' => function ($value) use ($canEditReviewers) {
                                if (!$canEditReviewers) {
                                    return 'You do not have permission to edit reviewers.';
                                }
                                return true;
                            }
                        )
                    ),
                    new ReviewersValidator($this->p4),
                )
            )
        );
    }

    /**
     * Validates the reviewerQuorum field
     * @param $canEditReviewers
     */
    private function addReviewerQuorum($canEditReviewers)
    {
        $this->add(
            array(
                'name'          => ReviewModel::REVIEWER_QUORUMS,
                'required'      => false,
                'validators'    => array(
                    array(
                        'name'      => '\Application\Validator\IsArray'
                    ),
                    array(
                        'name'      => '\Zend\Validator\Callback',
                        'options'   => array(
                            'callback' => function ($value) use ($canEditReviewers) {
                                if (!$canEditReviewers) {
                                    return 'You do not have permission to edit reviewers.';
                                }
                                return true;
                            }
                        )
                    ),
                    new ReviewersValidator($this->p4),
                )
            )
        );
    }

    /**
     * Add filters for 'vote' pseudo-field for purpose of adding user vote.
     */
    private function addVote()
    {
        $this->add(
            array(
                'name'          => 'vote',
                'required'      => false,
                'filters'       => array(
                    array(
                        'name'      => '\Zend\Filter\Callback',
                        'options'   => array(
                            'callback'  => function ($vote) {
                                if (!is_array($vote)) {
                                    return $vote;
                                }

                                $vote += array('value' => null, 'version' => null);
                                $valid = array('up' => 1, 'down' => -1, 'clear' => 0);
                                if (array_key_exists($vote['value'], $valid)) {
                                    $vote['value'] = $valid[$vote['value']];
                                }
                                if (!$vote['version']) {
                                    $vote['version'] = null;
                                }

                                return $vote;
                            }
                        )
                    )
                ),
                'validators'    => array(
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($vote) {
                                // only allow 0, -1, 1 as values
                                if (!is_array($vote) || !is_int($vote['value'])
                                    || !in_array($vote['value'], array(1, -1, 0))
                                ) {
                                    return "Invalid vote value";
                                }

                                if ($vote['version'] && !ctype_digit((string) $vote['version'])) {
                                    return "Invalid vote version";
                                }

                                return true;
                            }
                        )
                    )
                )
            )
        );
    }
}
