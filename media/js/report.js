/**
 * Manager Dashboard report (Export PDF, 1.0.11): opens the browser's print dialog once the page
 * and its letterhead image have loaded, where "Save as PDF" makes the PDF. The page's own
 * "Print / Save as PDF" button opens it again.
 *
 * @license GNU General Public License version 2 or later
 */
(() => {
  'use strict';

  const print = () => window.print();

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-bt-print]').forEach((button) => {
      button.addEventListener('click', print);
    });

    document.querySelectorAll('[data-bt-close]').forEach((button) => {
      button.addEventListener('click', () => window.close());
    });
  });

  window.addEventListener('load', () => {
    if (document.querySelector('[data-bt-report][data-autoprint="1"]')) {
      window.setTimeout(print, 300);
    }
  });
})();
