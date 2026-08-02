(function () {
  function updateProgress(el, data) {
    var books = el.querySelector('[data-ykt-books]');
    var donors = el.querySelector('[data-ykt-donors]');
    var percent = el.querySelector('[data-ykt-percent]');
    var bar = el.querySelector('[data-ykt-bar]');
    var progressBar = el.querySelector('[role="progressbar"]');

    if (books) books.textContent = Number(data.books_funded || 0).toLocaleString();
    if (donors) donors.textContent = Number(data.donor_count || 0).toLocaleString();
    if (percent) percent.textContent = String(data.percentage || 0) + '%';
    if (bar) bar.style.width = String(data.percentage || 0) + '%';
    if (progressBar) progressBar.setAttribute('aria-valuenow', String(data.percentage || 0));
  }

  function refresh(el) {
    if (!window.yktProgress || !window.yktProgress.ajaxUrl) return;

    var params = new URLSearchParams();
    params.set('action', 'ykt_campaign_progress');
    params.set('target', el.getAttribute('data-target') || '0');

    fetch(window.yktProgress.ajaxUrl + '?' + params.toString(), { credentials: 'same-origin' })
      .then(function (response) { return response.json(); })
      .then(function (payload) {
        if (payload && payload.success && payload.data) updateProgress(el, payload.data);
      })
      .catch(function () {});
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.ykt-progress, .ykt-book-counter').forEach(refresh);
  });
})();
