<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

return array(
    'xhprof' => array(
        'slow_time'            => 3,          // time, in seconds, beyond which page loads are considered "slow"
        'report_file_lifetime' => 86400 * 7,  // clean up stale xhprof reports older than one week
        'ignored_routes'       => array(),    // a list of long-running routes to ignore
    ),
);
