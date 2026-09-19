// Reglas del contrato que no se pueden ejercitar desde la interfaz.
//
// Las otras cuatro suites manejan la pantalla, que es como lo usa una persona.
// Pero varias reglas viven solo en la capa de API: no hay boton para borrar un
// reporte ya enviado, ni para mandar un estado vacio. Sin estas pruebas, los
// defectos que cubren se arreglaron y nadie se enteraria si vuelven.
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

// ---- 5. Un documento no puede ser un enlace que ejecuta codigo (A-01) ----
// La interfaz muestra la URL como un enlace: con el esquema javascript:, abrirlo
// ejecuta codigo en la sesion de quien hace clic. PHP lo rechaza con 422 y el
// simulador tiene que hacer lo mismo. La obra 2 no se toca en los casos de arriba.
const malicioso = await api('POST', '/proyectos/2/documentos', {
  nombre: 'Plano de planta', tipo: 'pdf', url: 'javascript://x%0Aalert(document.cookie)',
});
chequear('un enlace javascript: devuelve 422', malicioso.estado === 422, `dio ${malicioso.estado}`);
const guardados = (await api('GET', '/proyectos/2/documentos')).datos ?? [];
chequear('y no queda guardado',
  !guardados.some((d) => String(d.url).toLowerCase().startsWith('javascript')));

const valido = await api('POST', '/proyectos/2/documentos', {
  nombre: 'Plano de planta', tipo: 'pdf', url: 'https://drive.google.com/file/d/abc123/view',
});
chequear('un enlace https se guarda', valido.estado === 201, `dio ${valido.estado}`);

// ---- 6. Los fallos de login se limitan por cuenta (A-03) ----
for (let i = 0; i < 5; i++) {
  r = await api('POST', '/auth/login', { email: 'tecnico@sgso.test', contrasena: 'equivocada' });
  chequear(`el fallo ${i + 1} de tecnico devuelve 401`,
    r.estado === 401 && r.datos?.error === 'Credenciales invalidas', `dio ${r.estado}`);
}
const bloqueado = await api('POST', '/auth/login', {
  email: 'tecnico@sgso.test', contrasena: 'tecnico123',
});
chequear('el sexto intento de tecnico bloquea incluso la clave correcta',
  bloqueado.estado === 429 && !bloqueado.datos?.token && bloqueado.datos?.reintentar_en_segundos > 0,
  `dio ${bloqueado.estado}`);

const otraCuenta = await api('POST', '/auth/login', {
  email: 'administrativo@sgso.test', contrasena: 'admin123',
});
chequear('el bloqueo de tecnico no afecta a administrativo', otraCuenta.estado === 200, `dio ${otraCuenta.estado}`);

r = await api('POST', '/auth/login', { email: 'tecnico2@sgso.test', contrasena: 'equivocada' });
chequear('la cuenta inactiva con clave incorrecta responde credenciales invalidas',
  r.estado === 401 && r.datos?.error === 'Credenciales invalidas', `dio ${r.estado}`);
r = await api('POST', '/auth/login', { email: 'tecnico2@sgso.test', contrasena: 'tecnico123' });
chequear('la cuenta inactiva con clave correcta responde 403', r.estado === 403, `dio ${r.estado}`);

// ---- 7. Una contraseña corta no se acepta al crear un usuario (A-05) ----
// El mínimo pasó de 6 a 10 caracteres. Vale la pena probarlo acá porque el
// simulador tiene su propia copia de la regla: si se desincroniza del PHP, la
// demo acepta contraseñas que en produccion fallan.
r = await api('POST', '/auth/register', {
  nombre: 'Prueba corta', email: `corta-${Date.now()}@sgso.test`, contrasena: 'corta123', rol: 'PersonalTecnico',
});
chequear('una contrasena de menos de 10 caracteres devuelve 422', r.estado === 422, `dio ${r.estado}`);

r = await api('POST', '/auth/register', {
  nombre: 'Prueba comun', email: `comun-${Date.now()}@sgso.test`, contrasena: 'password123', rol: 'PersonalTecnico',
});
chequear('una de las contrasenas mas usadas devuelve 422, aunque sea larga', r.estado === 422, `dio ${r.estado}`);

r = await api('POST', '/auth/register', {
  nombre: 'Prueba valida', email: `valida-${Date.now()}@sgso.test`, contrasena: 'obras-triwe-2026', rol: 'PersonalTecnico',
});
chequear('una contrasena larga y no comun se acepta', r.estado === 201, `dio ${r.estado}`);

await nav.close();
console.log(`\n${ok.length}/${ok.length + mal.length} OK`);
if (mal.length) process.exit(1);
