/* NAT Share Buttons - nsb.js */
(function () {
    'use strict';

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-network]');
        if (!btn) return;

        var wrap    = btn.closest('.nsb-wrap');
        var postId  = wrap ? wrap.dataset.postId : null;
        var network = btn.dataset.network;

        if (!postId || !network || !window.NSB) return;

        // Fire-and-forget AJAX — we don't block the navigation
        var body = new URLSearchParams({
            action:   'nsb_click',
            nonce:    NSB.nonce,
            post_id:  postId,
            network:  network,
        });

        fetch(NSB.ajaxurl, {
            method:      'POST',
            credentials: 'same-origin',
            headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:        body.toString(),
            keepalive:   true,   // survives page navigation
        }).catch(function () { /* silent fail */ });
    });
}());
