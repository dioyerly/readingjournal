(function () {
  'use strict';
  var URL_TRACK = 'track.php';
  function enviar(t, d) {
    try {
      var q = new URLSearchParams(location.search);
      var body = JSON.stringify({ t: t, d: d || '', r: document.referrer || '', u: q.get('utm_source') || q.get('ref') || '', c: q.get('utm_campaign') || '', w: innerWidth });
      if (navigator.sendBeacon && navigator.sendBeacon(URL_TRACK, new Blob([body], { type: 'application/json' }))) return;
      fetch(URL_TRACK, { method: 'POST', body: body, headers: { 'Content-Type': 'application/json' }, keepalive: true }).catch(function () { });
    } catch (e) { }
  }
  var hechos = {};
  function una(t, d) { var k = t + (d || ''); if (hechos[k]) return; hechos[k] = 1; enviar(t, d); }

  var yaVisto = false; try { yaVisto = sessionStorage.getItem('bw_v') === '1'; sessionStorage.setItem('bw_v', '1'); } catch (e) { }
  if (!yaVisto) enviar('visita');

  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a,button'); if (!a) return;
    if (a.hasAttribute('data-sub')) {
      var todos = [].slice.call(document.querySelectorAll('[data-sub]')), i = todos.indexOf(a);
      enviar('click_sub', i === 0 ? 'menu' : (i === todos.length - 1 ? 'precio' : 'portada'));
    } else {
      var h = (a.getAttribute('href') || '');
      if (/wa\.me|whatsapp/i.test(h)) enviar('click_wa');
      else if (/app\.html/i.test(h)) enviar('click_app');
    }
  }, true);

  function scroll() {
    var h = document.documentElement, p = (window.scrollY + innerHeight) / Math.max(1, h.scrollHeight);
    if (p >= .5) una('scroll50'); if (p >= .9) una('scroll90');
  }
  addEventListener('scroll', scroll, { passive: true });
  var pr = document.getElementById('precio');
  if (pr && 'IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (es) { es.forEach(function (x) { if (x.isIntersecting) { una('precio'); io.disconnect(); } }); }, { threshold: .4 });
    io.observe(pr);
  }
})();