(function () {
  function setCount(count) {
    document.querySelectorAll('.ykt-cart-icon__count').forEach(function (el) {
      var value = Number(count || 0);
      el.textContent = String(value);
      el.classList.toggle('ykt-cart-icon__count--empty', value <= 0);
    });
  }

  function fetchCart(action) {
    if (!window.yktCampaignFrontend || !window.yktCampaignFrontend.ajaxUrl) return Promise.resolve(null);

    var params = new URLSearchParams();
    params.set('action', action);

    return fetch(window.yktCampaignFrontend.ajaxUrl + '?' + params.toString(), { credentials: 'same-origin' })
      .then(function (response) { return response.json(); })
      .catch(function () { return null; });
  }

  function refreshCartCount() {
    fetchCart('ykt_cart_count').then(function (payload) {
      if (payload && payload.success && payload.data) setCount(payload.data.count);
    });
  }

  function refreshCartPanel() {
    return fetchCart('ykt_cart_panel').then(function (payload) {
      if (!payload || !payload.success || !payload.data) return;
      setCount(payload.data.count);
      document.querySelectorAll('.ykt-cart-drawer__body').forEach(function (body) {
        body.innerHTML = payload.data.html || '';
      });
    });
  }

  function openCartDrawer(event) {
    var trigger = event.target.closest('.ykt-cart-icon');
    if (!trigger) return;

    event.preventDefault();
    var drawer = document.querySelector('.ykt-cart-drawer');
    if (!drawer) {
      window.location.href = trigger.href;
      return;
    }

    refreshCartPanel().then(function () {
      drawer.classList.add('is-open');
      drawer.setAttribute('aria-hidden', 'false');
      trigger.setAttribute('aria-expanded', 'true');
      document.documentElement.classList.add('ykt-cart-drawer-open');
    });
  }

  function closeCartDrawer() {
    document.querySelectorAll('.ykt-cart-drawer.is-open').forEach(function (drawer) {
      drawer.classList.remove('is-open');
      drawer.setAttribute('aria-hidden', 'true');
    });
    document.querySelectorAll('.ykt-cart-icon[aria-expanded="true"]').forEach(function (trigger) {
      trigger.setAttribute('aria-expanded', 'false');
    });
    document.documentElement.classList.remove('ykt-cart-drawer-open');
  }

  function bindCartEvents() {
    refreshCartCount();
    if (document.body) {
      document.body.addEventListener('added_to_cart', function () {
        refreshCartCount();
        refreshCartPanel();
      });
    }
  }

  document.addEventListener('DOMContentLoaded', bindCartEvents);
  document.addEventListener('click', openCartDrawer);
  document.addEventListener('click', function (event) {
    if (event.target.closest('[data-ykt-cart-close]')) closeCartDrawer();
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeCartDrawer();
  });
})();
