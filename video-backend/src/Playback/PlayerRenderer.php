<?php

declare(strict_types=1);

namespace Drumeo\Video\Playback;

use Drumeo\Video\Config;

final class PlayerRenderer
{
    private static ?string $hlsJs = null;

    public function __construct(private readonly Config $config)
    {
    }

    /** @return array{html:string,js:string} */
    public function render(string $videoId, string $recipe, int $audioIndex, string $playlistUrl): array
    {
        $domId = 'vp-' . $videoId . '-' . $recipe . '-' . $audioIndex;
        $html = '<div class="vp" data-vp="' . htmlspecialchars($domId, ENT_QUOTES) . '" id="' . htmlspecialchars($domId, ENT_QUOTES) . '">'
            . '<video id="' . htmlspecialchars($domId, ENT_QUOTES) . '-video"'
            . ' controls playsinline webkit-playsinline preload="metadata"'
            . ' data-playlist="' . htmlspecialchars($playlistUrl, ENT_QUOTES) . '"'
            . ' data-video-id="' . htmlspecialchars($videoId, ENT_QUOTES) . '"'
            . ' data-recipe="' . htmlspecialchars($recipe, ENT_QUOTES) . '"'
            . ' data-audio-index="' . $audioIndex . '"'
            . '></video></div>';

        $js = $this->hlsLibrary() . "\n" . $this->bootstrap($domId, $playlistUrl);
        return ['html' => $html, 'js' => $js];
    }

    private function hlsLibrary(): string
    {
        if (self::$hlsJs !== null) {
            return self::$hlsJs;
        }
        $path = $this->config->playerHlsJsPath;
        if ($path !== '' && is_file($path)) {
            self::$hlsJs = file_get_contents($path) ?: '';
        } else {
            self::$hlsJs = '';
        }
        return self::$hlsJs;
    }

    private function bootstrap(string $domId, string $playlistUrl): string
    {
        $idJson = json_encode($domId);
        $urlJson = json_encode($playlistUrl);
        return <<<JS
(function(){
  var wrapId = {$idJson};
  var playlistUrl = {$urlJson};
  var wrap = document.getElementById(wrapId) || document.querySelector('[data-vp="' + wrapId + '"]');
  if (!wrap) { return; }
  var video = wrap.querySelector('video');
  if (!video) { return; }
  var url = video.getAttribute('data-playlist') || playlistUrl;
  function isAppleNative() {
    var ua = navigator.userAgent || '';
    var iOS = /iPad|iPhone|iPod/.test(ua);
    var iPadOS = navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1;
    var safari = /^((?!chrome|android|crios|fxios).)*safari/i.test(ua);
    return iOS || iPadOS || (safari && !/Chrome/.test(ua));
  }
  function attachNative() {
    video.src = url;
    try { video.load(); } catch (e) {}
  }
  function attachHls() {
    var HlsClass = window.Hls;
    if (!HlsClass || !HlsClass.isSupported()) {
      attachNative();
      return;
    }
    if (video._vbHls) {
      try { video._vbHls.destroy(); } catch (e) {}
    }
    var hls = new HlsClass({
      enableWorker: false,
      lowLatencyMode: false,
      liveDurationInfinity: false,
      startPosition: 0,
      manifestLoadingMaxRetry: 6,
      manifestLoadingRetryDelay: 1000,
      levelLoadingMaxRetry: 6
    });
    video._vbHls = hls;
    hls.loadSource(url);
    hls.attachMedia(video);
    hls.on(HlsClass.Events.ERROR, function(_evt, data) {
      if (!data || !data.fatal) return;
      if (data.type === HlsClass.ErrorTypes.NETWORK_ERROR) {
        hls.startLoad();
      } else if (data.type === HlsClass.ErrorTypes.MEDIA_ERROR) {
        hls.recoverMediaError();
      }
    });
  }
  if (isAppleNative()) {
    attachNative();
  } else {
    attachHls();
  }
})();
JS;
    }
}
