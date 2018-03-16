<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Application\Filter;

use Application\Escaper\Escaper;
use Zend\Filter\AbstractFilter;
use Application\Filter\Emojify;
use Application\Filter\Linkify;
use Parsedown;

class Preformat extends AbstractFilter
{
    protected $linkify         = true;
    protected $emojify         = true;
    protected $markdown        = false;
    protected $markdownLimited = false;
    protected $markdownRestrictedElements;
    protected $baseUrl;

    /**
     * Require caller to explicitly specify base url as we can't guess reasonable
     * default value from this scope.
     *
     * @param   string  $baseUrl    base url to prepend to otherwise relative urls
     *                              created by this filter
     */
    public function __construct($baseUrl, $markdown = false, $limited = false)
    {
        $this->setBaseUrl($baseUrl);
        $this->setMarkdown($markdown, $limited);
    }

    /**
     * Attempts to escape and adjust the passed text so it will respect the
     * original whitespace and line breaks but will still allow the text to
     * be wrapped should it over-run its containing element.
     *
     * @param  string $value
     * @return string
     */
    public function filter($value)
    {
        $value     = trim($value);
        $charCount = 50;
        // If the value is greater than $charCount in length and the position of the
        // first new line is greater than $charCount then insert a line break after
        // the first occurrence of '. ' or '! ' or '? ' if it is found
        $newLineFound     = strpos($value, "\n");
        $searchCharsFound = preg_match('/(\. |\! |\? )/', $value, $matches, PREG_OFFSET_CAPTURE);
        if (!$this->markdown) {
            if (count_chars($value) > $charCount && (!$newLineFound || $newLineFound > $charCount)) {
                $replace = true;
                if ($newLineFound) {
                    // Check to make sure the matched end character is before any existing new line
                    $replace = $searchCharsFound == 1 && $matches[0][1] < $newLineFound;
                }
                if ($replace === true) {
                    $value = preg_replace('/(\. |\! |\? )/', "$1\n", $value, 1);
                }
            }
        }
        // if linkification is enabled that will take care of escapement.
        // otherwise we'll escape it ourselves before we get started.
        if ($this->linkify) {
            $linkify = new Linkify($this->baseUrl);
            // If markdown is used we do not want linkify to linkify things within the code block
            if ($this->markdown) {
                Linkify::addCallback(array($linkify, "excludeCodeBlock"), "excludeCodeBlock", 0);
            }
            $value = $linkify->filter($value);
        } else {
            // escape any html before we get started
            if (!$this->markdown) {
                $escaper = new Escaper;
                $value   = $escaper->escapeHtml($value);
            }
        }

        // if emojify is on, apply it after linking and escaping
        if ($this->emojify) {
            $emojify = new Emojify($this->baseUrl);
            $value   = $emojify->filter($value);
        }

        // if markdown is on, apply it
        if ($this->markdown) {
            $filter = new Parsedown();
            $filter
                ->setBreaksEnabled(true)->setParagraphsAsSpan(true)
                ->setLimited($this->markdownLimited, $this->markdownRestrictedElements)
                ->setReturnAsArray(true);
            $value   = $filter->text($value);
            $markup  = '<span class="first-line">' . trim($value[0]) . "</span>";
            $value   = array_slice($value, 1);
            $markup .= !empty($value) ? '<span class="more-lines">' . implode($value) . '</span>' : '';

            $value = $markup;
        } else {
            // turn tabs into four spaces
            $value = str_replace("\t", "    ", $value);

            // remove trailing new lines
            $value = rtrim($value, "\n");

            // replace two spaces in a row with nbsp followed by space
            // to allow normal word-wrapping to occur. we do two runs
            // of this to catch odd numbers of spaces where we end up
            // with nbsp space space on our first go of things.
            // we also ensure lines that lead with a single space turn
            // into a nbsp as the browser otherwise skips the space.
            $value = str_replace("  ",  "&nbsp; ",   $value);
            $value = str_replace("  ",  " &nbsp;",   $value);
            $value = str_replace("\n ", "\n &nbsp;", $value);
            $value = str_replace("\n",  "<br>\n",    $value);

            $lines  = explode("<br>", $value, 2);
            $value  = '<span class="first-line">' . trim($lines[0]) . "</span>";
            $value .= isset($lines[1]) ? '<br><span class="more-lines">' . $lines[1] . '</span>' : '';
        }
        return $value;
    }

    /**
     * Base url to prepend to otherwise relative urls.
     *
     * @param   string|null     $baseUrl    the base url to prepend (e.g. http://example.com, /path, etc) or null
     * @return  Preformat       to maintain a fluent interface
     */
    public function setBaseUrl($baseUrl)
    {
        $this->baseUrl = $baseUrl;
        return $this;
    }

    /**
     * The base url that will be prepended to otherwise relative urls.
     *
     * @return  string|null     the base url to prepend (e.g. http://example.com, /path, etc) or null
     */
    public function getBaseUrl()
    {
        return $this->baseUrl;
    }

    /**
     * If enabled (default) the passed text will be linkified before it is preformatted.
     *
     * @param   bool        $enabled    true to enable linkification, false otherwise
     * @return  Preformat   to maintain a fluent interface
     */
    public function setLinkify($enabled)
    {
        $this->linkify = (bool)$enabled;
        return $this;
    }

    /**
     * If enabled the passed text will be parsed through Markdown.
     *
     */
    public function setMarkdown($enabled, $limited)
    {
        $this->markdown                   = (bool)$enabled;
        $this->markdownLimited            = (bool)$enabled;
        $this->markdownRestrictedElements = array('h1', 'h2', 'h3', 'h4', 'h5', 'h6');
        return $this;
    }

    /**
     * Get current linkification setting.
     *
     * @param   bool    true if linkification is enabled, false otherwise
     */
    public function getLinkify()
    {
        return $this->linkify;
    }

    /**
     * If enabled (default) the passed text will be emojified before it is preformatted.
     *
     * @param   bool        $enabled    true to enable emojification, false otherwise
     * @return  Preformat   to maintain a fluent interface
     */
    public function setEmojify($enabled)
    {
        $this->emojify = (bool)$enabled;
        return $this;
    }

    /**
     * Get current emojification setting.
     *
     * @param   bool    true if emojification is enabled, false otherwise
     */
    public function getEmojify()
    {
        return $this->emojify;
    }
}
