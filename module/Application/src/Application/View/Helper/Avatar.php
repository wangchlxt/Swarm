<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Application\View\Helper;

use Application\Config\ConfigManager;

/**
 * Renders an avatar
 * @package Application\View\Helper
 */
class Avatar
{
    const DEFAULT_COUNT = 6;
    const GROUPS_AVATAR = 'groups';
    const ROUNDED       = 'img-rounded';
    const SIZE_64       = '64';
    const SIZE_128      = '128';
    const SIZE_256      = '256';

    /**
     * Renders a image tag and optional link for the an avatar.
     *
     * @param  AbstractHelper          $view        the Zend view
     * @param  string                  $id          id for the avatar owner
     * @param  string                  $email       email for the avatar owner
     * @param  string                  $name        name of the avatar owner
     * @param  string|int              $size        the size of the avatar (e.g. 64, 128)
     * @param  bool                    $link        optional - link to the user (default=true)
     * @param  bool                    $class       optional - class to add to the image
     * @param  bool                    $fluid       optional - match avatar size to the container
     * @param  string                  $sheetSuffix optional - suffix to append to the size to get the sprite sheet
     * @return string
     */
    public static function getAvatar(
        $view,
        $id,
        $email,
        $name,
        $size,
        $link = true,
        $class = null,
        $fluid = true,
        $sheetSuffix = ''
    ) {
        $services = $view->getHelperPluginManager()->getServiceLocator();
        $config   = $services->get('config');
        $size     = (int) $size ?: static::SIZE_64;

        // pick a default image and color for this user - if no user, pick system avatar
        // we do this by summing the ascii values of all characters in their id
        // then we modulo divide by 6 to get a remainder in the range of 0-5.
        $class .= ' as-' . $size . $sheetSuffix;
        if ($id) {
            $i      = (array_sum(array_map('ord', str_split($id))) % static::DEFAULT_COUNT) + 1;
            $class .= ' ai-' . $i;
            $class .= ' ac-' . $i;
        } else {
            $class .= ' avatar-system';
        }

        // determine the url to use for this user's avatar based on the configured pattern
        // if user is null or no pattern is configured, fallback to a blank gif via data uri
        $url     = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
            ? ConfigManager::getValue($config, ConfigManager::AVATARS_HTTPS)
            : ConfigManager::getValue($config, ConfigManager::AVATARS_HTTP);
        $url     = $url && $id
            ? $url
            : 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
        $replace = array(
            '{user}'    => $id,
            '{email}'   => $email,
            '{hash}'    => $email ? md5(strtolower($email)) : '00000000000000000000000000000000',
            '{default}' => 'blank',
            '{size}'    => $size
        );
        $url     = str_replace(array_keys($replace), array_map('rawurlencode', $replace), $url);

        // build the actual img tag we'll be using
        $fluid = $fluid ? 'fluid' : '';
        $class = $view->escapeHtmlAttr(trim('avatar ' . $class));
        $alt   = $view->escapeHtmlAttr($name);
        $html  = '<img width="' . $size . '" height="' . $size . '" alt="' . $alt . '"'
            . ' src="' . $url . '" data-user="' . $view->escapeHtmlAttr($id) . '"'
            . ' class="' . $class . '" onerror="$(this).trigger(\'img-error\')"'
            . ' onload="$(this).trigger(\'img-load\')">';

        $href = $sheetSuffix === static::GROUPS_AVATAR ? $view->url('group', array('group' => $id)) :
            $view->url('user', array('user' => $id));
        if ($link && $id) {
            $html = '<a href="' . $href . '" title="' . $alt . '"' . ' class="avatar-wrapper avatar-link ' . $fluid
                . '">' . $html . '</a>';
        } else {
            $html = '<div class="avatar-wrapper ' . $fluid . '" title="' . $alt . '">' . $html . '</div>';
        }

        return $html;
    }
}
