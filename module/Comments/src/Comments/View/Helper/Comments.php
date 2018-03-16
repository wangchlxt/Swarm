<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

namespace Comments\View\Helper;

use Attachments\Model\Attachment;
use Comments\Model\Comment;
use Groups\Model\Group;
use Projects\Model\Project;
use Reviews\Model\Review;
use Users\Model\User;
use Zend\View\Helper\AbstractHelper;

class Comments extends AbstractHelper
{
    protected $defaultTemplate = 'comments.phtml';

    /**
     * If called without arguments, return instance of this class, otherwise
     * render comments.
     *
     * @param   string|null     $topic      optional - topic to render comments for
     * @param   string|null     $template   optional - template to use for rendering
     * @return  Comments|string             this helper if called without arguments
     *                                      or rendered comments otherwise
     */
    public function __invoke($topic = null, $template = null, $version = null)
    {
        if ($topic === null && $template === null) {
            return $this;
        }

        return $this->render($topic, $template, $version);
    }

    /**
     * Render comments for a given topic.
     *
     * @param   string          $topic      topic to render comments for
     * @param   string|null     $template   optional - template to use for rendering
     * @return  string          rendered comments
     */
    public function render($topic, $template = null, $version = null)
    {
        $view         = $this->getView();
        $services     = $view->getHelperPluginManager()->getServiceLocator();
        $config       = $services->get('config');
        $maxSize      = $config['attachments']['max_file_size'];
        $mentionsMode = $config['mentions']['mode'];
        $p4Admin      = $services->get('p4_admin');
        $ipProtects   = $services->get('ip_protects');
        $options      = array(
            Comment::FETCH_BY_TOPIC => $topic
        );
        $comments     = Comment::fetchAll($options, $p4Admin, $ipProtects);
        $attachments  = Comment::fetchAttachmentsByComments($comments, $p4Admin);

        // check mentions settings, can be one of:
        // - disabled
        // - enabled for all users and all groups in all review comments
        // - enabled only for project users and groups in review that has a project (default)
        $mentions = array();
        switch ($mentionsMode) {
            case 'disabled':
            case 'global':
                break;
            default:
                $mentions = Comment::getPossibleMentions($topic, $config, $p4Admin);
        }

        // if a version has been provided and this is a review topic,
        // filter out any comments that don't have a matching version
        if ($version && strpos($topic, 'reviews/') === 0) {
            $comments->filterByCallback(
                function (Comment $comment) use ($version) {
                    $context = $comment->getContext();
                    return isset($context['version']) && $context['version'] == $version;
                }
            );
        }

        return $view->render(
            $template ?: $this->defaultTemplate,
            array(
                'topic'       => $topic,
                'maxSize'     => $maxSize,
                'comments'    => $comments,
                'attachments' => $attachments,
                'canAttach'   => $services->get('depot_storage')->isWritable('attachments/'),
                'mentions'    => $mentions,
                'mode'        => $mentionsMode
            )
        );
    }

    /**
     * Return number of open comments for a given topic.
     *
     * @param   string  $topic  topic to get a count for
     * @return  int     number of comments for a given topic
     */
    public function count($topic)
    {
        $view     = $this->getView();
        $services = $view->getHelperPluginManager()->getServiceLocator();
        $p4Admin  = $services->get('p4_admin');

        return current(Comment::countByTopic($topic, $p4Admin));
    }
}
