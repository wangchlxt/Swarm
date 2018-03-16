<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Application\View\Helper;

use Zend\I18n\Exception;
use Zend\I18n\View\Helper\AbstractTranslatorHelper;

class TranslatePluralEscape extends AbstractTranslatorHelper
{
    public function __invoke(
        $singular,
        $plural,
        $number,
        array $replacements = null,
        $context = null,
        $textDomain = 'default',
        $locale = null
    ) {
        if ($this->translator === null) {
            throw new Exception\RuntimeException('Translator has not been set');
        }

        return $this->translator->translatePluralReplaceEscape(
            $singular,
            $plural,
            $number,
            $replacements,
            $context,
            $textDomain,
            $locale
        );
    }
}
