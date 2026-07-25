(function () {
  function saveNote(button) {
    var row = button.closest('tr');
    var textarea = row ? row.querySelector('.ykt-internal-note') : null;
    if (!textarea || !window.yktAdmin) return;

    button.disabled = true;
    var body = new URLSearchParams();
    body.set('action', 'ykt_save_internal_note');
    body.set('nonce', window.yktAdmin.nonce);
    body.set('order_id', textarea.getAttribute('data-order-id'));
    body.set('note', textarea.value);

    fetch(window.yktAdmin.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    }).finally(function () {
      button.disabled = false;
    });
  }

  document.addEventListener('click', function (event) {
    if (event.target && event.target.classList.contains('ykt-save-note')) {
      saveNote(event.target);
    }
  });
})();
