# Entrevistas pendientes del relevamiento

El plan de entrevistas del relevamiento preveía tres: dirección técnica,
administración y un supervisor de campo. Solo se hizo la primera (TP1). Esta
guía cubre las dos que faltan y las confirmaciones que quedaron abiertas con la
dirección técnica ([REQUERIMIENTOS.md](../REQUERIMIENTOS.md) §3).

**Formato del plan original:** introducción (2 min), procesos y desafíos
(15-20 min), visión (5 min), aspectos técnicos (5 min) y cierre (5 min).
Preguntas abiertas, de lo general a lo puntual. Lo de abajo es el cuerpo de cada
entrevista; la introducción y el cierre se usan como en el plan ("¿Hay algo que
no preguntamos?", "¿A quién más conviene entrevistar?", próximos pasos).

**No repetir** lo que ya contó la dirección técnica en el TP1: herramientas que
usan, dónde se guarda la información, comunicación entre oficina y obra, el error
más común y qué tendría que hacer el sistema.

**Sin datos personales en el repositorio:** acá van los roles, no los nombres, y
las respuestas se resumen sin datos de Triwe (el repositorio es público).

Entre paréntesis, el requerimiento o ítem del plan que destraba cada pregunta.

---

## 1. Administración

**Objetivo:** entender cómo se registran hoy los gastos, los pedidos y las
cotizaciones, y dónde termina lo que hace el sistema contable.
**Duración:** 35 a 40 minutos.

1. Contame un gasto de una obra desde que se decide hasta que queda registrado en
   contabilidad. ¿Quién interviene y en qué momento? (RF29)
2. Cuando se compra o se paga algo antes de tener la factura, ¿cómo se anota hoy
   y dónde? ¿Qué pasa cuando después llega el comprobante? (RF29)
3. ¿Qué gastos de una obra tienen que contarse para saber "cuánto se gastó":
   materiales, horas, combustible, repuestos, otros? ¿Hay alguno que no? (RF29,
   RF13)
4. ¿Cómo se enteran en administración de que hace falta un material? ¿Qué datos
   del pedido necesitan para comprar y registrar bien? (RF30)
5. En el TP1 se contó que una compra fuera de protocolo puede hacer perder la
   retención. ¿Qué datos o pasos tienen que estar para que la compra quede bien
   hecha? (RF30)
6. ¿Cómo piden hoy presupuestos a los proveedores y cómo los comparan? ¿Dónde
   guardan lo que responden? (RF31)
7. ¿En qué formato reciben las ofertas: PDF, foto, mensaje, correo, planilla,
   lista de precios? ¿Nos pueden mostrar dos o tres ejemplos, sin datos
   sensibles? (RF31, RF34)
8. Si una oferta viniera en una planilla o un CSV, ¿qué columnas trae? ¿Cambian
   las unidades o los nombres de un proveedor a otro? ¿Cuánto dura una oferta?
   (RF34, RF31)
9. ¿Qué resuelve hoy el sistema contable con pagos, facturación y retenciones, y
   qué queda afuera (planillas, papel, a mano)? *Es para entender el límite: SCGO
   no lo va a reemplazar.* (§3, exclusión contable)
10. ¿Cómo controlan hoy las horas de entrada y salida que se pagan? ¿Quién las
    revisa antes de pagar? (RF32)

## 2. Supervisor de campo

**Objetivo:** ver cómo es la carga diaria en obra, con qué dispositivo y qué
conexión, para que entre en menos de 10 minutos.
**Duración:** 30 a 35 minutos.

1. Contame un día típico en la obra, desde que llegás hasta que informás a la
   oficina. ¿Qué anotás y cuándo? (RNF02)
2. ¿Con qué dispositivo y qué aplicaciones registrás y mandás información hoy?
   ¿Qué pasa cuando tenés que escribir mucho desde el celular? (RNF01, RNF09)
3. ¿Hay señal o datos en la obra? Cuando no hay, ¿cómo lo resolvés y cuánto
   tarda en llegar la información? (conectividad, RNF01, RNF10)
4. ¿Cuánto te lleva hoy el reporte del día? ¿Qué parte sobra o se repite? ¿Cuánto
   te parecería razonable? (RNF02)
5. ¿Cómo se pide hoy un material desde la obra y cómo te enterás de que se compró
   o llegó? ¿Qué se pierde en el camino? (RF30)
6. ¿Cómo se toma la hora de entrada y salida del personal y de los maquinistas?
   ¿Quién anota, quién controla y cómo se corrigen los errores? (RF32)
7. ¿Qué te queda a medias en el día? ¿Cómo te acordás hoy, y cómo te gustaría que
   te avisen? (RF33)
8. De lo que cargás, ¿qué te resulta más cómodo elegir de una lista y qué sí o sí
   hay que escribir? (RNF09)
9. ¿Quién más carga datos en la obra, y qué tan cómodo está con el celular o la
   computadora? (RNF01, RNF02)

## 3. Confirmaciones con la dirección técnica

**Objetivo:** validar las reglas de alerta de costos y decidir qué se construye.
**Duración:** 20 a 25 minutos. Conviene llevar un ejemplo con números, de una
obra real o inventada, con el resultado que daría hoy SCGO.

1. ¿Cómo se mide el avance de una obra: por etapas terminadas, por porcentaje
   estimado, por cantidad de trabajo? ¿Quién lo define? (reglas de alerta, RF13)
2. ¿Qué costos entran en "gastado" para compararlo con el avance? ¿Entran las
   compras anticipadas de material que todavía no se usó? (reglas de alerta,
   RF29)
3. ¿Cada cuánto conviene mirar la comparación (semana, mes, etapa) y a partir de
   qué diferencia tiene que saltar una alerta? ¿Una de "atención" y otra de
   "grave"? (reglas de alerta, RF12, RF13)
4. Con el ejemplo en la mano: ¿esta alerta les parece correcta o una falsa
   alarma? (reglas de alerta; los ejemplos quedan como casos de prueba)
5. De los circuitos propuestos (gastos reales, pedidos de material, cotizaciones,
   horas, pendientes e importar ofertas), ¿cuáles quieren, en qué orden, y
   cuáles no? (DEC-08: RF29 a RF34)
6. Para las ofertas, ¿les sirve cargarlas a mano o importarlas de una planilla,
   sabiendo que la captura automática de precios queda afuera? (DEC-08, RF31,
   RF34)
7. En el diagrama de contexto figura la consulta del estado de un lote a la
   municipalidad. ¿La necesitan? ¿Cómo la hacen hoy y cada cuánto? (D-10)
8. ¿Quién de Triwe puede aceptar un cambio de alcance? El estudio de viabilidad
   pide confirmarlo antes de comprometer el desarrollo. (DEC-08)

---

## Qué se anota después

| Respuesta | Dónde va |
|---|---|
| Gastos, pedidos, cotizaciones y formato de las ofertas | RF29 a RF31 y RF34 en `REQUERIMIENTOS.md` §5; D-17 a D-19 y D-23 en `PLAN-PRODUCTO.md` |
| El límite con el sistema contable | `REQUERIMIENTOS.md` §3 |
| Horas y pendientes | RF32 y RF33; D-20 y D-21 |
| Uso en obra, tiempos y conectividad | RNF01, RNF02, RNF09 y RNF10 en `REQUERIMIENTOS.md` §6; D-07 en el plan |
| Reglas de alerta (medición, costos, períodos, umbrales) | RF12 y RF13 dejan de decir "reglas a validar"; los ejemplos pasan a pruebas |
| Qué circuitos quiere Triwe y quién acepta cambios | DEC-08 en `PLAN-PRODUCTO.md`, que se cierra |
| La consulta de lotes | D-10: se descarta o se convierte en un RF |

Cada cambio va por PR, como cualquier otro, con la fecha de la entrevista.
