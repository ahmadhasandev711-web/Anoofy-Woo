/* Anoofy order box: posts only this box's own fields to admin-post.php, then follows the redirect (which carries
   the result banner). No framework, no inline script. */
(function () {
  'use strict';
  var cfg = window.anoofyOrderBox || {};

  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-anoofy-submit]') : null;
    if (!btn) { return; }
    var group = btn.closest('[data-anoofy-form]');
    var box = btn.closest('[data-anoofy-box]');
    if (!group || !box) { return; }
    e.preventDefault();

    var body = new FormData();
    body.append('action', group.getAttribute('data-anoofy-form'));
    body.append('order_id', box.getAttribute('data-order-id'));
    body.append('_anoofy_nonce', box.getAttribute('data-nonce'));
    group.querySelectorAll('input[name], select[name], textarea[name]').forEach(function (el) {
      body.append(el.name, el.value);
    });
    if (btn.hasAttribute('data-anoofy-event')) {
      body.append('anoofy_event', btn.getAttribute('data-anoofy-event'));
    }

    var label = btn.textContent;
    btn.disabled = true;
    btn.textContent = cfg.sending || '…';
    fetch(cfg.endpoint, { method: 'POST', body: body, credentials: 'same-origin', redirect: 'follow' })
      .then(function (res) {
        // A successful action answers with a redirect back to the order (carrying the result banner).
        // Anything else (expired nonce, 403, server error) lands on a bare admin-post.php page: do not navigate there.
        if (res.ok && res.redirected) {
          window.location.assign(res.url);
          return;
        }
        throw new Error('unexpected response');
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = label;
        window.alert(cfg.failed || 'Request failed');
      });
  });
})();
