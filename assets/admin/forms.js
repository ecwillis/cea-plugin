(function($) {
  'use strict';

  var counter = Date.now();

  function nextIndex() {
    counter += 1;
    return String(counter);
  }

  function makeRow(templateId) {
    var template = document.getElementById(templateId);

    if (!template) {
      return '';
    }

    return template.innerHTML.replace(/__INDEX__/g, nextIndex());
  }

  function initializeSortable() {
    $('.cea-form-sortable').sortable({
      axis: 'y',
      handle: '.cea-form-drag-handle',
      items: '> .cea-form-builder-row',
      placeholder: 'cea-form-builder-placeholder'
    });
  }

  function updateChoiceVisibility(row) {
    var type = row.querySelector('[data-cea-field-type]');
    var choices = row.querySelector('[data-cea-choices]');

    if (!type || !choices) {
      return;
    }

    choices.hidden = type.value !== 'select' && type.value !== 'radio';
  }

  $(document).on('click', '[data-cea-add="field"]', function() {
    var list = document.getElementById('cea-form-fields-list');
    var html = makeRow('tmpl-cea-form-field');

    if (list && html) {
      list.insertAdjacentHTML('beforeend', html);
      updateChoiceVisibility(list.lastElementChild);
    }
  });

  $(document).on('click', '[data-cea-add-action]', function() {
    var list = document.getElementById('cea-form-actions-list');
    var type = this.getAttribute('data-cea-add-action');
    var html = makeRow('tmpl-cea-form-action-' + type);

    if (list && html) {
      list.insertAdjacentHTML('beforeend', html);
    }
  });

  $(document).on('click', '[data-cea-remove]', function() {
    var message = window.ceaFormsAdmin ? window.ceaFormsAdmin.removeConfirm : 'Remove this item?';

    if (window.confirm(message)) {
      $(this).closest('.cea-form-builder-row').remove();
    }
  });

  $(document).on('change', '[data-cea-field-type]', function() {
    updateChoiceVisibility(this.closest('[data-cea-row="field"]'));
  });

  $(document).on('input', '[data-cea-label]', function() {
    var row = this.closest('[data-cea-row="field"]');
    var title = row ? row.querySelector('[data-cea-row-title]') : null;
    var fallback = window.ceaFormsAdmin ? window.ceaFormsAdmin.untitled : 'Untitled field';

    if (title) {
      title.textContent = this.value.trim() || fallback;
    }
  });

  $(document).on('click', '[data-cea-shortcode]', function() {
    this.select();

    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(this.value);
    }
  });

  $(function() {
    initializeSortable();

    document.querySelectorAll('[data-cea-row="field"]').forEach(function(row) {
      updateChoiceVisibility(row);
    });
  });
})(jQuery);
