(function () {
    'use strict';

    function initPlayer(video) {
        if (!video || video.dataset.mmbaReady === '1') {
            return;
        }

        var src = video.getAttribute('data-src');
        if (!src) {
            return;
        }

        video.dataset.mmbaReady = '1';

        if (window.Hls && window.Hls.isSupported()) {
            var hls = new window.Hls({
                enableWorker: true,
                lowLatencyMode: true,
            });
            hls.loadSource(src);
            hls.attachMedia(video);
            video.addEventListener('error', function () {
                // Keep native fallback available if HLS fails mid-stream.
            });
            return;
        }

        if (video.canPlayType('application/vnd.apple.mpegurl')) {
            video.src = src;
        }
    }

    function boot() {
        var videos = document.querySelectorAll('video.mmba-player[data-src]');
        for (var i = 0; i < videos.length; i++) {
            initPlayer(videos[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
