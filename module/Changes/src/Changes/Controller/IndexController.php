<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Changes\Controller;

use Application\Filter\Preformat;
use Application\Permissions\Protections;
use Application\Permissions\Exception\ForbiddenException;
use Application\Config\ConfigManager;
use Comments\Model\Comment;
use P4\Connection\Exception\CommandException;
use P4\Model\Fielded\Iterator as FieldedIterator;
use P4\Spec\Change;
use P4\Spec\Depot;
use P4\Spec\Exception\NotFoundException;
use Projects\Model\Project;
use Reviews\Model\Review;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;
use Zend\View\Model\ViewModel;

class IndexController extends AbstractActionController
{
    public function changeAction()
    {
        $services = $this->getServiceLocator();
        $config   = $services->get('config');
        $p4Config = $services->get('p4_config');
        $p4       = $services->get('p4');
        $id       = $this->getEvent()->getRouteMatch()->getParam('change');
        $archiver = $services->get('archiver');

        try {
            $change = Change::fetchById($id, $p4);
        } catch (NotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }

        // if we got a not found or invalid argument exception
        // send a more appropriate 404
        if (!isset($change)) {
             $this->getResponse()->setStatusCode(404);
             return;
        }

        // send 403 if change is not accessible
        if (!$change->canAccess()) {
            throw new ForbiddenException("You don't have permission to view this change.");
        }

        // if there is a review with the same id; we should view it
        // review changes should always be pending but we get original
        // id just to make sure the check is done correctly.
        $p4Admin = $this->getServiceLocator()->get('p4_admin');
        if (Review::exists($change->getOriginalId(), $p4Admin)) {
            return $this->redirect()->toRoute('review', array('review' => $change->getId()));
        }

        // get the review(s) associated with the change
        $reviews = Review::fetchAll(array(Review::FETCH_BY_CHANGE => $id), $p4Admin);

        // remember if there are reviews associated and filter out reviews the current user cannot access
        $hasReviews = $reviews->count() > 0;
        $reviews    = $services->get('projects_filter')->filter($reviews, 'projects');

        // for pending changes, view the associated review if the change is its archive change
        if ($change->isPending()) {
            foreach ($reviews as $review) {
                $version = $review->getVersionOfChange($id);
                if ($version !== false) {
                    return $this->redirect()->toRoute(
                        'review',
                        array(
                            'review'  => $review->getId(),
                            'version' => $version
                        )
                    );
                }
            }
        }

        // our change may have been re-numbered; redirect to the proper page if so
        if ($change->getId() != $id) {
            return $this->redirect()->toRoute('change', array('change' => $change->getId()));
        }

        // get file data and supplement w. add/edit/delete info
        // note we fetch max + 1 so that we know if we've exceeded max.
        $max   = isset($p4Config['max_changelist_files']) ? (int) $p4Config['max_changelist_files'] : 1000;
        $files = $change->getFileData(true, $max ? $max + 1 : null);

        $expandAllLimit = ConfigManager::getValue($config, ConfigManager::REVIEWS_EXPAND_ALL);
        $fileCount      = count($files);
        $allowExpand    = $expandAllLimit == 0 || $fileCount <= $expandAllLimit;

        // if we've exceeded max files, indicate we've cropped the file list and drop the last element
        if ($max && $fileCount > $max) {
            $cropped = true;
            array_pop($files);
        }

        // prior to processing, filter files to comply with user's IP-based protections
        $ipProtects = $this->getServiceLocator()->get('ip_protects');
        $files      = $ipProtects->filterPaths($files, Protections::MODE_LIST, 'depotFile');

        $counts = array('adds' => 0, 'edits' => 0, 'deletes' => 0);
        foreach ($files as &$file) {
            $file['isAdd']      = preg_match('/add|branch|import/', $file['action']) !== 0;
            $file['isEdit']     = preg_match('/add|branch|import|delete/', $file['action']) === 0;
            $file['isDelete']   = strpos($file['action'], 'delete') !== false;
            $counts['adds']    += (int) $file['isAdd'];
            $counts['edits']   += (int) $file['isEdit'];
            $counts['deletes'] += (int) $file['isDelete'];
        }

        $mentions = array();
        // determine whether user can request review (i.e. if not under review already and if review will be accessible)
        if ($hasReviews) {
            // check mentions settings, can be one of:
            // - disabled
            // - enabled for all users and all groups in all comments
            // - enabled only for project users and groups in review that has a project (default)
            switch ($config['mentions']['mode']) {
                case 'disabled':
                case 'global':
                    break;
                default:
                    $mentions = Comment::getPossibleMentions('changes/' . $change->getId(), $config, $p4Admin);
            }
        } else {
            $projects         = Project::getAffectedByChange($change, $p4Admin);
            $canRequestReview = !$projects || $services->get('projects_filter')->filterList($projects);
        }

        return new ViewModel(
            array(
                'change'           => $change,
                'isRemoteShelf'    => $change->isRemoteEdgeShelf(),
                'files'            => $files,
                'counts'           => $counts,
                'max'              => $max,
                'cropped'          => isset($cropped) ? true : false,
                'review'           => !$reviews->count() && $hasReviews ? 'private' : $reviews->first(),
                'canRequestReview' => isset($canRequestReview) ? $canRequestReview : null,
                'jobs'             => $this->getFormattedJobs($change),
                'ignoreWs'         => $config['diffs']['ignore_whitespace_default'],
                'canArchive'       => $archiver->canArchive() && ($counts['adds'] + $counts['edits'] > 0),
                'mentionsMode'     => $config['mentions']['mode'],
                'mentions'         => $mentions,
                'allowExpand'      => $allowExpand,
                'fileCount'        => $fileCount,
                'expandAllLimit'   => $expandAllLimit
            )
        );
    }

    public function changesAction()
    {
        $request = $this->getRequest();
        $route   = $this->getEvent()->getRouteMatch();
        $path    = trim($route->getParam('path'), '/');
        $project = $route->getParam('project');

        // let file browser handle full page requests.
        if ($request->getQuery('format') !== 'partial') {
            return $this->forward()->dispatch(
                'Files\Controller\Index',
                array(
                    'action'  => 'file',
                    'path'    => $path,
                    'history' => true,
                    'project' => $project
                )
            );
        }

        $services       = $this->getServiceLocator();
        $p4             = $services->get('p4');
        $p4Admin        = $services->get('p4_admin');
        $max            = $request->getQuery('max', 50);
        $after          = $request->getQuery('after');
        $status         = $request->getQuery('status', Change::SUBMITTED_CHANGE);
        $includeReviews = $request->getQuery('includeReviews', false);
        $user           = preg_replace('/^@/', '', $request->getQuery('user'));
        $range          = preg_replace(
            array('/^([^@#])/', '/\s*,\s*/'),
            array('@\1', ','),
            trim($request->getQuery('range'))
        );

        // if we have a client, set it on the connection
        $client = $project ? $project->getClient() : null;
        if ($client) {
            $p4->setClient($client);
        }

        $filespec = $client ? rtrim($client . '/' . $path, '/') : $path;
        $filespec = ($filespec ? '//' . $filespec . '/...' : '') . ($range ?: '');

        try {
            $changes = Change::fetchAll(
                array(
                    Change::FETCH_AFTER       => $after,
                    Change::FETCH_MAXIMUM     => $max,
                    Change::FETCH_BY_USER     => $user ?: null,
                    Change::FETCH_BY_STATUS   => $status,
                    Change::FETCH_BY_FILESPEC => $filespec ?: null
                ),
                $p4
            );
        } catch (CommandException $e) {
            // capture cases where fetching changes failed due to invalid characters in username
            // or invalid client mapping (e.g. non-existing depot name) and return an empty list
            // these cases are recognized by the error messages:
            //  - "Wildcards (*, %%x, ...) not allowed in '<something>'"
            //  - "Revision chars (@, #) not allowed in '<something>'"
            //  - "<path> - must refer to client '<client>'"
            //  - "Invalid changelist/client/label/date"s
            if (preg_match('/(Wildcards|Revision chars) \(.+?\) not allowed in /', $e->getMessage())
                || strpos($e->getMessage(), '- must refer to client') !== false
                || strpos($e->getMessage(), 'Can\'t use a pending changelist number for this command') !== false
            ) {
                $changes = new FieldedIterator;
            } elseif (strpos($e->getMessage(), 'Invalid changelist/client/label/date') !== false
                || strpos($e->getMessage(), 'Unintelligible revision specification') !== false
                || strpos($e->getMessage(), 'Invalid revision number') !== false
            ) {
                $changes = new FieldedIterator;
                $this->getResponse()->getHeaders()->addHeaders(array('X-Swarm-Range-Error' => true));
                $this->getResponse()->setStatusCode(400);
            } else {
                throw $e;
            }
        }

        // if no changes reported and not paginating, check for a remote depot
        // (not something we can readily detect when running against a client)
        $remote = false;
        if (!$changes->count() && !$after && !$client) {
            preg_match('#^/{0,2}([^/]+)#', $path, $match);
            if (isset($match[1])) {
                $depots = Depot::fetchAll(array(), $p4)->filter(Depot::ID_FIELD, $match[1]);
                $remote = $depots->count() && $depots->first()->get('Type') == 'remote';
            }
        }

        // if reviews are included and changes were found,
        // build a reviewsByChange array indexed by Change
        $reviewsByChange = array();
        if ($changes->count() && $includeReviews) {
            $reviews = Review::fetchAll(array(Review::FETCH_BY_CHANGE => $changes->invoke('getId')), $p4Admin);
            foreach ($reviews as $review) {
                $reviewsByChange += array_fill_keys($review->getChanges(), $review);
            }
        }

        $model = new ViewModel;
        $model->setTerminal(true);
        $model->setVariables(
            array(
                'path'      => $path,
                'changes'   => $changes,
                'project'   => $project,
                'remote'    => $remote,
                'status'    => $status,
                'reviews'   => $includeReviews ? $reviewsByChange : false,
            )
        );

        return $model;
    }

    public function fixesAction()
    {
        $services = $this->getServiceLocator();
        $p4       = $services->get('p4');
        $route    = $this->getEvent()->getRouteMatch();
        $changeId = $route->getParam('change');
        $mode     = $route->getParam('mode');
        $request  = $this->getRequest();

        // if posted, add or remove jobs depending on mode
        $jobs = $request->getPost('jobs');
        if ($request->isPost() && $jobs && $mode) {
            // allow changing jobs only for authenticated users
            $services->get('permissions')->enforce('authenticated');

            $flags = array_merge(
                $mode === 'delete' ? array('-d') : array(),
                array('-c', $changeId),
                (array) $jobs
            );

            try {
                $p4->run('fix', $flags);
            } catch (CommandException $e) {
                // check for non-existent or invalid job or change IDs and report them as 404s
                if (preg_match('/Job \'(.+?)\' unknown - use \'job\' to create it./', $e->getMessage())
                    || preg_match('/Change (\d+) unknown./', $e->getMessage())
                    || preg_match('/Invalid changelist number \'(.+)\'./', $e->getMessage())
                    || preg_match('/(Wildcards|Revision chars) \(.+?\) not allowed in /', $e->getMessage())
                ) {
                    // non-existent or invalid job or change ID
                    $this->getResponse()->setStatusCode(404);
                    return;
                }

                // ensure we throw any errors unrelated to non-existent jobs
                throw $e;
            }
        }

        return new JsonModel(array('jobs' => $this->getFormattedJobs(Change::fetchById($changeId, $p4))));
    }

    /**
     * Return list of jobs attached to a given change as an associative array with
     * formatted jobs data.
     *
     * @param   Change  $change     change to get array with formatted jobs data for
     * @return  array   array with formatted jobs data
     */
    protected function getFormattedJobs(Change $change)
    {
        $jobs      = array();
        $preformat = new Preformat($this->getRequest()->getBaseUrl());
        foreach ($change->getJobObjects() as $job) {
            $jobs[] = array(
                'job'         => $job->getId(),
                'link'        => $this->url()->fromRoute('job', array('job' => $job->getId())),
                'status'      => $job->getStatus(),
                'description' => $preformat->filter($job->getDescription())
            );
        }

        return $jobs;
    }
}
