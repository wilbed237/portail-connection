/* Infobulles des graphiques (survol, toucher et clavier). Les textes sont insérés avec textContent. */
(function () {
  var tip = null;
  function show(el) {
    tip = tip || document.getElementById('tip');
    if (!tip) return;
    tip.replaceChildren();
    var v = document.createElement('strong'); v.textContent = el.getAttribute('data-tip');
    var l = document.createElement('span'); l.textContent = el.getAttribute('data-tip-label') || '';
    tip.append(v, l);
    tip.hidden = false;
    var r = el.getBoundingClientRect(), w = tip.offsetWidth, h = tip.offsetHeight;
    var x = Math.min(Math.max(8, r.left + r.width / 2 - w / 2), window.innerWidth - w - 8);
    var y = r.top - h - 8; if (y < 8) y = r.bottom + 8;
    tip.style.left = x + 'px'; tip.style.top = y + 'px';
  }
  function hide() { if (tip) tip.hidden = true; }
  function target(e) { return e.target.closest && e.target.closest('[data-tip]'); }
  document.addEventListener('pointerover', function (e) { var t = target(e); if (t) show(t); });
  document.addEventListener('pointerout', function (e) { var t = target(e); if (t && !t.contains(e.relatedTarget)) hide(); });
  document.addEventListener('focusin', function (e) { var t = target(e); if (t) show(t); else hide(); });
  document.addEventListener('focusout', hide);
  window.addEventListener('scroll', hide, { passive: true });
  // Listes déroulantes qui appliquent le filtre dès qu'on change la valeur
  document.addEventListener('change', function (e) { if (e.target.matches('select[data-autosubmit]')) e.target.form.submit(); });
})();
