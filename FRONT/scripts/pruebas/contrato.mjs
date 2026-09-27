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

// SGSO_URL, como las otras cuatro suites. BASE queda por compatibilidad: era la
// unica que la leia, y correrla contra otra URL con SGSO_URL iba en silencio a
// localhost.
const BASE = process.env.SGSO_URL || process.env.BASE || 'http://127.0.0.1:8123/SCGO/';
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
// (de eso se ocupa humo.mjs). Otro token solo hace falta para ver lo que
// recibe cada rol, como los importes que no le llegan al Tecnico (RF20).
const api = (metodo, ruta, cuerpo = null, token = 'mock.1') => page.evaluate(async ([m, r, c, t]) => {
  const res = await window.sgsoMockFetch(r, {
    method: m,
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${t}` },
    body: c === null ? undefined : JSON.stringify(c),
  });
  let datos = null;
  try { datos = await res.json(); } catch { /* respuesta sin cuerpo */ }
  return { estado: res.status, datos, total: res.headers.get('X-Total-Count') };
}, [metodo, ruta, cuerpo, token]);

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

// ---- 8. Corregir una sola fecha de una etapa se compara con la otra (C-01) ----
// La etapa 1 de los datos base va del 2026-06-29 al 2026-07-09. La API comparaba
// inicio y fin solo si llegaban los dos, y el simulador no validaba nada al editar.
r = await api('PUT', '/planificacion/etapa/1', { fecha_fin: '2026-06-01' });
chequear('corregir solo la fecha de fin a antes del inicio devuelve 422', r.estado === 422, `dio ${r.estado}`);

r = await api('PUT', '/planificacion/etapa/1', { fecha_inicio: '2026-08-01' });
chequear('corregir solo la fecha de inicio a despues del fin devuelve 422', r.estado === 422, `dio ${r.estado}`);

r = await api('PUT', '/planificacion/etapa/1', { peso_porcentual: 101 });
chequear('un peso mayor a 100 al editar devuelve 422', r.estado === 422, `dio ${r.estado}`);

r = await api('PUT', '/planificacion/etapa/1', { fecha_fin: '2026-07-20' });
chequear('corregir solo la fecha de fin dentro del rango se acepta', r.estado === 200, `dio ${r.estado}`);

// ---- 9. El uso de una maquina no acepta valores negativos ni obras inexistentes (C-01) ----
// Del uso cargado sale el promedio de la alerta de consumo (RF24). La API los
// aceptaba (y la obra inexistente le daba 500); el simulador no validaba nada.
r = await api('POST', '/maquinaria/1/registros', { fecha: '2026-03-01', horas_uso: 4, combustible_consumido: -10 });
chequear('un registro de maquinaria con combustible negativo devuelve 422', r.estado === 422, `dio ${r.estado}`);

r = await api('POST', '/maquinaria/1/registros', { fecha: '2026-03-01', horas_uso: 4, id_proyecto: 99999 });
chequear('un registro de maquinaria con una obra inexistente devuelve 422', r.estado === 422, `dio ${r.estado}`);

r = await api('POST', '/maquinaria/1/fallas', { descripcion: 'Perdida de aceite' });
chequear('una falla sin fecha devuelve 422', r.estado === 422, `dio ${r.estado}`);

r = await api('POST', '/maquinaria/1/registros', { fecha: '2026-03-01', horas_uso: 4, combustible_consumido: 20 });
chequear('un registro de maquinaria valido se acepta', r.estado === 201, `dio ${r.estado}`);

// ---- 10. Los listados que crecen se paginan a pedido (C-03) ----
// Sin ?limite= van enteros, como siempre. Con limite y desde, la pagina y el
// total en X-Total-Count, en el mismo orden que la API: lo mas reciente primero.
const todos = await api('GET', '/reportes');
chequear('sin pedir pagina, los reportes van enteros y sin total',
  todos.estado === 200 && todos.datos.length >= 3 && todos.total === null, `dio ${todos.datos?.length} y ${todos.total}`);
const fechas = todos.datos.map((x) => x.fecha_creacion);
chequear('los reportes van del mas reciente al mas viejo',
  fechas.every((f, i) => i === 0 || fechas[i - 1] >= f), fechas.join(', '));

const ids = (lista) => lista.map((x) => x.id_reporte).join(',');
r = await api('GET', '/reportes?limite=2');
chequear('la primera pagina trae las dos mas recientes y el total',
  ids(r.datos) === ids(todos.datos.slice(0, 2)) && r.total === String(todos.datos.length), `dio ${ids(r.datos)} y ${r.total}`);
r = await api('GET', '/reportes?limite=2&desde=2');
chequear('la segunda pagina sigue donde termino la primera',
  ids(r.datos) === ids(todos.datos.slice(2, 4)), `dio ${ids(r.datos)}`);

const reportesEnRevision = todos.datos.filter((x) => x.estado === 'en_revision').length;
r = await api('GET', '/reportes?estado=en_revision&limite=1');
chequear('el total respeta el filtro por estado',
  r.datos.length === 1 && r.total === String(reportesEnRevision), `dio ${r.total}, esperaba ${reportesEnRevision}`);

r = await api('GET', '/proyectos/2/asistencias?limite=abc');
chequear('un limite que no es un numero devuelve 422', r.estado === 422 && Boolean(r.datos?.errors?.limite), `dio ${r.estado}`);
r = await api('GET', '/proyectos/2/asistencias?limite=5&desde=-1');
chequear('un desde negativo devuelve 422', r.estado === 422 && Boolean(r.datos?.errors?.desde), `dio ${r.estado}`);

// En pantalla: la obra 2 con mas asistencias que una pagina muestra la primera
// y ofrece el resto, con el total real.
for (let i = 0; i < 52; i++) {
  await api('POST', '/proyectos/2/asistencias', { fecha: '2026-03-01', trabajador: `Operario ${i}`, estado: 'presente' });
}
const asistenciasObra2 = (await api('GET', '/proyectos/2/asistencias')).datos.length;
await page.goto(BASE + '#/proyectos/2', { waitUntil: 'networkidle' });
await page.waitForTimeout(1500);
const verMas = page.getByRole('button', { name: /Ver más/ });
chequear('la obra muestra el boton para ver mas asistencias con el total',
  (await verMas.count()) === 1 && (await verMas.innerText()).includes(`50 de ${asistenciasObra2}`),
  (await verMas.count()) ? await verMas.innerText() : 'sin boton');
if (await verMas.count()) {
  await verMas.click();
  await page.waitForTimeout(800);
}
chequear('ver mas trae el resto y el boton desaparece',
  (await page.getByText(/^Operario \d+$/).count()) === 52 && (await verMas.count()) === 0,
  `se ven ${await page.getByText(/^Operario \d+$/).count()}`);

// ---- 11. El consumo anomalo de maquinaria llega a Alertas (D-03) ----
// La API lo marcaba solo en el listado de la maquina. El feed tiene que traer
// una alerta por maquina, con la misma regla: mas de 1,5 veces su promedio.
const maquina = await api('POST', '/maquinaria', { nombre: 'Excavadora de contrato', tipo: 'Movimiento de suelos' });
const idMaq = maquina.datos?.id_maquinaria;
for (const [fecha, litros] of [['2026-03-01', 10], ['2026-03-02', 10], ['2026-03-03', 40]]) {
  await api('POST', `/maquinaria/${idMaq}/registros`, { fecha, horas_uso: 1, combustible_consumido: litros, id_proyecto: 1 });
}
const obra1 = (await api('GET', '/proyectos/1')).datos?.nombre;
let feed = (await api('GET', '/analisis')).datos.alertas;
const alertaMaq = feed.find((a) => a.tipo === 'maquinaria' && a.maquina === 'Excavadora de contrato');
chequear('el consumo anomalo aparece en el feed de alertas, con la obra y la fecha',
  alertaMaq?.proyecto === obra1 && alertaMaq?.gravedad === 'media' && String(alertaMaq?.mensaje).includes('03/03/2026'),
  JSON.stringify(alertaMaq ?? null));

// El feed y el listado de cada maquina dicen lo mismo.
let discrepan = [];
for (const m of (await api('GET', '/maquinaria')).datos) {
  const marcados = (await api('GET', `/maquinaria/${m.id_maquinaria}/registros`)).datos.filter((r) => r.alerta_consumo).length;
  const alerta = feed.find((a) => a.tipo === 'maquinaria' && a.maquina === m.nombre);
  const cuenta = !alerta ? 0 : /^Un registro/.test(alerta.mensaje) ? 1 : Number(alerta.mensaje.match(/^(\d+) registros/)?.[1]);
  if (cuenta !== marcados) discrepan.push(`${m.nombre}: listado ${marcados}, feed ${cuenta}`);
}
chequear('el feed cuenta los mismos registros anomalos que el listado de cada maquina', discrepan.length === 0, discrepan.join('; '));

// Las alertas de avance usan la gravedad de la API: alta desde 15 puntos de desvio.
const analisis = (await api('GET', '/analisis')).datos;
discrepan = [];
for (const p of analisis.proyectos.filter((x) => x.alerta_avance)) {
  const alerta = analisis.alertas.find((a) => a.tipo === 'avance' && a.proyecto === p.nombre);
  const esperada = p.avance_esperado - p.avance_real >= 15 ? 'alta' : 'media';
  if (alerta?.gravedad !== esperada) discrepan.push(`${p.nombre}: ${alerta?.gravedad} en vez de ${esperada}`);
}
chequear('la gravedad de las alertas de avance es la de la API', discrepan.length === 0, discrepan.join('; '));

await page.goto(BASE + '#/alertas', { waitUntil: 'networkidle' });
await page.waitForTimeout(1500);
const filaMaquina = page.getByText('Excavadora de contrato');
chequear('la pantalla de alertas muestra la maquina y su obra',
  (await filaMaquina.count()) === 1 && (await filaMaquina.innerText()).includes(obra1),
  (await filaMaquina.count()) ? await filaMaquina.innerText() : 'no aparece');

// ---- 12. Una incidencia avisa por correo segun su gravedad (D-02) ----
// Protocolo de la API: alta a Gerentes y Personal Administrativo activos,
// media solo al Personal Administrativo, baja a nadie. El simulador no manda
// correos: responde a cuantos habria avisado.
const cuentas = (await api('GET', '/usuarios')).datos.filter((u) => u.activo !== false);
const cuantos = (...roles) => cuentas.filter((u) => roles.includes(u.rol)).length;
const esperados = { alta: cuantos('Gerente', 'PersonalAdministrativo'), media: cuantos('PersonalAdministrativo'), baja: 0 };
for (const gravedad of ['alta', 'media', 'baja']) {
  r = await api('POST', '/proyectos/1/incidencias', { fecha: '2026-03-02', tipo: 'clima', gravedad, descripcion: `Prueba ${gravedad}` });
  chequear(`una incidencia ${gravedad} avisa a ${esperados[gravedad]}`,
    r.estado === 201 && r.datos?.avisados === esperados[gravedad], `dio ${r.estado} y ${r.datos?.avisados}`);
}

// En pantalla, quien la carga ve a cuantos se aviso. La gravedad por defecto es media.
await page.goto(BASE + '#/proyectos/1', { waitUntil: 'networkidle' });
await page.waitForTimeout(1500);
await page.fill('#desc', 'Se corta la luz en la obra');
await page.locator('form').filter({ has: page.locator('#desc') }).getByRole('button', { name: /Registrar/ }).click();
await page.waitForTimeout(800);
chequear('al registrarla, la pantalla dice a cuantos se aviso por correo',
  (await page.getByText(`Se avisó por correo a ${esperados.media} persona`).count()) === 1);

// ---- 13. Una obra nueva arranca creada y pasa a planificacion con su planificacion (D-04) ----
const nueva = await api('POST', '/proyectos', {
  nombre: 'Obra de contrato D-04', tipo: 'Vivienda', ubicacion: 'Posadas', encargado: 'Ing. Prueba',
  fechaInicio: '2099-01-01', presupuesto: 1000000,
});
const idNueva = nueva.datos?.id;
chequear('una obra nueva arranca creada', nueva.estado === 201 && nueva.datos?.estado === 'creada', `dio ${nueva.estado} y ${nueva.datos?.estado}`);

await page.goto(BASE + '#/proyectos', { waitUntil: 'networkidle' });
await page.waitForTimeout(1200);
await page.getByRole('button', { name: 'Creadas', exact: true }).click();
await page.waitForTimeout(500);
chequear('el filtro de obras creadas la muestra',
  (await page.getByText('Obra de contrato D-04', { exact: true }).count()) === 1);

r = await api('POST', `/proyectos/${idNueva}/planificacion`, { fecha_carga: '2026-03-01' });
chequear('cargar su planificacion la pasa a planificacion',
  r.estado === 201 && await estadoObra(idNueva) === 'planificacion', `dio ${r.estado} y ${await estadoObra(idNueva)}`);

// ---- 14. La certificacion la calcula la API, al centavo (D-09) ----
// Antes la calculaba la pantalla, redondeada al peso. Es un importe: al
// Personal Tecnico (mock.3) no le llega (RF20).
const obraUno = (await api('GET', '/proyectos/1')).datos;
const esperado = Math.round(obraUno.presupuesto * obraUno.avance) / 100;
chequear('el detalle de la obra trae el monto certificado al centavo',
  obraUno.certificado === esperado, `dio ${obraUno.certificado}, esperaba ${esperado}`);
const comoTecnico = (await api('GET', '/proyectos/1', null, 'mock.3')).datos;
chequear('al Personal Tecnico no le llega la certificacion',
  comoTecnico && !('certificado' in comoTecnico) && !('presupuesto' in comoTecnico), JSON.stringify(Object.keys(comoTecnico ?? {})));

await page.goto(BASE + '#/proyectos/1', { waitUntil: 'networkidle' });
await page.waitForTimeout(1500);
const textoCertificado = esperado.toLocaleString('es-AR', { maximumFractionDigits: 2 });
chequear('la pantalla muestra el monto que dio la API',
  (await page.getByText(`Certificación a la fecha: $${textoCertificado}`).count()) === 1, textoCertificado);

await nav.close();
console.log(`\n${ok.length}/${ok.length + mal.length} OK`);
if (mal.length) process.exit(1);
