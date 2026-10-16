/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Frontend error collector. Standalone on purpose (no Joomla core dependency),
 * so it can be loaded first and see errors from every later script.
 */
((window, document) => {
  const KEY = 'plg_system_frontenderrors';
  let options = window.Joomla && window.Joomla.getOptions ? window.Joomla.getOptions(KEY) : null;

  if (!options) {
    document.querySelectorAll('.joomla-script-options').forEach((el) => {
      try {
        const data = JSON.parse(el.text || el.textContent);
        options = data[KEY] || options;
      } catch (e) {
        // Ignore malformed options.
      }
    });
  }

  if (!options) {
    return;
  }

  const scan = /[?&]hc_scan=1(&|$)/.test(window.location.search);
  const nav = window.navigator;

  if (!scan && (nav.webdriver || /bot|crawl|spider|slurp/i.test(nav.userAgent) || Math.random() * 100 >= options.sample)) {
    return;
  }

  const MAX = 10;
  const seen = {};
  let reports = [];
  let timer = 0;
  let total = 0;

  // In a scan the list is never flushed, so the page can read it as it grows.
  if (scan) {
    window.JoomlaFrontendErrors = { reports, ready: false };
  }

  const flush = () => {
    if (!reports.length) {
      return;
    }

    const body = JSON.stringify({ page: window.location.pathname, reports });
    reports = [];
    timer = 0;
    nav.sendBeacon(options.endpoint, new Blob([body], { type: 'application/json' }));
  };

  const add = (type, message, file, line, col, stack) => {
    const msg = String(message);
    const key = [type, msg, file, line, col].join('|');

    if (seen[key]) {
      seen[key].count += 1;
      return;
    }

    if (total >= MAX) {
      return;
    }

    // Missing file, line, col and stack are filled in by the server.
    const report = {
      type, message: msg, file, line, col, stack, count: 1,
    };
    seen[key] = report;
    total += 1;
    reports.push(report);

    if (!scan && !timer) {
      timer = window.setTimeout(flush, 5000);
    }
  };

  window.addEventListener('error', (e) => {
    const t = e.target;

    if (t && t !== window && /^(SCRIPT|LINK|IMG)$/.test(t.tagName)) {
      add('resource', `Failed to load ${t.tagName.toLowerCase()}`, t.src || t.href);
    } else {
      add('error', e.message, e.filename, e.lineno, e.colno, e.error && e.error.stack);
    }
  }, true);

  window.addEventListener('unhandledrejection', (e) => {
    const r = e.reason;
    add('rejection', String((r && r.message) || r), '', 0, 0, r && r.stack);
  });

  document.addEventListener('securitypolicyviolation', (e) => {
    add('csp', e.violatedDirective, e.blockedURI, e.lineNumber, e.columnNumber);
  });

  if (options.console) {
    const original = window.console.error;
    window.console.error = (...args) => {
      add('console', args.join(' '));
      original.apply(window.console, args);
    };
  }

  if (scan) {
    window.addEventListener('load', () => {
      window.JoomlaFrontendErrors.ready = true;
    });
  } else {
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'hidden') {
        flush();
      }
    });
    window.addEventListener('pagehide', flush);
  }
})(window, document);
