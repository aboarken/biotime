/**
 * Date picker for portal date fields (inputs marked data-bt-datepicker). The field shows and sends
 * DD/MM/YYYY; it is not typed into: a click (or Enter, Space, Arrow Down) opens a month calendar
 * in the portal's design, and choosing a day fills the field and fires "change" (filters.js then
 * applies the filter). Month and weekday names come from the page (data-months, data-days), so
 * they are translated. Weeks start on Monday, as in the Attendance Calendar.
 * Without JavaScript the field stays a plain text field for DD/MM/YYYY.
 *
 * @license GNU General Public License version 2 or later
 */
(() => {
  'use strict';

  const pad = (n) => String(n).padStart(2, '0');
  const format = (d) => `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`;
  const parse = (value) => {
    const m = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(value.trim());

    if (!m) {
      return null;
    }

    const d = new Date(Number(m[3]), Number(m[2]) - 1, Number(m[1]));

    return d.getDate() === Number(m[1]) ? d : null;
  };
  const sameDay = (a, b) => a && b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();

  const init = (input) => {
    const months = (input.dataset.months || '').split(',');
    const days = (input.dataset.days || '').split(',');
    const labels = JSON.parse(input.dataset.labels || '{}');
    const min = input.dataset.min ? parse(input.dataset.min) : null;
    // The month the page shows (data-month "MM/YYYY"): the calendar opens there when no date is set.
    const pageMonth = (() => {
      const m = /^(\d{1,2})\/(\d{4})$/.exec(input.dataset.month || '');

      return m ? new Date(Number(m[2]), Number(m[1]) - 1, 1) : null;
    })();

    input.readOnly = true;
    input.setAttribute('aria-haspopup', 'dialog');
    input.setAttribute('aria-expanded', 'false');

    const wrap = document.createElement('div');
    wrap.className = 'bt-datepicker';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    const pop = document.createElement('div');
    pop.className = 'bt-datepicker-pop';
    pop.setAttribute('role', 'dialog');
    pop.setAttribute('aria-modal', 'false');
    pop.setAttribute('aria-label', labels.choose || 'Choose a date');
    pop.hidden = true;
    wrap.appendChild(pop);

    let view = parse(input.value) || pageMonth || new Date();
    view = new Date(view.getFullYear(), view.getMonth(), 1);

    const choose = (d) => {
      input.value = d ? format(d) : '';
      close(true);
      input.dispatchEvent(new Event('change', { bubbles: true }));
    };

    const render = (focusDate) => {
      const selected = parse(input.value);
      const today = new Date();
      const first = new Date(view.getFullYear(), view.getMonth(), 1);
      const lead = (first.getDay() + 6) % 7; // Monday first
      const count = new Date(view.getFullYear(), view.getMonth() + 1, 0).getDate();
      const prevAllowed = !min || new Date(view.getFullYear(), view.getMonth(), 0) >= new Date(min.getFullYear(), min.getMonth(), 1);

      pop.innerHTML = '';

      const head = document.createElement('div');
      head.className = 'bt-datepicker-head';
      const prev = document.createElement('button');
      prev.type = 'button';
      prev.className = 'bt-datepicker-nav';
      prev.innerHTML = '&larr;';
      prev.setAttribute('aria-label', labels.prev || 'Previous month');
      prev.disabled = !prevAllowed;
      prev.addEventListener('click', () => { view = new Date(view.getFullYear(), view.getMonth() - 1, 1); render(); });
      const title = document.createElement('div');
      title.className = 'bt-datepicker-title';
      title.setAttribute('aria-live', 'polite');
      title.textContent = `${months[view.getMonth()] || view.getMonth() + 1} ${view.getFullYear()}`;
      const next = document.createElement('button');
      next.type = 'button';
      next.className = 'bt-datepicker-nav';
      next.innerHTML = '&rarr;';
      next.setAttribute('aria-label', labels.next || 'Next month');
      next.addEventListener('click', () => { view = new Date(view.getFullYear(), view.getMonth() + 1, 1); render(); });
      head.append(prev, title, next);
      pop.appendChild(head);

      const grid = document.createElement('div');
      grid.className = 'bt-datepicker-grid';
      grid.setAttribute('role', 'grid');
      days.forEach((name) => {
        const th = document.createElement('div');
        th.className = 'bt-datepicker-dow';
        th.textContent = name;
        grid.appendChild(th);
      });

      for (let i = 0; i < lead; i++) {
        grid.appendChild(document.createElement('span'));
      }

      let toFocus = null;

      for (let day = 1; day <= count; day++) {
        const d = new Date(view.getFullYear(), view.getMonth(), day);
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'bt-datepicker-day';
        b.textContent = String(day);
        b.dataset.date = format(d);
        b.setAttribute('aria-label', `${day} ${months[d.getMonth()] || ''} ${d.getFullYear()}`);
        b.tabIndex = -1;

        if (sameDay(d, today)) {
          b.classList.add('is-today');
        }

        if (sameDay(d, selected)) {
          b.classList.add('is-selected');
          b.setAttribute('aria-pressed', 'true');
        }

        if (min && d < min) {
          b.disabled = true;
        }

        b.addEventListener('click', () => choose(d));
        grid.appendChild(b);

        if (sameDay(d, focusDate) || (!focusDate && (sameDay(d, selected) || (!selected && sameDay(d, today))))) {
          toFocus = b;
        }
      }

      pop.appendChild(grid);

      const foot = document.createElement('div');
      foot.className = 'bt-datepicker-foot';
      const todayBtn = document.createElement('button');
      todayBtn.type = 'button';
      todayBtn.className = 'bt-datepicker-link';
      todayBtn.textContent = labels.today || 'Today';
      todayBtn.addEventListener('click', () => choose(new Date(today.getFullYear(), today.getMonth(), today.getDate())));
      foot.appendChild(todayBtn);

      if (input.value !== '') {
        const clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'bt-datepicker-link';
        clear.textContent = labels.clear || 'Clear';
        clear.addEventListener('click', () => choose(null));
        foot.appendChild(clear);
      }

      pop.appendChild(foot);

      const target = toFocus || grid.querySelector('.bt-datepicker-day:not([disabled])');

      if (target) {
        target.tabIndex = 0;

        if (!pop.hidden) {
          target.focus();
        }
      }
    };

    const open = () => {
      if (!pop.hidden) {
        return;
      }

      const start = parse(input.value) || pageMonth || new Date();
      view = new Date(start.getFullYear(), start.getMonth(), 1);
      pop.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      render();
    };

    function close(refocus) {
      if (pop.hidden) {
        return;
      }

      pop.hidden = true;
      input.setAttribute('aria-expanded', 'false');

      if (refocus) {
        input.focus();
      }
    }

    input.addEventListener('click', open);
    input.addEventListener('keydown', (event) => {
      if (['Enter', ' ', 'ArrowDown'].includes(event.key)) {
        event.preventDefault();
        event.stopPropagation();
        open();
      }
    });

    // Arrow keys move between days, Escape closes.
    pop.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        close(true);

        return;
      }

      const current = document.activeElement && document.activeElement.dataset ? parse(document.activeElement.dataset.date || '') : null;
      const step = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[event.key];

      if (!current || step === undefined) {
        return;
      }

      event.preventDefault();
      const d = new Date(current.getFullYear(), current.getMonth(), current.getDate() + step);

      if (d.getMonth() !== view.getMonth() || d.getFullYear() !== view.getFullYear()) {
        view = new Date(d.getFullYear(), d.getMonth(), 1);
      }

      render(d);
    });

    // Close on a click outside. composedPath() is taken when the click starts, so a click on the
    // month arrows (whose buttons are rebuilt by render()) still counts as inside.
    document.addEventListener('click', (event) => {
      if (!event.composedPath().includes(wrap)) {
        close(false);
      }
    });
  };

  const start = () => document.querySelectorAll('input[data-bt-datepicker]').forEach(init);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
