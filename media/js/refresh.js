/**
 * The page was shown with data older than the cache time (1.2.6, see DisplayController), so it
 * opened at once. It is loaded again in the background, never from older data, and its content is
 * replaced:
 *  - "Showing your data from … Updating…" is shown above the title meanwhile (role=status, so
 *    screen readers hear it);
 *  - if the user has started choosing in the page (a filter, a date), it is not replaced: a
 *    "Reload the page" link is offered instead;
 *  - if the page no longer exists for the user (403/404), or is another page now (login,
 *    "could not be matched"), it is loaded normally instead, so the older copy is never kept;
 *  - if the update fails, or brings an error where the shown page had none (ZKBioTime
 *    unavailable), the shown content stays and the note says so.
 * The page's scripts (filters, date picker) attach to the new content on the "bt:refreshed" event.
 *
 * The page content is found by its data-bt-root attribute, never by the class "com-biotime":
 * some site templates put that class on <body> as well.
 *
 * @license GNU General Public License version 2 or later
 */
(() => {
  'use strict';

  const options = window.Joomla && Joomla.getOptions ? Joomla.getOptions('com_biotime.refresh') : null;

  // Only ever this site: the page's own path, never another origin.
  const sameOrigin = (url) => {
    try {
      return new URL(url, window.location.href).origin === window.location.origin;
    } catch (e) {
      return false;
    }
  };

  if (!options || !options.url || !sameOrigin(options.url) || (options.page && !sameOrigin(options.page))) {
    return;
  }

  const text = (key) => (Joomla.Text._(key) || key).replace('%s', options.since || '');
  const ROOT = '[data-bt-root]';

  const start = () => {
    const root = document.querySelector(ROOT);

    if (!root) {
      return;
    }

    let touched = false;
    const touch = () => { touched = true; };
    root.addEventListener('input', touch);
    root.addEventListener('change', touch);

    const note = document.createElement('p');
    note.className = 'bt-refresh-note';
    note.setAttribute('role', 'status');
    note.textContent = text('COM_BIOTIME_REFRESH_UPDATING');
    root.prepend(note);
    root.setAttribute('aria-busy', 'true');

    // The reason (the page's own error message, or the HTTP status) is added, so the note says
    // why the page could not be updated.
    const fail = (reason) => {
      const why = reason instanceof Error ? reason.message : (reason || '');
      note.textContent = text('COM_BIOTIME_REFRESH_FAILED') + (why ? ' (' + why + ')' : '');
      note.classList.add('bt-refresh-note--error');

      if (window.console) {
        console.warn('ZKBioTime portal: page not updated', why || '');
      }
    };

    fetch(options.url, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
      .then((response) => {
        // The page no longer exists for this user: never keep showing the older copy; load it
        // normally, so its real answer is shown.
        if ((response.status === 403 || response.status === 404) && options.page) {
          window.location.replace(options.page);

          return new Promise(() => {});
        }

        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }

        return response.text();
      })
      .then((html) => {
        if (html === undefined) {
          return;
        }

        const fresh = new DOMParser().parseFromString(html, 'text/html').querySelector(ROOT);

        // Another page now (login, "could not be matched"): the user may no longer see this
        // content, so the older copy is not kept; the page is loaded normally.
        if (!fresh || fresh.getAttribute('data-bt-root') !== root.getAttribute('data-bt-root')) {
          if (options.page) {
            window.location.replace(options.page);

            return;
          }

          fail('other page');

          return;
        }

        // An error (e.g. ZKBioTime unavailable) where the shown page had none.
        const alert = fresh.querySelector('[role="alert"]');

        if (alert && !root.querySelector('[role="alert"]')) {
          fail(alert.textContent.trim().replace(/\s+/g, ' ').slice(0, 200));

          return;
        }

        if (touched) {
          note.textContent = text('COM_BIOTIME_REFRESH_READY') + ' ';
          const link = document.createElement('a');
          link.href = window.location.href;
          link.textContent = text('COM_BIOTIME_REFRESH_RELOAD');
          note.append(link);

          return;
        }

        const node = document.importNode(fresh, true);
        root.replaceWith(node);
        document.dispatchEvent(new CustomEvent('bt:refreshed'));
      })
      .catch(fail)
      .finally(() => root.removeAttribute('aria-busy'));
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
