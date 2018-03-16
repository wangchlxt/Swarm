<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Api\Controller;

use Api\AbstractApiController;
use Zend\View\Model\JsonModel;

/**
 * Basic API controller providing a simple version action
 *
 * @SWG\Resource(
 *   apiVersion="v8",
 *   basePath="/api/v8/"
 * )
 */
class IndexController extends AbstractApiController
{
    /**
     * Return version info
     *
     * @SWG\Api(
     *     path="version",
     *     description="Version Information",
     *     @SWG\Operation(
     *         method="GET",
     *         summary="Show Version Information",
     *         notes="This can be used to determine the currently-installed Swarm version,
     *                and also to check that Swarm's API is responding as expected.",
     *         nickname="version"
     *     )
     * )
     *
     * @apiSuccessExample Successful Response:
     *     HTTP/1.1 200 OK
     *
     *     {
     *         "year": "2017",
     *         "version": "SWARM/2017.3-MAIN/8499605 (2017/10/25)"
     *     }
     *
     *     Note: `year` refers to the year of the Swarm release, not necessarily the current year.
     *
     * @return  JsonModel
     */
    public function versionAction()
    {
        if (!$this->getRequest()->isGet()) {
            $this->getResponse()->setStatusCode(405);
            return;
        }

        $data = array(
            'version'   => VERSION,
            'year'      => current(explode('.', VERSION_RELEASE)),
        );

        // include a list of supported api versions for v1.1 and up
        if ($this->getEvent()->getRouteMatch()->getParam('version') !== "v1") {
            $data['apiVersions'] = array(1.0, 1.1, 1.2, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0);
        }

        return new JsonModel($this->sortEntityFields($data));
    }
}
