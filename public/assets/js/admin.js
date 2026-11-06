/* EQ Finance — admin behaviour (vanilla JS, no dependencies). */
(function () {
  'use strict';

  // Sidebar (mobile)
  var layout = document.querySelector('.layout');
  document.querySelectorAll('[data-sidebar-toggle]').forEach(function (el) {
    el.addEventListener('click', function () {
      if (layout) layout.classList.toggle('sidebar-open');
    });
  });

  // Theme toggle: auto -> dark -> light
  var root = document.documentElement;
  document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var current = root.getAttribute('data-theme');
      var systemDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
      var effectiveDark = current === 'dark' || (current !== 'light' && systemDark);
      var next = effectiveDark ? 'light' : 'dark';
      root.setAttribute('data-theme', next);
      try { localStorage.setItem('eqf-theme', next); } catch (e) {}
    });
  });

  // Confirmation prompts
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (ev) {
      if (!window.confirm(el.getAttribute('data-confirm'))) {
        ev.preventDefault();
      }
    });
  });

  // Copy to clipboard
  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var target = document.querySelector(btn.getAttribute('data-copy'));
      if (!target) return;
      var text = target.textContent.trim();
      var done = function () {
        var old = btn.textContent;
        btn.textContent = 'Copied';
        setTimeout(function () { btn.textContent = old; }, 1600);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text); done(); });
      } else {
        fallbackCopy(text); done();
      }
    });
  });

  function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'absolute';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
  }
})();
