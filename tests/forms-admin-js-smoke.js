'use strict';

const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const handlers = {};
const announcements = [];
let sortableOptions;

function makeButton(direction) {
  return {
    direction,
    disabled: false,
    focused: false,
    getAttribute(name) {
      return name === 'data-cea-move' ? this.direction : null;
    },
    closest() {
      return this.row;
    },
    focus() {
      this.focused = true;
    }
  };
}

function makeRow(name) {
  const row = {
    name,
    matches(selector) {
      return selector === '.cea-form-builder-row';
    },
    querySelector(selector) {
      if (selector === '[data-cea-move="up"]') {
        return this.up;
      }

      if (selector === '[data-cea-move="down"]') {
        return this.down;
      }

      return null;
    }
  };

  row.up = makeButton('up');
  row.down = makeButton('down');
  row.up.row = row;
  row.down.row = row;

  return row;
}

const first = makeRow('first');
const second = makeRow('second');
const list = {
  children: [first, second],
  insertBefore(node, reference) {
    this.children = this.children.filter((child) => child !== node);
    this.children.splice(this.children.indexOf(reference), 0, node);
  }
};

[first, second].forEach((row) => {
  row.parentElement = list;

  Object.defineProperty(row, 'previousElementSibling', {
    get() {
      const index = list.children.indexOf(row);
      return index > 0 ? list.children[index - 1] : null;
    }
  });

  Object.defineProperty(row, 'nextElementSibling', {
    get() {
      const index = list.children.indexOf(row);
      return index < list.children.length - 1 ? list.children[index + 1] : null;
    }
  });
});

const documentMock = {
  getElementById() {
    return null;
  },
  querySelectorAll() {
    return [];
  }
};

function jquery(argument) {
  if (typeof argument === 'function') {
    argument();
    return {};
  }

  if (argument === documentMock) {
    return {
      on(event, selector, callback) {
        handlers[selector] = callback;
        return this;
      }
    };
  }

  if (argument === '.cea-form-sortable') {
    return {
      sortable(options) {
        sortableOptions = options;
        return this;
      },
      each(callback) {
        callback.call(list);
        return this;
      }
    };
  }

  throw new Error('Unexpected jQuery argument in test.');
}

const context = {
  Array,
  Date,
  Math,
  document: documentMock,
  jQuery: jquery,
  navigator: {},
  window: {
    ceaFormsAdmin: {
      movedDown: 'Item moved down.',
      movedUp: 'Item moved up.',
      removeConfirm: 'Remove this item?',
      untitled: 'Untitled field'
    },
    wp: {
      a11y: {
        speak(message) {
          announcements.push(message);
        }
      }
    }
  }
};

const source = fs.readFileSync(path.join(__dirname, '..', 'assets', 'admin', 'forms.js'), 'utf8');
vm.runInNewContext(source, context);

assert.strictEqual(sortableOptions.handle, '.cea-form-drag-handle', 'Sortable must use the dedicated drag handle.');
assert.strictEqual(sortableOptions.cancel, 'input, textarea, select, option', 'The button drag handle must not be cancelled.');
assert.strictEqual(sortableOptions.distance, 5, 'Sortable must require intentional pointer movement.');
assert.strictEqual(first.up.disabled, true, 'The first row cannot move up.');
assert.strictEqual(second.down.disabled, true, 'The last row cannot move down.');

handlers['[data-cea-move]'].call(second.up);

assert.deepStrictEqual(list.children.map((row) => row.name), ['second', 'first'], 'Move up must reorder rows.');
assert.strictEqual(second.up.focused, true, 'Focus must remain on the activated move control.');
assert.strictEqual(announcements.pop(), 'Item moved up.', 'Move up must be announced.');

handlers['[data-cea-move]'].call(second.down);

assert.deepStrictEqual(list.children.map((row) => row.name), ['first', 'second'], 'Move down must reorder rows.');
assert.strictEqual(announcements.pop(), 'Item moved down.', 'Move down must be announced.');

process.stdout.write('CEA form-builder JavaScript smoke tests passed.\n');
