(function () {
    'use strict';

    const meta = document.querySelector('meta[name="csrf-token"]');
    const token = meta ? meta.content : '';
    if (!token) return;

    window.CSRF_TOKEN = token;

    if (window.jQuery) {
        window.jQuery.ajaxPrefilter(function (options, originalOptions, jqXHR) {
            const method = String(options.type || options.method || 'GET').toUpperCase();
            if (method !== 'GET' && method !== 'HEAD' && method !== 'OPTIONS') {
                jqXHR.setRequestHeader('X-CSRF-Token', token);
            }
        });
    }

    if (window.fetch) {
        const originalFetch = window.fetch.bind(window);
        window.fetch = function (input, init) {
            const options = Object.assign({}, init || {});
            const method = String(options.method || 'GET').toUpperCase();
            if (method !== 'GET' && method !== 'HEAD' && method !== 'OPTIONS') {
                const headers = new Headers(options.headers || {});
                headers.set('X-CSRF-Token', token);
                options.headers = headers;
            }
            return originalFetch(input, options);
        };
    }
})();
