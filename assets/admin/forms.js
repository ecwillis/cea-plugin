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
    var lists = $('.cea-form-sortable');

    lists.sortable({
      axis: 'y',
      cancel: 'input, textarea, select, option',
      distance: 5,
      handle: '.cea-form-drag-handle',
      items: '> .cea-form-builder-row',
      placeholder: 'cea-form-builder-placeholder',
      update: function() {
        updateMoveButtons(this);
      }
    });

    lists.each(function() {
      updateMoveButtons(this);
    });
  }

  function updateMoveButtons(list) {
    var rows;

    if (!list) {
      return;
    }

    rows = Array.prototype.filter.call(list.children, function(child) {
      return child.matches('.cea-form-builder-row');
    });

    rows.forEach(function(row, index) {
      var up = row.querySelector('[data-cea-move="up"]');
      var down = row.querySelector('[data-cea-move="down"]');

      if (up) {
        up.disabled = index === 0;
      }

      if (down) {
        down.disabled = index === rows.length - 1;
      }
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
      updateMoveButtons(list);
    }
  });

  $(document).on('click', '[data-cea-add-action]', function() {
    var list = document.getElementById('cea-form-actions-list');
    var type = this.getAttribute('data-cea-add-action');
    var html = makeRow('tmpl-cea-form-action-' + type);

    if (list && html) {
      list.insertAdjacentHTML('beforeend', html);
      updateMoveButtons(list);
    }
  });

  $(document).on('click', '[data-cea-remove]', function() {
    var list = this.closest('[data-cea-list]');
    var message = window.ceaFormsAdmin ? window.ceaFormsAdmin.removeConfirm : 'Remove this item?';

    if (window.confirm(message)) {
      $(this).closest('.cea-form-builder-row').remove();
      updateMoveButtons(list);
    }
  });

  $(document).on('click', '[data-cea-move]', function() {
    var direction = this.getAttribute('data-cea-move');
    var row = this.closest('.cea-form-builder-row');
    var list = row ? row.parentElement : null;
    var sibling = row ? (direction === 'up' ? row.previousElementSibling : row.nextElementSibling) : null;

    if (!list || !sibling) {
      return;
    }

    if (direction === 'up') {
      list.insertBefore(row, sibling);
    } else {
      list.insertBefore(sibling, row);
    }

    updateMoveButtons(list);
    this.focus();

    if (window.wp && window.wp.a11y) {
      window.wp.a11y.speak(
        direction === 'up'
          ? (window.ceaFormsAdmin ? window.ceaFormsAdmin.movedUp : 'Item moved up.')
          : (window.ceaFormsAdmin ? window.ceaFormsAdmin.movedDown : 'Item moved down.')
      );
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
