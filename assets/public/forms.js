(function() {
  'use strict';

  function uuid() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return window.crypto.randomUUID();
    }

    return Date.now().toString(36) + '-' + Math.random().toString(36).slice(2) + '-' + Math.random().toString(36).slice(2);
  }

  function prepare(form) {
    var token = form.querySelector('[data-cea-submission-token]');
    var startedAt = form.querySelector('[data-cea-started-at]');

    if (token && !token.value) {
      token.value = uuid();
    }

    // Preserve the server-rendered value so submitting does not restart the timer.
    if (startedAt && !startedAt.value) {
      startedAt.value = Math.floor(Date.now() / 1000);
    }
  }

  document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.cea-form__form').forEach(prepare);

    var result = document.querySelector('[data-cea-result]');

    if (result) {
      result.focus();
    }
  });

  document.addEventListener('submit', function(event) {
    if (event.target.matches('.cea-form__form')) {
      prepare(event.target);
    }
  });
})();
