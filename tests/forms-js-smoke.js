'use strict';

const assert = require('node:assert');
const fs = require('node:fs');
const vm = require('node:vm');

const listeners = {};
const startedAt = { value: '1000' };
const token = { value: '' };

const form = {
  matches(selector) {
    return selector === '.cea-form__form';
  },
  querySelector(selector) {
    if (selector === '[data-cea-submission-token]') {
      return token;
    }

    if (selector === '[data-cea-started-at]') {
      return startedAt;
    }

    return null;
  }
};

const context = {
  Date: {
    now() {
      return 5000000;
    }
  },
  Math,
  document: {
    addEventListener(type, callback) {
      listeners[type] = callback;
    },
    querySelector() {
      return null;
    },
    querySelectorAll(selector) {
      return selector === '.cea-form__form' ? [form] : [];
    }
  },
  window: {
    crypto: {
      randomUUID() {
        return 'fixed-submission-token';
      }
    }
  }
};

const source = fs.readFileSync(
  require('node:path').join(__dirname, '..', 'assets', 'public', 'forms.js'),
  'utf8'
);

vm.runInNewContext(source, context);

listeners.DOMContentLoaded();

assert.strictEqual(startedAt.value, '1000', 'Page initialization must preserve the server-rendered start time.');
assert.strictEqual(token.value, 'fixed-submission-token', 'Page initialization must add an idempotency token.');

listeners.submit({ target: form });

assert.strictEqual(startedAt.value, '1000', 'Submitting must not reset the elapsed-time start value.');

startedAt.value = '';
token.value = '';
listeners.submit({ target: form });

assert.strictEqual(startedAt.value, 5000, 'Dynamically inserted forms must receive a start time when empty.');
assert.strictEqual(token.value, 'fixed-submission-token', 'Dynamically inserted forms must receive an idempotency token.');

process.stdout.write('CEA public forms JavaScript smoke tests passed.\n');
