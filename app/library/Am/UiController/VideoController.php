<?php
/*
*   Members page. Used to renew subscription.
*
*
*     Author: Alex Scott
*      Email: alex@cgi-central.net
*        Web: http://www.cgi-central.net
*    Details: Member display page
*    FileName $RCSfile$
*    Release: 6.3.39 ($Revision: 5371 $)
*
* Please direct bug reports,suggestions or feedback to the cgi-central forums.
* http://www.cgi-central.net/forum/
*
* aMember PRO is a commercial software. Any distribution is strictly prohibited.
*
*/

class VideoController extends MediaController
{
    protected $type = 'video';

    function posterAction()
    {
        $id = $this->_request->get('id');
        $this->validateSignedLink($id);
        $id = intval($id);
        $media = $this->getDi()->videoTable->load($id);
        set_time_limit(600);

        while (@ob_end_clean());
        $this->getDi()->session->writeClose();

        $config = $this->getMediaConfig($media);

        $file = null;
        if ($poster_id = $media->poster_id ?: (isset($config['poster_id']) ? $config['poster_id'] : "")) {
            $file = $this->getDi()->plugins_storage->getFile($poster_id);
        }

        if (!$file) {
            throw new Am_Exception_InputError;
        }

        if ($path = $file->getLocalPath()) {
            $this->_helper->sendFile($path, $file->getMime());
        } else {
            Am_Mvc_Response::redirectLocation(
                $file->getUrl($this->getDi()->config->get('storage.s3.expire',15) * 60, false)
            );
        }
    }

    function getSignedPosterLink(ResourceAbstract $media)
    {
        $rel = $media->pk() . '-' . ($this->getDi()->time + 3600 * 24);
        return $this->getDi()->surl(
            "{$this->type}/poster/id/{$rel}-{$this->getDi()->security->siteHash('am-' . $this->type . '-' . $rel, 10)}",
            false
        );
    }

    function getHtmlCode($media, $width, $height)
    {
        $scriptId = "am-{$this->type}-" . filterId($this->id);
        $mediaId = filterId($this->id);
        $divId = "div-".$scriptId;

        $url = $this->getSignedLink($media);
        $config = $this->getMediaConfig($media);

        //Poster
        $poster = '';
        if ($poster_id = $media->poster_id ?: ($config['poster_id'] ?? "")) {
            $file = $this->getDi()->plugins_storage->getFile($poster_id);
            $poster = $file ? $this->getSignedPosterLink($media) : '';
        }

        //Captions
        $captions = '';
        if ($media->cc_vtt_id) {
            $cc_vtt = $this->getDi()->uploadTable->load($media->cc_vtt_id, false);
            $cc_vtt_url = $this->getDi()->surl('upload/get/' . preg_replace('/^\./', '', $cc_vtt->path), false);
            $captions = $cc_vtt_url;
        }

        $track = '';
        if ($captions) {
            $track = <<<CUT
<track kind="captions" src="{$captions}" />
CUT;
        }

        //Branding
        $logo_css = '';
        $position_map = [
            'top-right' => 'top: 20px;right: 20px;',
            'top-left' => 'top: 20px;left: 20px;',
            'bottom-right' => 'bottom: 50px;right: 20px;',
            'bottom-left' => 'bottom: 50px;left: 20px;',
        ];

        if (!empty($config['logo'])) {
$logo_css = <<<CUT
<style>
    #{$divId} .plyr__video-wrapper::before {
        {$position_map[$config['logo_position']]}   
        position: absolute;
        z-index: 10;
        content: url('{$config['logo']}');
    }
    .plyr--stopped.plyr__poster-enabled .plyr__video-wrapper::before {
        display: none;
    }
</style>
CUT;
        }

        return <<<CUT
{$logo_css}
<div id="{$divId}" class="am-video-wrapper" style="max-width:{$width}; height:{$height}">
    <video id="player-{$mediaId}" controls data-poster="{$poster}">
        <source type="{$media->mime}" />
        {$track}
    </video>
</div>
CUT;

    }
}