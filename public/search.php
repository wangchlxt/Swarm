<?php
/**
 * Fast search endpoint
 *
 * This script is stand-alone to provide quick search results.
 * We anticipate the client to make requests as the user types.
 * Therefore, we want to eliminate as much overhead as possible.
 */

// @codingStandardsIgnoreStart

// in order to unserialize cached project data, we need a fielded iterator
// we need to use a namespace block to properly fake the expected iterator
namespace P4\Model\Fielded { class Iterator extends \ArrayIterator {} }

// everything else goes in the global namespace as per usual
namespace {
    error_reporting(error_reporting() & ~(E_STRICT|E_NOTICE));

    define('BASE_PATH', dirname(__DIR__));

    // allow BASE_DATA_PATH to be overridden via an environment variable
    define(
        'BASE_DATA_PATH',
        getenv('SWARM_DATA_PATH') ? rtrim(getenv('SWARM_DATA_PATH'), '/\\') : BASE_PATH . '/data'
    );
    // config is needed for p4 parameters
    $config = BASE_DATA_PATH . '/config.php';
    $config = file_exists($config) ? include $config : null;

    // detect a multi-p4-server setup and define
    // associated constants (MULTI_P4_SERVER, P4_SERVER_ID, DATA_PATH)
    require_once __DIR__ . '/../module/Application/SwarmFunctions.php';
    \Application\SwarmFunctions::configureEnvironment(BASE_DATA_PATH, isset($_GET['server']) ? $_GET['server'] : null);
    // The build will generate swarm_class_map.php. It can be generated directly
    // with 'ant generate-classmap'.
    // We need to be able to load classes for unserialization of users, projects and groups to work
    if (file_exists(BASE_PATH . '/library/Zend/swarm_class_map.php')) {
        // setup mapped autoloading
        require_once __DIR__ . '/../library/Zend/Loader/ClassMapAutoloader.php';
        $loader = new Zend\Loader\ClassMapAutoloader();

        // Register the class map:
        $loader->registerAutoloadMap(BASE_PATH . '/library/Zend/swarm_class_map.php');

        // Register with spl_autoload:
        $loader->register();
    } else {
        // setup expensive autoloading
        set_include_path(BASE_PATH);
        include 'library/Zend/Loader/AutoloaderFactory.php';
        Zend\Loader\AutoloaderFactory::factory(
            array(
                'Zend\Loader\StandardAutoloader' => array(
                    'namespaces'   => array(
                        'P4'       => BASE_PATH . '/library/P4',
                        'Record'   => BASE_PATH . '/library/Record',
                        'Zend'     => BASE_PATH . '/library/Zend',
                        'Projects' => BASE_PATH . '/module/Projects/src/Projects',
                        'Users'    => BASE_PATH . '/module/Users/src/Users'
                    )
                )
            )
        );
    }

    // all of our responses should be interpreted as json
    header('Content-type: application/json; charset=utf-8');

    // if login required, enforce it
    if (isset($config['security']['require_login'])
        && $config['security']['require_login']
        && !getIdentity($config)
    ) {
        header($_SERVER['SERVER_PROTOCOL'] . ' 401 Unauthorized');
        exit;
    }

    // break up query into keywords - no keywords, no results!
    $keywords = preg_split('/[\s\/]+/', isset($_GET['q']) ? $_GET['q'] : '');
    $keywords = array_unique(array_filter($keywords, 'strlen'));
    if (!$keywords) {
        echo json_encode(array());
        exit;
    }

    // supported query parameters:
    //  - specific types of things to search for (required)
    //  - maximum results to return (default 50)
    $max     = isset($_GET['max'])   ? (int) $_GET['max'] : 50;
    $types   = isset($_GET['types']) ? array_flip((array) $_GET['types']) : array();
    $results = array();

    // search projects
    if (isset($types['projects'])) {
        $cache = getLatestCache('projects', $config);
        if ($cache) {
            $projects = unserialize(file_get_contents($cache));

            // prepare list with project ids the current user can access; we will use it
            // to determine access to private projects
            // for anonymous users, no private projects are accessible
            // for authenticated users, only grant access to private projects that are
            // among the accessible projects for the current user
            $accessibleProjects = getIdentity($config) ? getProjectIds() : array();

            foreach ($projects as $id => $project) {
                $values = $project->getValuesArray();
                // exclude deleted projects from the result
                if (isset($values['deleted']) && $values['deleted']) {
                    continue;
                }

                // skip if project is private and not accessible by the current user
                $isPrivate = isset($values['private']) && $values['private'];
                if ($isPrivate && !in_array($id, $accessibleProjects)) {
                    continue;
                }

                $score = getMatchScore($values, 'name', $keywords);
                if ($score !== false) {
                    $results[] = array(
                        'type'    => 'project',
                        'id'      => $id,
                        'label'   => $values['name'],
                        'detail'  => substr($values['description'], 0, 250),
                        'score'   => $score + 5,
                        'private' => $isPrivate
                    );
                }
            }
        }
    }

    // search users
    if (isset($types['users'])) {
        $cache = getLatestCache('users', $config);
        if ($cache) {
            // if filesize is greater than 2MB, stream the data for memory savings
            if (filesize($cache) > 1024 * 1024 * 2) {
                $users = new Record\Cache\ArrayReader($cache);
                $users->openFile();
            } else {
                $users = unserialize(file_get_contents($cache));
            }
            foreach ($users as $user) {
                $values = $user->getValuesArray();
                $score = getMatchScore($values, array('User', 'FullName'), $keywords);
                if ($score !== false) {
                    $results[] = array(
                        'type'   => 'user',
                        'id'     => $values['User'],
                        'detail' => $values['FullName'],
                        'score'  => $score
                    );
                }
            }
        }
    }

    // search groups
    if (isset($types['groups'])) {
        $cache = getLatestCache('groups', $config);
        if ($cache) {
            // if filesize is greater than 2MB, stream the data for memory savings
            if (filesize($cache) > 1024 * 1024 * 2) {
                $groups = new Record\Cache\ArrayReader($cache);
                $groups->openFile();
            } else {
                $groups = unserialize(file_get_contents($cache));
            }
            foreach ($groups as $group) {
                // exclude the project groups from search result
                if (strpos($group['Group'], 'swarm-project-') === 0) {
                    continue;
                }
                // not all groups will have config data attached (especially those created outside of Swarm)
                // therefore, we are defensive when preparing values to pass to getMatchScore() and
                // searching groups by id, name and description.
                $values = array(
                    'id'          => $group['Group'],
                    'label'       => isset($group['config']['name'])        ? $group['config']['name']        : $group['Group'],
                    'description' => isset($group['config']['description']) ? $group['config']['description'] : ''
                );
                $score = getMatchScore($values, array('id', 'label', 'description'), $keywords);
                if ($score !== false) {
                    $results[] = array(
                        'type'   => 'group',
                        'id'     => $values['id'],
                        'label'  => $values['label'],
                        'detail' => $values['description'],
                        'score'  => $score
                    );
                }
            }
        }
    }

    // search file names (optionally scoped to a path and/or a project)
    $path    = trim($_GET['path'], '/');
    $project = $_GET['project'];
    if (isset($types['files-names'])) {
        $p4              = getP4($config);
        $p4->client      = $project ? 'swarm-project-' . $project : $p4->client;
        $p4->maxlocktime = isset($config['search']['maxlocktime'])
            ? $config['search']['maxlocktime']
            : 5000;

        // if we have no path, search shallow and include dirs
        $path   = trim($project ? $p4->client . "/$path" : $path, "/");
        $dirs   = !$path;
        $path   = $path ? "//$path/..." : "//*/*";

        $lower  = function_exists('mb_strtolower') ? 'mb_strtolower' : 'strtolower';
        $upper  = function_exists('mb_strtoupper') ? 'mb_strtoupper' : 'strtoupper';
        $filter = '';
        foreach ($keywords as $keyword) {
            $regex = preg_replace_callback('/(.)/u', function($matches) use ($lower, $upper) {
                return '[\\' . $lower($matches[0]) . '\\' . $upper($matches[0]) . ']';
            }, $keyword);
            $filter .= ' depotFile~=' . $regex;
            $filter .= $dirs ? '|dir~=' . $regex : '';
        }

        try {
            $files = runP4(
                $p4,
                'fstat',
                '-m ' . $max * 5,
                $dirs ? '-Dx' : '',
                '-T depotFile' . ($dirs ? ',dir' : ''),
                '-F' .
                $filter,
                $path
            );
            foreach ($files as $file) {
                $file['path']     = isset($file['depotFile']) ? $file['depotFile'] : $file['dir'];
                $file['basename'] = basename($file['path']);
                $score            = getMatchScore($file, 'path',     $keywords) * .5;
                $score           += getMatchScore($file, 'basename', $keywords) * .5;
                if ($score !== false) {
                    $results[] = array(
                        'type'   => 'file',
                        'id'     => $file['path'],
                        'label'  => $file['basename'],
                        'detail' => $file['path'],
                        'score'  => $score
                    );
                }
            }
        } catch (\Exception $e) {
            // ignore errors
        }
    }

    // search file contents
    if (isset($types['files-contents']) && isset($config['search']['p4_search_host'])) {
        $host     = trim($config['search']['p4_search_host'], '/');
        $identity = getIdentity($config);
        if ($identity && strlen($host)) {
            $url   = $host . '/api/search';
            $query = array(
                'userId'       => $identity['id'],
                'ticket'       => $identity['ticket'],
                'query'        => isset($_GET['q']) ? $_GET['q'] : '',
                'paths'        => array(), // empty paths make the query much faster
                'rowCount'     => $max,
                'resultFormat' => 'DETAILED'
            );
            $context  = stream_context_create(
                array(
                    'http' => array(
                        'method'  => 'POST',
                        'header'  => 'Content-Type: application/json',
                        'content' => json_encode($query)
                    )
                )
            );
            $response = json_decode(file_get_contents($url, false, $context), true);
            $payload  = isset($response['payload']) ? $response['payload'] : array();
            $matches  = isset($payload['detailedFilesModels']) ? $payload['detailedFilesModels'] : array();
            $maxScore = isset($payload['maxScore']) ? $payload['maxScore'] : null;
            foreach ($matches as $match) {
                $match    += array('filesModel' => array(), 'score' => 0);
                $file      = $match['filesModel'] + array('depotFile' => null);
                $score     = ($maxScore ? $match['score'] / $maxScore * 100 : $match['score']) * .5;
                $score    += getMatchScore($file, 'depotFile', $keywords) * .5;
                $results[] = array(
                    'type'   => 'file',
                    'id'     => $file['depotFile'],
                    'label'  => basename($file['depotFile']),
                    'detail' => $file['depotFile'],
                    'score'  => $score
                );
            }
        }
    }

    // sort matches by score (secondary sort by label)
    usort($results, function($a, $b) {
        $difference = $b['score'] - $a['score'];
        return $difference ?: strnatcasecmp($a['label'], $b['label']);
    });

    // limit to max results
    $results = $max > 0 ? array_slice($results, 0, $max) : $results;

    echo json_encode($results);

    function getIdentity($config)
    {
        $config = isset($config['session']) ? $config['session'] : array();

        // session cookie name is configurable
        // default is SWARM[-<PORT>] the port is only appended if not 80 or 443
        if (isset($config['name'])) {
            $name = $config['name'];
        } else {
            $server = $_SERVER + array('HTTP_HOST' => '', 'SERVER_PORT' => null);
            preg_match('/:(?P<port>[0-9]+)$/', $server['HTTP_HOST'], $matches);
            $port   = isset($matches['port']) && $matches['port'] ? $matches['port'] : $server['SERVER_PORT'];
            $name   = 'SWARM' . ($port == 80 || $port == 443 ? '' : '-' . $port);
        }

        // if no session cookie, then no identity
        if (!isset($_COOKIE[$name])) {
            return null;
        }

        // session save path is also configurable
        $path    = isset($config['save_path']) ? $config['save_path'] : DATA_PATH . '/sessions';
        $path    = rtrim($path, '/') . '/sess_' . $_COOKIE[$name];
        $session = is_readable($path) ? file_get_contents($path) : null;
        $pattern = '/Zend_Auth[^}]+id";s:[0-9]+:"([^"]+)"[^}]+ticket";(?:s:[0-9]+:"([^"]+)"|N);/';
        preg_match($pattern, $session, $matches);

        // return array containing two elements id and ticket or null if not auth'd
        $matches += array(null, null, null);
        return strlen($matches[1]) ? array('id' => $matches[1], 'ticket' => $matches[2]) : null;
    }

    function getLatestCache($key, $config)
    {
        $config  = P4_SERVER_ID ? $config['p4'][P4_SERVER_ID] : $config['p4'];
        $config += array('port' => null, 'user' => null, 'password' => null);
        $port    = isset($config['port']) ? $config['port'] : null;
        $pattern = DATA_PATH . '/cache/' . strtoupper(md5($port)) . '-' . strtoupper(bin2hex($key)) . '-*[!a-z]';
        $files   = glob($pattern, GLOB_NOSORT);
        natsort($files);
        return end($files);
    }

    function getMatchScore($values, $fields, array $keywords)
    {
        $score = 100;
        foreach ($keywords as $keyword) {
            $distance = null;
            foreach ((array) $fields as $field) {
                $value = isset($values[$field]) ? $values[$field] : null;
                if (($current = stripos($value, $keyword)) !== false) {
                    $current += (strlen($value) - strlen($keyword)) / 5;
                    if ($distance === null || $current < $distance) {
                        $distance = $current;
                    }
                }
            }
            if ($distance === null) {
                return false;
            }
            $score -= $distance;
        }

        return $score;
    }

    function getProjectIds()
    {
        // determine base path for the url:
        // - if in multi-p4d mode, its simply the server id
        // - otherwise check the request uri, if Swarm is running under a sub-folder,
        //   the base path should be before /search?<query_params>
        $basePath = P4_SERVER_ID
            ? '/' . P4_SERVER_ID
            : preg_replace('#/search$#', '', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

        // return ids of all projects that are accessible for the current user
        $server  = $_SERVER + array('HTTP_HOST' => '', 'HTTPS' => null, 'HTTP_COOKIE' => '');
        $url     = 'http' . ($server['HTTPS'] ? 's' : '') . '://' . rtrim($server['HTTP_HOST'], '/') . $basePath;
        $context = stream_context_create(
            array(
                'http' => array(
                    'header' => 'Cookie: ' . $server['HTTP_COOKIE'] . "\r\n",
                )
            )
        );

        return json_decode(
            file_get_contents($url . '/projects?idsOnly=true', false, $context),
            true
        );
    }

    function getP4($config)
    {
        // to facilitate SSL connections, specify the path to the trust file which
        // Swarm should have auto-generated upon establishing trust to the server
        putenv('P4TRUST=' . DATA_PATH . '/p4trust');

        $identity     = (array) getIdentity($config) + array('id' => null, 'ticket' => null);
        $config       = P4_SERVER_ID ? $config['p4'][P4_SERVER_ID] : $config['p4'];
        $config      += array('port' => null, 'user' => null, 'password' => null);
        $p4           = new \P4;
        $p4->prog     = 'SWARM_SEARCH';
        $p4->port     = $config['port'];
        $p4->user     = $identity['id']     ?: $config['user'];
        $p4->password = $identity['ticket'] ?: $config['password'];
        $p4->tagged   = true;
        $p4->connect();

        return $p4;
    }

    function runP4()
    {
        $arguments = func_get_args();
        $p4        = array_shift($arguments);
        $arguments = array_filter($arguments, 'strlen');

        // detect unicode error and re-run with charset if needed
        try {
            return call_user_func_array(array($p4, 'run'), $arguments);
        } catch (\Exception $e) {
            if (stripos($e->getMessage(), 'unicode server') === false) {
                throw $e;
            }
            $p4->charset = 'utf8unchecked';
            return call_user_func_array(array($p4, 'run'), $arguments);
        }
    }
}
