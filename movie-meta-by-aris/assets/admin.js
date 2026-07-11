(function () {
    'use strict';

    function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function (resolve, reject) {
            var input = document.createElement('textarea');
            input.value = text;
            input.setAttribute('readonly', '');
            input.style.position = 'absolute';
            input.style.left = '-9999px';
            document.body.appendChild(input);
            input.select();
            try {
                document.execCommand('copy');
                resolve();
            } catch (err) {
                reject(err);
            } finally {
                document.body.removeChild(input);
            }
        });
    }

    document.addEventListener('click', function (event) {
        var btn = event.target.closest('.mmba-copy-btn');
        if (!btn) {
            return;
        }

        var target = btn.getAttribute('data-target') || '';
        if (!target) {
            return;
        }

        copyText(target).then(function () {
            var original = btn.textContent;
            btn.textContent = 'Copied';
            setTimeout(function () {
                btn.textContent = original;
            }, 1200);
        });
    });
})();
