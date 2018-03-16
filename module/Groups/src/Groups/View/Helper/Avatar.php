<?php
/**
 * Created by PhpStorm.
 * User: swellard
 * Date: 25/08/17
 * Time: 18:18
 */

namespace Groups\View\Helper;

use Zend\View\Helper\AbstractHelper;
use Application\View\Helper\Avatar as AvatarHelper;
use Groups\Model\Group as GroupModel;

class Avatar extends AbstractHelper
{
    /**
     * Renders a image tag and optional link for the given group's avatar.
     *
     * @param   string|GroupModel|null  $group  a group id or user object (null for anonymous)
     * @param   string|int              $size   the size of the avatar (e.g. 64, 128)
     * @param   bool                    $link   optional - link to the user (default=true)
     * @param   bool                    $class  optional - class to add to the image
     * @param   bool                    $fluid  optional - match avatar size to the container
     * @return string
     */
    public function __invoke($group = null, $size = null, $link = true, $class = null, $fluid = true)
    {
        $view     = $this->getView();
        $services = $view->getHelperPluginManager()->getServiceLocator();
        $isModel  = $group instanceof GroupModel;

        if (!$isModel) {
            $p4Admin = $services->get('p4_admin');
            if ($group && GroupModel::exists($group, $p4Admin)) {
                $group   = GroupModel::fetchById($group, $p4Admin);
                $isModel = true;
            } else {
                $group = $group ?: null;
                $link  = false;
            }
        }
        $id    = $isModel ? $group->getId()                             : $group;
        $email = $isModel ? $group->getConfig()->get('emailAddress')    : null;
        $name  = $isModel ? $group->getConfig()->getName()              : $group;

        return AvatarHelper::getAvatar(
            $view,
            $id,
            $email,
            $name,
            $size,
            $link,
            $class,
            $fluid,
            AvatarHelper::GROUPS_AVATAR
        );
    }
}
