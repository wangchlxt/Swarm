<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Groups\View\Helper;

use Groups\Model\Group;
use Zend\View\Helper\AbstractHelper;

class GroupToolbar extends AbstractHelper
{
    /**
     * Returns the markup for a group toolbar.
     *
     * @param   Group|string    $group  the group to render toolbar for
     * @return  string          markup for the group toolbar
     */
    public function __invoke($group)
    {
        $view        = $this->getView();
        $services    = $view->getHelperPluginManager()->getServiceLocator();
        $permissions = $services->get('permissions');
        $event       = $services->get('Application')->getMvcEvent();
        $route       = $event->getRouteMatch()->getMatchedRouteName();
        $mode        = $event->getRouteMatch()->getParam('mode');
        $group       = $group instanceof Group ? $group : Group::fetchById($group, $services->get('p4_admin'));

        // declare group links
        $links = array(
            array(
                'label'  => 'Activity',
                'url'    => $view->url('group', array('group' => $group->getId())),
                'active' => $route === 'group',
                'class'  => 'overview-link',
                'icon'   => 'list'
            ),
            array(
                'label'  => 'Reviews',
                'url'    => $view->url('group-reviews', array('group' => $group->getId())),
                'active' => $route === 'group-reviews' || $route === 'review',
                'class'  => 'review-link',
                'icon'   => 'th-list'
            )
        );

        // add group settings links if user has permission
        if ($permissions->isOne(array('super', 'owner' => $group))) {
            $links[] = array(
                'label'  => 'Settings',
                'url'    => $view->url('edit-group', array('group' => $group->getId())),
                'active' => $route === 'edit-group',
                'class'  => 'settings',
                'icon'   => 'wrench'
            );
            $links[] = array(
                'label'  => 'Notifications',
                'url'    => $view->url('edit-notifications', array('group' => $group->getId())),
                'active' => $route === 'edit-notifications',
                'class'  => 'notifications',
                'icon'   => 'envelope'
            );
        }

        // render list of links
        $list = '';
        foreach ($links as $link) {
            $list .= '<li class="' . ($link['active'] ? 'active' : '') . '">'
                  . '<a href="' . $link['url'] . '" class="' . $link['class'] . '">'
                  . '<i class="icon-' . $link['icon'] . '"></i> '
                  . $view->te($link['label'])
                  . '</a>'
                  . '</li>';
        }

        // render group toolbar
        $name = $view->escapeHtml($group->getConfig()->getName());
        $url  = $view->url('group',  array('group' => $group->getId()));
        return '<div class="group-navbar " data-group="' . $group->getId() . '">'
             . '  <ul class="nav nav-tabs">' . $list . '</ul>'
             . '</div>';
    }
}
