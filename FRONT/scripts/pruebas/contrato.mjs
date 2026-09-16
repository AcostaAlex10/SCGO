// Reglas del contrato que no se pueden ejercitar desde la interfaz.
//
// Las otras cuatro suites manejan la pantalla, que es como lo usa una persona.
// Pero varias reglas viven solo en la capa de API: no hay boton para borrar un
// reporte ya enviado, ni para mandar un estado vacio. Sin estas pruebas, los
// cuatro defectos que cubren se arreglaron y nadie se enteraria si vuelven.
//
// Por eso el simulador expone `window.sgsoMockFetch` (ver servidor.ts): se le
// habla directo, con el mismo token que usa la app.
//
// Se corre igual que las demas, con el build estatico servido en :8123.
import { chromium } from 'playwright';

const BASE = process.env.BASE ?? 'http://127.0.0.1:8123/SCGO/';
const ok = [], mal = [];
const chequear = (q, c, d = '') => { (c ? ok : mal).push(q); console.log(`${c ? '  OK  ' : ' FALLA'} ${q}${d ? ' — ' + d : ''}`); };

const nav = await chromium.launch();
const page = await (await nav.newContext({ viewport: { width: 1400, height: 1000 } })).newPage();

const entrar = async () => {
  await page.waitForTimeout(700);
  if (await page.locator('input[type=email]').count()) {
    await page.fill('input[type=email]', 'admin@sgso.test');
    await page.fill('input[type=password]', 'admin123');
    await page.click('button[type=submit]');
    await page.waitForTimeout(2500);
  }
};

// Llama al simulador directo. El token `mock.1` es el admin, que tiene todos
// los roles: lo que se prueba aca son las reglas de negocio, no los permisos
// (de eso se ocupa humo.mjs).
const api = (metodo, ruta, cuerpo = null) => page.evaluate(async ([m, r, c]) => {
  const res = await window.sgsoMockFetch(r, {
    method: m,
    headers: { 'Content-Type': 'application/json', Authorization: 'Bearer mock.1' },
    body: c === null ? undefined : JSON.stringify(c),
  });
  let datos = null;
  try { datos = await res.json(); } catch { /* respuesta sin cuerpo */ }
  return { estado: res.status, datos };
}, [metodo, ruta, cuerpo]);

const estadoObra = async (id) => (await api('GET', `/proyectos/${id}`)).datos?.estado;

await page.goto(BASE, { waitUntil: 'networkidle' });
await entrar();
await page.evaluate(() => window.sgsoMockReset());
await page.waitForTimeout(2500);
await entrar();

chequear('el simulador expone la costura de prueba',
  await page.evaluate(() => typeof window.sgsoMockFetch === 'function'));

// ---- 1. Rechazar exige motivo (mock y PHP tienen que coincidir) ----
// El reporte 11 viene en revision en los datos base.
let r = await api('POST', '/reportes/11/rechazar', {});
chequear('rechazar sin motivo devuelve 422', r.estado === 422, `dio ${r.estado}`);
chequear('y el reporte sigue en revision',
  (await api('GET', '/reportes')).datos.find(x => x.id_reporte === 11)?.estado === 'en_revision');

r = await api('POST', '/reportes/11/rechazar', { observacion: '   ' });
chequear('un motivo de solo espacios tampoco alcanza', r.estado === 422, `dio ${r.estado}`);

// ---- 2. No se borra un reporte ya enviado ----
// Era el camino que dejaba la obra trabada: enviar el final y borrarlo.
chequear('la obra 1 arranca en ejecucion', await estadoObra('1') === 'en_ejecucion');

const creado = await api('POST', '/reportes', {
  id_proyecto: 1, titulo: 'Cierre de obra', contenido: 'Certificacion final', es_final: true,
});
const idFinal = creado.datos?.id_reporte;
chequear('se crea el reporte final en borrador',
  creado.estado === 201 && creado.datos?.es_final === true && creado.datos?.estado === 'borrador');

const enviado = await api('POST', `/reportes/${idFinal}/enviar`);
chequear('enviarlo lleva la obra a revision',
  enviado.datos?.estado_proyecto === 'en_revision' && await estadoObra('1') === 'en_revision',
  String(enviado.datos?.estado_proyecto));

const borrado = await api('DELETE', `/reportes/${idFinal}`);
chequear('borrar el reporte final en revision devuelve 409', borrado.estado === 409, `dio ${borrado.estado}`);
chequear('el reporte sigue existiendo',
  (await api('GET', '/reportes')).datos.some(x => x.id_reporte === idFinal));
// Una obra en revision es correcta solo si existe el reporte final que la puso
// ahi. Comprobar el estado solo no alcanza: es identico en el caso sano y en el
// trabado, que es justamente el defecto.
const enRevision = (await api('GET', '/reportes')).datos
  .some(x => x.id_proyecto === 1 && x.es_final && x.estado === 'en_revision');
chequear('la obra sigue en revision Y con su reporte final vivo',
  await estadoObra('1') === 'en_revision' && enRevision);

// La salida sigue siendo la prevista: resolverlo.
const rechazado = await api('POST', `/reportes/${idFinal}/rechazar`, { observacion: 'Faltan planos' });
chequear('rechazarlo con motivo devuelve la obra a ejecucion',
  rechazado.datos?.estado_proyecto === 'en_ejecucion' && await estadoObra('1') === 'en_ejecucion',
  String(rechazado.datos?.estado_proyecto));
chequear('y ahora si se puede borrar, porque quedo rechazado',
  (await api('DELETE', `/reportes/${idFinal}`)).estado === 200);

// ---- 3. Un estado vacio no pisa el de la obra ----
// Saltaba la validacion por venir vacio y se escribia igual.
const antesVacio = await estadoObra('1');
const conVacio = await api('PUT', '/proyectos/1', { nombre: 'Obra 1', estado: '' });
chequear('un PUT con estado vacio no falla', conVacio.estado === 200, `dio ${conVacio.estado}`);
chequear('y la obra conserva su estado',
  await estadoObra('1') === antesVacio, `${antesVacio} -> ${await estadoObra('1')}`);

// ---- 4. Una obra cancelada no recibe mas avance ----
const cancelada = await api('PUT', '/proyectos/1', { estado: 'cancelada' });
chequear('la obra 1 se puede cancelar desde ejecucion',
  cancelada.estado === 200 && await estadoObra('1') === 'cancelada');

// La planificacion 4 es la de la obra 1.
const avance = await api('POST', '/planificacion/4/avances', {
  cantidad_ejecutada: 10, porcentaje_avance: 55, fecha: '2026-09-10',
});
chequear('cargar avance en una obra cancelada devuelve 409', avance.estado === 409, `dio ${avance.estado}`);
chequear('la obra sigue cancelada', await estadoObra('1') === 'cancelada');

await nav.close();
console.log(`\n${ok.length}/${ok.length + mal.length} OK`);
if (mal.length) process.exit(1);
