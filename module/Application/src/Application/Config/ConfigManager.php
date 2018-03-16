<?php
/**
 * Manager to help with getting values from a configuration array.
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Application\Config;

class ConfigManager
{
    const DIFF_MAX_DIFFS                          = 'diffs.max_diffs';
    const FILES_DOWNLOAD_TIMEOUT                  = 'files.download_timeout';
    const FILES_MAX_SIZE                          = 'files.max_size';
    const REVIEWS_EXPAND_ALL                      = 'reviews.expand_all_file_limit';
    const REVIEWS_DISABLE_TESTS_ON_APPROVE_COMMIT = 'reviews.disable_tests_on_approve_commit';
    const REVIEWS_EXPAND_GROUP_REVIEWERS          = 'reviews.expand_group_reviewers';
    const REVIEWS_FILTERS_RESULT_SORTING          = 'reviews.filters.result_sorting';
    const REVIEWS_FILTERS_DATE_FIELD              = 'reviews.filters.date_field';
    const UPGRADE_BATCH_SIZE                      = 'upgrade.batch_size';
    const UPGRADE_STATUS_REFRESH_INTERVAL         = 'upgrade.status_refresh_interval';
    const AVATARS_HTTP                            = 'avatars.http_url';
    const AVATARS_HTTPS                           = 'avatars.https_url';
    const SECURITY_REQUIRE_LOGIN                  = 'security.require_login';
    const WORKER_CHANGE_SAVE_DELAY                = 'queue.worker_change_save_delay';
    const TRANSLATOR_NON_UTF8_ENCODINGS           = 'translator.non_utf8_encodings';
    const PROJECTS_SIDEBAR_FOLLOWERS_DISABLED     = 'projects.sidebar_followers_disabled';
    const DASHBOARD_MAX_ACTIONS                   = 'users.maximum_dashboard_actions';
    const PROJECTS_README_MODE                    = 'projects.readme_mode';
    const PROJECTS_MAX_README_SIZE                = 'projects.max_readme_size';
    const PROJECTS_MAINLINES                      = 'projects.mainlines';

    const STRING           = 'string';
    const INT              = 'int';
    const INT_ALLOW_NULL   = 'intAllowNull';
    const BOOLEAN          = 'boolean';
    const ARRAY_OF_STRINGS = 'arrayOfStrings';

    // Metadata to define types etc (structure should agree with the configuration array)
    private static $configMetaData = array(
        'diffs'   => array(
            'max_diffs'             => array('type' => self::INT),
        ),
        'files'   => array(
            'max_size'              => array('type' => self::INT),
            'download_timeout'      => array('type' => self::INT)
        ),
        'reviews' => array(
            'expand_all_file_limit' => array('type' => self::INT),
            'filters'               => array(
                'result_sorting'    => array('type' => self::BOOLEAN),
                'date_field'        => array('type' => self::STRING, 'valid_values' => array('created', 'updated'))
            ),
            'expand_group_reviewers'          => array('type' => self::BOOLEAN),
            'disable_tests_on_approve_commit' => array('type' => self::BOOLEAN)
        ),
        'projects' => array(
            'sidebar_followers_disabled' => array('type' => self::BOOLEAN),
            'readme_mode' =>
                array('type'  => self::STRING, 'valid_values' => array('disabled', 'restricted', 'unrestricted')),
            'mainlines'       => array('type' => self::ARRAY_OF_STRINGS),
            'max_readme_size' => array('type' => self::INT_ALLOW_NULL)
        ),
        'upgrade' => array(
            'status_refresh_interval' => array('type' => self::INT),
            'batch_size'              => array('type' => self::INT)
        ),
        'avatars' => array(
            'http_url'  => array('type' => self::STRING),
            'https_url' => array('type' => self::STRING)
        ),
        'security' => array(
            'require_login' => array('type' => self::BOOLEAN)
        ),
        'queue' => array(
            'worker_change_save_delay' => array('type' => self::INT)
        ),
        'translator' => array(
            'non_utf8_encodings' => array('type' => self::ARRAY_OF_STRINGS)
        ),
        'users' => array(
            'maximum_dashboard_actions' => array('type' => self::INT)
        )
    );

    /**
     * Gets a value from the config and checks if it is valid. Simple case at the moment - we may
     * want to enhance to add ranges etc.
     * @param $config the config data
     * @param $path the array path (for nested values use dot notation, for example 'reviews.expand_all_file_limit'.
     * @param $metaData optional metadata override
     * @param $default optional default value that will be returned without validation if any problems occur
     * @return mixed|null
     */
    public static function getValue($config, $path, $default = null, $metaData = null)
    {
        // This will throw an exception if the value is not set
        $configValue     = null;
        $metaDataElement = null;
        try {
            $configValue = self::getValueFromConfig($config, $path);
            try {
                $metaDataElement = self::getValueFromConfig($metaData ? $metaData : self::$configMetaData, $path);
            } catch (ConfigException $e) {
                // Something is wrong with our metadata, perhaps a path is asked for that we haven't yet catered for.
                // Don't fail because of this.
                return $configValue;
            }
            // This will throw an exception if validation fails
            $configValue = self::validate($configValue, $metaDataElement, $path);
        } catch (ConfigException $ce) {
            if ($default === null || $metaDataElement === null) {
                throw $ce;
            } else {
                $configValue = self::validateDefault($default, $metaDataElement);
            }
        }
        return $configValue;
    }

    /**
     * Make sure that if a default is provided it fits the metadata definition.
     * @param $value
     * @param $metaDataValue
     * @return null
     * @throws ConfigException
     */
    private static function validateDefault($value, $metaDataValue)
    {
        $type    = $metaDataValue['type'];
        $default = null;
        switch ($type) {
            case self::STRING:
                if (isset($metaDataValue['valid_values']) &&
                    !array_uintersect(array($value), $metaDataValue['valid_values'], 'strcasecmp')) {
                    throw new ConfigException(
                        "Value '" . $value . "' provided as a default must be one of '"
                        . implode(', ', $metaDataValue['valid_values'])
                    );
                }
                $default = strtolower($value);
                break;
            default:
                $default = $value;
                break;
        }
        return $default;
    }

    private static function validate($value, $metaDataValue, $path)
    {
        // Validate all parameters here - currently just type is specified
        $type           = $metaDataValue['type'];
        $convertedValue = null;
        $nullPermitted  = false;
        switch ($type) {
            case self::INT:
                if (ctype_digit(strval($value))) {
                    $convertedValue = intval($value);
                }
                break;
            case self::BOOLEAN:
                if ($value === true || strcasecmp($value, 'true') == 0) {
                    $convertedValue = true;
                } elseif ($value === false || strcasecmp($value, 'false') == 0) {
                    $convertedValue = false;
                }
                break;
            case self::STRING:
                if (is_string($value)) {
                    $convertedValue = strtolower($value);
                    if (isset($metaDataValue['valid_values']) &&
                        !array_uintersect(array($value), $metaDataValue['valid_values'], 'strcasecmp')) {
                        // valid values are specified and the value does not match them
                        $convertedValue = null;
                    }
                }
                break;
            case self::ARRAY_OF_STRINGS:
                // We support setting a single string value that we will convert to an array
                $value = is_string($value) ? (array) $value : $value;
                if (is_array($value)) {
                    $convertedValue = array();
                    foreach ($value as $arrayValue) {
                        if (is_string($arrayValue)) {
                            $convertedValue[] = strtolower($arrayValue);
                            if (isset($metaDataValue['valid_values']) &&
                                !array_uintersect(array($arrayValue), $metaDataValue['valid_values'], 'strcasecmp')) {
                                // valid values are specified and the value does not match them
                                $convertedValue = null;
                                break;
                            }
                        } else {
                            $convertedValue = null;
                            break;
                        }
                    }
                }
                break;
            case self::INT_ALLOW_NULL:
                if ($value === null) {
                    $nullPermitted = true;
                } else {
                    if (ctype_digit(strval($value))) {
                        $convertedValue = intval($value);
                    }
                }
                break;
        }
        if ($convertedValue === null && $nullPermitted !== true) {
            throw new ConfigException(
                "Value '" . (is_array($value) ? var_export($value, true) : $value) .
                "' at path '" . $path . "' is invalid"
            );
        }
        return $convertedValue;
    }

    /**
     * Iterates through the configuration to find a value.
     * @param $config the config
     * @param $path the array path (for nested values use dot notation, for example 'reviews.expand_all_file_limit'.
     * @return mixed
     * @throws \Exception if the path does not exist or the configuration being searched is not an array.
     */
    private static function getValueFromConfig($config, $path)
    {
        $ref  = &$config;
        $keys = explode('.', $path);
        foreach ($keys as $idx => $key) {
            if (!is_array($ref)) {
                throw new ConfigException('Configuration is not an array');
            }
            if (!array_key_exists($key, $ref)) {
                throw new ConfigException("Path '" . $path . "' does not exist");
            }
            $ref = &$ref[$key];
        }
        return $ref;
    }
}
