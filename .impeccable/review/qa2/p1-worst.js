(() => {
  const h = [...document.querySelectorAll('header')].find(e => getComputedStyle(e).display !== 'none');
  const rate = [...h.querySelectorAll(':scope > span')].find(s => s.textContent.startsWith('Dólar'));
  const age = rate.children[2];
  age.textContent = 'puede haber cambiado'; age.className = 'text-xs text-warning';
  const name = h.querySelector('span.max-w-56');
  name.textContent = 'Inversiones y Variedades La Bendición de Dios C.A.';
  const chat = [...h.querySelectorAll('nav a')].find(a => a.textContent.includes('Chat'));
  const b = document.createElement('span'); b.className = 'badge badge-sm badge-primary ml-1'; b.textContent = '12'; chat.appendChild(b);
  const kids = [...h.children].map(c => { const r = c.getBoundingClientRect(); return { txt: c.textContent.slice(0,25), l: Math.round(r.left), r: Math.round(r.right), w: Math.round(r.width) }; });
  const rr = rate.getBoundingClientRect();
  return JSON.stringify({ sw: h.scrollWidth, cw: h.clientWidth, nameW: Math.round(name.getBoundingClientRect().width), nameTrunc: name.scrollWidth > name.clientWidth, rate: { w: Math.round(rr.width), sw: rate.scrollWidth, cw: rate.clientWidth }, kids });
})()
