import fs from 'node:fs';
const css = fs.readFileSync('base.css','utf8');
const fonts = '<link href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,400..900&family=Gochi+Hand&display=swap" rel="stylesheet">';
const side = (on) => `<aside class="side"><div class="logo">apartar</div><div class="org">Moda Valentina<small>Plan Pro · Dueña</small></div>
${['Hoy','Comprobantes <b>3</b>','Cobranza','Clientes','Chat <b>2</b>','Productos','Catálogos'].map((x,i)=>`<div class="nav${i===on?' on':''}">${x}</div>`).join('')}<div class="nav more">Más…</div></aside>`;
const page = (title, body, on=0) => `<!doctype html><html lang="es"><head><meta charset="utf-8"><title>${title}</title>${fonts}<style>${css}</style></head><body><div class="app">${side(on)}<main class="main">${body}</main></div></body></html>`;

const lead = page('Mostrador por cliente', `
<div class="top"><h1>Buenas tardes, Valentina</h1><div class="rate">Tasa BCV hoy <span>Bs. 893,22</span></div></div>
<div style="display:flex;gap:12px;margin-bottom:24px"><div style="flex:1;padding:14px 16px;border-radius:6px;box-shadow:inset 0 0 0 2px #15171b;font-size:18px;color:#5f646d">¿A quién buscas? Nombre, cédula o código</div><div class="btn" style="padding:14px 20px;font-size:16px">Nuevo apartado</div></div>
<div class="h">Clientes con algo pendiente hoy</div>
${[['María Gómez','Vestido de lino · cuota vence hoy','resta $24,00','','Recordar por WhatsApp'],['José Rivas','Bolso de cuero · mandó comprobante $20,00','por aprobar','blue','Revisar'],['Carla Méndez','Sandalias · atrasada 3 días','$15,00','red','Recordar por WhatsApp'],['Luis Prado','2 apartados · próxima 19 nov','resta $61,50','','Ver'],['Ana Torres','Perfume · pagado completo','listo para entregar','green','Marcar entregado']].map(([n,s,a,c,b])=>`<div class="row"><div style="display:flex;gap:14px;align-items:center"><div style="width:40px;height:40px;border-radius:50%;background:#e2e5e9;display:grid;place-items:center;font-weight:800">${n[0]}</div><div><div class="n">${n}</div><div class="s">${s}</div></div></div><div style="display:flex;gap:18px;align-items:center"><span class="n ${c}">${a}</span><span class="btn ${b==='Ver'?'l':''}">${b}</span></div></div>`).join('')}
`);

const cal = page('Calendario de quincena', `
<div class="top"><h1>Esta quincena</h1><div class="rate">Tasa BCV hoy <span>Bs. 893,22</span></div></div>
<div style="display:flex;gap:10px;margin-bottom:18px;align-items:center"><span class="btn" style="background:#15171b">3 comprobantes por revisar</span><span class="hand" style="font-size:22px">entró $412,00 · falta $1.284,50</span></div>
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:22px">
${[['Atrasadas',[['Carla Méndez','$15,00','red'],['Pedro Ruiz','$9,00','red']]],['Hoy · mié 4',[['María Gómez','$12,00',''],['Rosa Díaz','$20,00','']]],['Esta semana',[['Luis Prado','$18,50',''],['Elena Paz','$12,00',''],['Juan Mora','$30,00','']]],['Resto de la quincena',[['Ana Torres','$10,00',''],['José Rivas','$20,00','']]]].map(([t,rows],i)=>`<div><div class="h ${i===0?'red':''}">${t}</div>${rows.map(([n,a,c])=>`<div class="row" style="flex-direction:column;align-items:flex-start;gap:6px"><div style="display:flex;justify-content:space-between;width:100%"><span class="n">${n}</span><span class="n ${c}">${a}</span></div><span class="btn l" style="padding:5px 10px;font-size:13px">Recordar</span></div>`).join('')}</div>`).join('')}
</div>`, 2);

const board = page('Pizarra del mostrador', `
<div class="top"><h1>Hoy, miércoles 4</h1><div class="rate">Tasa BCV hoy <span>Bs. 893,22</span></div></div>
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:36px">
<div><div class="hand" style="font-size:24px">Por revisar</div><div class="fig blue">3</div><div class="s" style="color:#5f646d;margin-bottom:10px">comprobantes</div>${[['José Rivas','$20,00'],['Marta León','Bs. 12.898,84'],['Elena Paz','$12,00']].map(([n,a])=>`<div class="row"><span class="n">${n}</span><span>${a}</span><span class="btn">Revisar</span></div>`).join('')}</div>
<div><div class="hand" style="font-size:24px">Por cobrar hoy</div><div class="fig red">$51,00</div><div class="s" style="color:#5f646d;margin-bottom:10px">4 clientes · 2 atrasados</div>${[['Carla Méndez','$15,00 · 3 días','red'],['María Gómez','$12,00 · hoy',''],['Rosa Díaz','$20,00 · hoy','']].map(([n,a,c])=>`<div class="row"><span class="n">${n}</span><span class="${c}">${a}</span><span class="btn l">WhatsApp</span></div>`).join('')}</div>
<div><div class="hand" style="font-size:24px">Este mes</div><div class="fig green">$412,00</div><div class="s" style="color:#5f646d;margin-bottom:10px">entró · falta $1.284,50</div>${[['Ana Torres','pagó completo'],['Luis Prado','abonó $18,50'],['Juan Mora','apartó nuevo']].map(([n,a])=>`<div class="row"><span class="n">${n}</span><span class="s">${a}</span></div>`).join('')}</div>
</div>`);

fs.writeFileSync('lead.html', lead); fs.writeFileSync('calendar.html', cal); fs.writeFileSync('board.html', board);
