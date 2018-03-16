<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace P4\Filter;

class Utf8
{
    const REPLACE_INVALID    = 'replace';
    const CONVERT_ENCODING   = 'convert';
    const NON_UTF8_ENCODINGS = 'non_utf8_encodings';
    const FALLBACK_ENCODING  = 'windows-1252';

    public static $fallbackEncodings = array(Utf8::FALLBACK_ENCODING);

    protected $options = array(
        'replace'               => true,
        'convert'               => false
    );

    /**
     * Constructor, can optionally take options :)
     *
     * @param array|bool    $replaceOrOptions   options array or setting for 'replace invalid'
     * @param bool          $convert            convert enabled/disabled
     */
    public function __construct($replaceOrOptions = true, $convert = false)
    {
        if (is_array($replaceOrOptions)) {
            $this->setOptions($replaceOrOptions);
        } else {
            $this->setReplaceInvalid($replaceOrOptions);
        }

        $this->setConvertEncoding($convert);
    }

    /**
     * Sets all options for this filter.
     *
     * @param   array   $options    options to use
     * @return  $this   to maintain a fluent interface
     */
    public function setOptions($options)
    {
        $this->options = $options + array(
            static::REPLACE_INVALID  => true,
            static::CONVERT_ENCODING => false
        );
        // Go through the setter for encodings to make sure they are validated
        if (isset($options[static::NON_UTF8_ENCODINGS])) {
            $this->setNonUtf8Encodings($options[static::NON_UTF8_ENCODINGS]);
        }

        return $this;
    }

    /**
     * Returns all options for this filter
     *
     * @return  array   options
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Used to update or access the 'replace invalid' option.
     *
     * If enabled, invalid UTF-8 byte sequences will be replaced with
     * an inverted question mark.
     *
     * @param   bool    $enabled    pass true/false to enable disable
     * @return  Utf8    provides a fluent interface
     */
    public function setReplaceInvalid($enabled)
    {
        $this->options[static::REPLACE_INVALID] = (bool) $enabled;
        return $this;
    }

    /**
     * Returns the current replace invalid setting. Default is true.
     *
     * @return  bool    true if invalid bytes will be replaced false otherwise
     */
    public function getReplaceInvalid()
    {
        return $this->options[static::REPLACE_INVALID];
    }

    /**
     * Sets the encodings to try for non-utf8 files. Any unsupported values
     * are filtered out.
     * @param $nonUtf8Encodings
     * @return $this
     */
    public function setNonUtf8Encodings($nonUtf8Encodings)
    {
        $validEncodings = $nonUtf8Encodings;
        if ($nonUtf8Encodings === null || !is_array($nonUtf8Encodings) || empty($nonUtf8Encodings)) {
            $validEncodings = static::$fallbackEncodings;
        } else {
            if (function_exists('mb_list_encodings')) {
                $validEncodings = array_intersect(
                    array_map('strtolower', $nonUtf8Encodings),
                    array_map('strtolower', mb_list_encodings())
                );
            }
        }

        $this->options[static::NON_UTF8_ENCODINGS] = $validEncodings;
        return $this;
    }

    /**
     * Gets the encodings to try for non-utf8 files falling back to static::$fallbackEncodings
     * if not specified
     * @return mixed
     */
    public function getNonUtf8Encodings()
    {
        if (!isset($this->options[static::NON_UTF8_ENCODINGS]) || empty($this->options[static::NON_UTF8_ENCODINGS])) {
            return static::$fallbackEncodings;
        } else {
            return $this->options[static::NON_UTF8_ENCODINGS];
        }
    }

    /**
     * Used to update or access the 'convert encoding' option.
     *
     * If enabled, input which contain high bytes but no valid utf-8 character
     * sequences will be assumed to be Windows-1252 (ISO-8859-1 compatible)
     * and converted to UTF-8 prior to further processing.
     *
     * @param   bool    $enabled    pass true/false to enable disable
     * @return  Utf8    provides a fluent interface
     */
    public function setConvertEncoding($enabled)
    {
        $this->options[static::CONVERT_ENCODING] = (bool) $enabled;
        return $this;
    }

    /**
     * Returns the current convert setting. Default is false.
     *
     * @return  bool    true if encoding conversion will be attempted false otherwise
     */
    public function getConvertEncoding()
    {
        return $this->options[static::CONVERT_ENCODING];
    }

    /**
     * This filter performs two primary operations; both can be enabled/disabled.
     *
     * The filter will check for strings containing high bits but no valid UTF-8
     * characters. These will be converted from Windows-1252 to UTF-8. This feature
     * is disabled by default.
     *
     * This filter will also replace all invalid utf-8 byte sequences with the
     * character '�'. This feature is enabled by default.
     *
     * @param  string|array     $value  utf8 text that may contain invalid byte sequences.
     * @return string           valid utf8 version of text; invalid bytes replaced with '�'.
     */
    public function filter($value)
    {
        // if we were passed an array, recursively sanitize its values.
        if (is_array($value)) {
            foreach ($value as $key => $element) {
                $value[$key] = $this->filter($element);
            }

            return $value;
        }

        // only sanitize strings.
        if (!is_string($value)) {
            return $value;
        }

        if ($this->getConvertEncoding()) {
            $value = $this->convertEncoding($value);
        }

        if ($this->getReplaceInvalid()) {
            $value = $this->replaceInvalid($value);
        }

        return $value;
    }

    /**
     * For inputs which contain high bytes but _no_ valid UTF-8 byte sequences
     * this method will assume the input is in Windows-1252 (ISO-8859-1 compatible)
     * encoding and convert it to UTF-8 assuming mb_string_convert or iconv are
     * available.
     *
     * @param   string  $value  text that may be in UTF-8, Windows-1252 or ISO-8859-1 format.
     * @return  string  utf8 version of text; invalid byte sequences may be present.
     */
    protected function convertEncoding($value)
    {
        // nothing to do if we lack support for encoding conversion
        if (!function_exists('mb_convert_encoding') && !function_exists('iconv')) {
            return $value;
        }

        // if no high bytes are present, no conversion needed
        if (!preg_match('/[\x80-\xFF]/', $value)) {
            return $value;
        }

        // start by checking if the input has at least one valid utf-8 sequence in it
        $hasUtf8 = preg_match(
            '/(
              [\xC0-\xDF][\x80-\xBF]                            # Two byte sequence
            | [\xE0-\xEF][\x80-\xBF]{2}                         # Three byte
            | [\xF0-\xF7][\x80-\xBF]{3}                         # Four byte
            | [\xF8-\xFB][\x80-\xBF]{4}                         # Five byte
            | [\xFC-\xFD][\x80-\xBF]{5}                         # Six byte
            )/x',
            $value
        );

        // if we have a single valid utf-8 byte, assume utf-8 input
        if ($hasUtf8) {
            return $value;
        }

        $from = Utf8::FALLBACK_ENCODING;

        if (function_exists('mb_detect_encoding')) {
            $from = mb_detect_encoding($value, $this->getNonUtf8Encodings());
            // Encodings may have been valid but failed to translate
            $from = $from === false ? Utf8::FALLBACK_ENCODING : $from;
        }

        // last ditch check; if invalid Windows-1252 bytes are present swap
        // to mac os roman, it just might be that :)
        if (preg_match('/[\x81\x8D\x8F\x90\x9D]/', $value)) {
            $from = 'macintosh';
        }

        // convert from input format, mb_convert doesn't support macintosh
        // but is otherwise our first choice as we expect its faster.
        if ($from != 'macintosh' && function_exists('mb_convert_encoding')) {
            $value = mb_convert_encoding($value, 'utf-8', $from);
        } elseif (function_exists('iconv')) {
            $value = iconv($from, 'utf-8', $value);
        }

        return $value;
    }

    /**
     * This method will replace all invalid utf-8 byte sequences with the character '�'.
     *
     * @param   string  $value  utf8 text that may contain invalid byte sequences.
     * @return  string  valid utf8 version of text; invalid bytes replaced with '�'.
     */
    protected function replaceInvalid($value)
    {
        // looks like we got a scalar, use our regex to sanitize it
        // we use a regex instead of mb_convert_encoding as the mbstring module may not be present and
        // our replacement character is ignored on php 5.3. we can't use iconv as it too might not be
        // present and it simply drops bad bytes.
        $value = preg_replace(
            '/(
              [\xC0-\xC1]                                           # Invalid UTF-8 Bytes
            | [\xF5-\xFF]                                           # Invalid UTF-8 Bytes
            | \xE0[\x80-\x9F]                                       # Overlong encoding of prior code point
            | \xF0[\x80-\x8F]                                       # Overlong encoding of prior code point
            | [\xC2-\xDF](?![\x80-\xBF])                            # Invalid UTF-8 Sequence Start
            | [\xE0-\xEF](?![\x80-\xBF]{2})                         # Invalid UTF-8 Sequence Start
            | [\xF0-\xF4](?![\x80-\xBF]{3})                         # Invalid UTF-8 Sequence Start
            | (?<=[\x0-\x7F\xF5-\xFF])[\x80-\xBF]                   # Invalid UTF-8 Sequence Middle
            | (?<![\xC2-\xDF]|[\xE0-\xEF]|[\xE0-\xEF][\x80-\xBF]    # Overlong Sequence
                |[\xF0-\xF4]|[\xF0-\xF4][\x80-\xBF]|[\xF0-\xF4]
                [\x80-\xBF]{2})[\x80-\xBF]
            | (?<=[\xE0-\xEF])[\x80-\xBF](?![\x80-\xBF])            # Short 3 byte sequence
            | (?<=[\xF0-\xF4])[\x80-\xBF](?![\x80-\xBF]{2})         # Short 4 byte sequence
            | (?<=[\xF0-\xF4][\x80-\xBF])[\x80-\xBF](?![\x80-\xBF]) # Short 4 byte sequence (2)
            )/x',
            "�",
            $value
        );

        return $value;
    }
}
