(function () {
  function setCount(count) {
    document.querySelectorAll('.ykt-cart-icon__count').forEach(function (el) {
      el.textContent = String(Number(count || 0));
    });
  }

  function refreshCartCount() {
    if (!window.yktCampaignFrontend || !window.yktCampaignFrontend.ajaxUrl) return;

    var params = new URLSearchParams();
    params.set('action', 'ykt_cart_count');

    fetch(window.yktCampaignFrontend.ajaxUrl + '?' + params.toString(), { credentials: 'same-origin' })
      .then(function (response) { return response.json(); })
      .then(function (payload) {
        if (payload && payload.success && payload.data) setCount(payload.data.count);
      })
      .catch(function () {});
  }

  document.addEventListener('DOMContentLoaded', refreshCartCount);
  document.body.addEventListener('added_to_cart', refreshCartCount);
})();
