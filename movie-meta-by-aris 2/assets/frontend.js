(function () {
    'use strict';

    var activeHls = null;

    function initInlinePlayers() {
        var videos = document.querySelectorAll('video.mmba-player[data-src]');
        for (var i = 0; i < videos.length; i++) {
            attachHls(videos[i]);
        }
    }

    function attachHls(video) {
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
            return hls;
        }

        if (video.canPlayType('application/vnd.apple.mpegurl')) {
            video.src = src;
        }

        return null;
    }

    function getModal() {
        return document.getElementById('mmba-modal');
    }

    function getPlayerMount() {
        return document.getElementById('mmba-modal-player');
    }

    function destroyPlayer() {
        var mount = getPlayerMount();
        if (!mount) {
            return;
        }

        var video = mount.querySelector('video');
        if (video) {
            try {
                video.pause();
            } catch (e) {
                // ignore
            }
            video.removeAttribute('src');
            video.load();
        }

        if (activeHls) {
            try {
                activeHls.destroy();
            } catch (e) {
                // ignore
            }
            activeHls = null;
        }

        mount.innerHTML = '';
    }

    function closeModal() {
        var modal = getModal();
        if (!modal) {
            return;
        }

        destroyPlayer();
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.documentElement.classList.remove('mmba-modal-open');
    }

    function openModal(src, type, title) {
        var modal = getModal();
        var mount = getPlayerMount();
        var titleEl = document.getElementById('mmba-modal-title');
        if (!modal || !mount || !src) {
            return;
        }

        destroyPlayer();

        if (titleEl) {
            titleEl.textContent = title || '';
        }

        if (type === 'embed') {
            var iframe = document.createElement('iframe');
            iframe.className = 'mmba-embed';
            iframe.src = src;
            iframe.title = title || 'Movie';
            iframe.allow = 'fullscreen; encrypted-media; picture-in-picture';
            iframe.setAttribute('allowfullscreen', '');
            iframe.setAttribute('referrerpolicy', 'no-referrer-when-downgrade');
            mount.appendChild(iframe);
        } else {
            var video = document.createElement('video');
            video.className = 'mmba-player';
            video.controls = true;
            video.playsInline = true;
            video.setAttribute('playsinline', '');
            video.setAttribute('data-src', src);
            mount.appendChild(video);
            activeHls = attachHls(video);
            // Do not autoplay — wait for user to press play.
        }

        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.documentElement.classList.add('mmba-modal-open');
    }

    function onClick(e) {
        var openBtn = e.target.closest('[data-mmba-open]');
        if (openBtn) {
            e.preventDefault();
            openModal(
                openBtn.getAttribute('data-src'),
                openBtn.getAttribute('data-type') || 'embed',
                openBtn.getAttribute('data-title') || ''
            );
            return;
        }

        if (e.target.closest('[data-mmba-close]')) {
            e.preventDefault();
            closeModal();
        }
    }

    function onKeydown(e) {
        if (e.key === 'Escape') {
            closeModal();
        }
    }

    function boot() {
        initInlinePlayers();
        document.addEventListener('click', onClick);
        document.addEventListener('keydown', onKeydown);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
