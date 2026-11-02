// Runs before the page paints so a saved dark theme does not flash white. Light is the default.
(function () {
  try {
    var saved = window.localStorage.getItem('hpos-theme');
    if (saved === 'dark' || saved === 'light') { document.documentElement.setAttribute('data-theme', saved); }
  } catch (e) { /* storage blocked: stay on light */ }
})();
