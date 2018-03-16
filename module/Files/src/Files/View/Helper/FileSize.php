<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Files\View\Helper;

use Zend\View\Helper\AbstractHelper;

class FileSize extends AbstractHelper
{
    private static $suffixes = array();

    /**
     * Builds translated file suffixes
     * @param $translator
     */
    private function buildSuffixes($translator)
    {
        if (empty(FileSize::$suffixes)) {
            FileSize::$suffixes = array(
                $translator->t('B'),
                $translator->t('KB'),
                $translator->t('MB'),
                $translator->t('GB'),
                $translator->t('TB'),
                $translator->t('PB')
            );
        }
    }

    /**
     * Converts the given filesize from bytes to a human-friendly format.
     * E.g. 12KB, 100MB
     *
     * @param   string|int  $size   the file size in bytes
     * @return  string      the formatted file size
     */
    public function __invoke($size)
    {
        $services   = $this->getView()->getHelperPluginManager()->getServiceLocator();
        $translator = $services->get('translator');
        FileSize::buildSuffixes($translator);

        $result = $size;
        $index  = 0;
        while ($result >= 1024 && $index++ < count(FileSize::$suffixes)) {
            $result = $result / 1024;
        }

        // 2 decimal points for sizes > MB.
        $precision = $index > 1 ? 2 : 0;
        $result    = round($result, $precision);

        return $result . " " . FileSize::$suffixes[$index];
    }
}
