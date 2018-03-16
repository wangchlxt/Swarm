<?php
/**
 * Very simple queuing system.
 *
 * This is intentionally simple to be fast. We want to queue events
 * quickly so as not to slow down the client (ie. the Perforce Server).
 *
 * To add something to the queue, POST to this script. Up to 1024kB
 * of raw post data will be written to a file in the queue folder.
 * No assumptions are made about the nature of the data (at least not
 * by this script).
 *
 * Each file in the queue is named for the current microtime. It is
 * possible to get collisions under high load or if time moves backward.
 * Therefore, we make 1000 attempts to get a unique name by incrementing
 * a trailing number.
 */

// base data path can come from three possible locations:
// 1) if a path is passed as the first cli argument, it will be used
// 2) otherwise, if the SWARM_DATA_PATH environment variable is set, it will be used
// 3) otherwise, we'll go up a folder from this script then into data
$basePath = getenv('SWARM_DATA_PATH')
    ? (rtrim(getenv('SWARM_DATA_PATH'), '/\\'))
    : (__DIR__ . '/../data');
$basePath = isset($argv[1]) ? $argv[1] : $basePath;

// the queue path is typically data-path/queue, however, when a server-id
// is passed in it becomes base-path/servers/server/queue.
$path   = $basePath . '/queue';
$server = isset($_GET['server']) ? $_GET['server'] : null;
if ($server) {
    require_once __DIR__ . '/../module/Application/SwarmFunctions.php';
    $servers = \Application\SwarmFunctions::getMultiServerConfiguration($basePath);

    if (preg_match('/[^a-z0-9_-]/i', $server)
        || !array_key_exists($server, $servers)
        || !is_dir($basePath . '/servers/' . $server)
    ) {
        queueError(
            404,
            'Not Found',
            'Invalid Perforce Server identifier. Check Swarm configuration file for valid servers.',
            'queue/add attempted with invalid p4 server: "' . $server . '"'
        );
    }
    $path = $basePath . '/servers/' . $server . '/queue';
}

// bail if we didn't get a valid auth token - can be passed as
// second arg for testing, normally passed via get param
$token = isset($argv[2]) ? $argv[2] : null;
$token = $token ?: (isset($_GET['token']) ? $_GET['token'] : null);
$token = preg_replace('/[^a-z0-9\-]/i', '', $token);
if (!strlen($token) || !file_exists($path . '/tokens/' . $token)) {
    queueError(
        401,
        'Unauthorized',
        'Missing or invalid token. View "About Swarm" as a super user for a list of valid tokens.',
        'queue/add attempted with invalid/missing token: "' . $token . '"'
    );
}

// 1000 attempts to get a unique filename.
$path = $path . '/' . sprintf('%015.4F', microtime(true)) . '.';
for ($i = 0; $i < 1000 && !($file = @fopen($path . $i, 'x')); $i++);

// write up to 1024 bytes of input.
// takes from stdin when CLI invoked for testing.
if ($file) {
    $input = fopen(PHP_SAPI === 'cli' ? 'php://stdin' : 'php://input', 'r');
    fwrite($file, fread($input, 1024));
}

function queueError($code, $status, $message, $log) {
    header('HTTP/1.0 ' . $code . ' ' . $status, true, $code);
    echo htmlspecialchars($message) . "\n";

    // try and get this failure into the logs to assist diagnostics
    // don't display the triggered error to the user, we've already done that bit
    ini_set('display_errors', 0);
    trigger_error($log, E_USER_ERROR);

    exit;
}
