// FB-27: apply the saved theme before first paint (external file: the CSP forbids inline scripts).
try {
  var t = localStorage.getItem('wave-theme');
  if (t === 'light' || t === 'dark') document.documentElement.dataset.theme = t;
} catch (e) {
  /* storage unavailable: follow the system */
}
