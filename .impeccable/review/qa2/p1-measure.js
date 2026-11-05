(() => {
  const h = [...document.querySelectorAll('header')].find(e => getComputedStyle(e).display !== 'none');
  const hr = h.getBoundingClientRect();
  const rate = [...h.querySelectorAll('span')].find(s => s.textContent.startsWith('Dólar'));
  const strips = [...document.querySelectorAll('div')].filter(d => d.textContent.startsWith('Dólar') && d.className.includes('border-t'));
  const r = rate ? rate.getBoundingClientRect() : null;
  const kids = [...h.children].map(c => { const b = c.getBoundingClientRect(); return { tag: c.tagName, txt: c.textContent.slice(0,30), l: Math.round(b.left), r: Math.round(b.right), w: Math.round(b.width), vis: getComputedStyle(c).display }; });
  const ageSpan = rate ? rate.children[2] : null;
  return JSON.stringify({ vw: innerWidth, scrollY, header: { top: hr.top, h: hr.height, w: hr.width, sw: h.scrollWidth, cw: h.clientWidth, pos: getComputedStyle(h).position }, rate: r && { top: r.top, l: r.left, r: r.right, w: r.width, sw: rate.scrollWidth, text: rate.innerText, ageDisplay: ageSpan && getComputedStyle(ageSpan).display }, strips: strips.map(s => ({ display: getComputedStyle(s).display, top: s.getBoundingClientRect().top })), kids });
})()
