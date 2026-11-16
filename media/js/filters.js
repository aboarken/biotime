/**
 * Filter bars of the portal pages (forms marked data-bt-filters) have no Search button:
 *  - choosing an option in a list (month, year, status, health, location) applies it at once;
 *  - a date is applied when it is chosen in the date picker (datepicker.js) or cleared; without
 *    JavaScript it is typed as DD/MM/YYYY.
 * A filter change always starts again at page 1 (the form does not send "page").
 * Without JavaScript the bar's own "Show" button (hidden by this script) submits it.
 *
 * @license GNU General Public License version 2 or later
 */
(() => {
  'use strict';

  const submit = (form) => {
    if (form.dataset.btSubmitting === '1') {
      return;
    }

    form.dataset.btSubmitting = '1';
    form.setAttribute('aria-busy', 'true');

    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.submit();
    }
  };

  const init = (form) => {
    form.querySelectorAll('.bt-toolbar-submit').forEach((el) => {
      el.hidden = true;
    });

    form.querySelectorAll('select').forEach((field) => {
      field.addEventListener('change', () => submit(form));
    });

    form.querySelectorAll('input[pattern]').forEach((field) => {
      const initial = field.value;

      field.addEventListener('change', () => {
        if (field.value !== initial && (field.value === '' || field.checkValidity())) {
          submit(form);
        }
      });
    });

    // Date picker fields (datepicker.js) use Enter to open the calendar.
    form.querySelectorAll('input:not([data-bt-datepicker])').forEach((input) => {
      input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.isComposing) {
          event.preventDefault();
          submit(form);
        }
      });
    });
  };

  const start = () => document.querySelectorAll('form[data-bt-filters]').forEach(init);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
