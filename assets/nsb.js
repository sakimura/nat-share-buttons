/* NAT Share Buttons - nsb.js */
(function () {
    'use strict';

    function postAjax(action, nonce, params) {
        var ajaxPath = window.NSB && NSB.ajax_path ? NSB.ajax_path : '/wp-admin/admin-ajax.php';
        var ajaxUrl = new URL(ajaxPath, window.location.origin).toString();
        var body = new URLSearchParams(Object.assign({
            action: action,
            nonce:  nonce,
        }, params));
        fetch(ajaxUrl, {
            method:      'POST',
            credentials: 'same-origin',
            headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:        body.toString(),
            keepalive:   true,
        }).catch(function () { /* silent fail */ });
    }

    // Record page view on load
    document.addEventListener('DOMContentLoaded', function () {
        if (!window.NSB || !NSB.post_id) return;
        postAjax('nsb_pageview', NSB.pv_nonce, { post_id: NSB.post_id });
    });

    // Record share button click
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-network]');
        if (!btn) return;

        var wrap    = btn.closest('.nsb-wrap');
        var postId  = wrap ? wrap.dataset.postId : null;
        var network = btn.dataset.network;

        if (!postId || !network || !window.NSB) return;
        postAjax('nsb_click', NSB.nonce, { post_id: postId, network: network });
    });
}());
