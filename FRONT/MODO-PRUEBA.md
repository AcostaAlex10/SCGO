# Modo de prueba estático

Permite ejecutar el frontend **sin backend y sin base de datos**: las peticiones
se resuelven contra un servidor simulado en memoria, con datos ficticios. Sirve
para publicar la aplicación como sitio estático (GitHub Pages) y probar la
interfaz sin depender de Render ni de Aiven, y sin riesgo de alterar los datos
de la demo.

## Qué prueba y qué no

Prueba la **interfaz**: navegación, formularios, validaciones del lado del
cliente, estados de carga, filtros, cálculos que muestra la UI y el
comportamiento según el rol.

No prueba el sistema real: no se ejecuta PHP, ni SQL, ni la autorización del
servidor. Es un doble de prueba de la interfaz, no una prueba de integración ni
de extremo a extremo. Para eso hay que apuntar a un backend real.

## Cómo se activa

Con la variable de entorno `VITE_MOCK=1` en tiempo de compilación:

```bash
cd FRONT
VITE_MOCK=1 npm run dev      # desarrollo
VITE_MOCK=1 npm run build    # sitio estático en dist/
```

Sin esa variable, la aplicación se comporta igual que siempre y consume
`VITE_API_URL`. El servidor simulado se carga con un import dinámico, así que
**no entra en el bundle principal**: queda en un chunk aparte que el navegador
solo descarga cuando el modo está activo.

Ese chunk igual se genera al compilar, con los datos adentro, y queda publicado
junto al resto del sitio: no se ejecuta con el modo apagado, pero es accesible
por su URL. Por eso en `datos.json` no van hashes de contraseña, tokens ni
correos personales.

## Cuentas de prueba

Son ficticias y solo existen dentro del modo de prueba. No corresponden a
ninguna cuenta real del sistema.

| Rol | Email | Contraseña |
|---|---|---|
| AdministradorSistema | `admin@sgso.test` | `admin123` |
| PersonalAdministrativo | `administrativo@sgso.test` | `admin123` |
| PersonalTecnico | `tecnico@sgso.test` | `tecnico123` |
| Gerente | `gerente@sgso.test` | `gerente123` |

Iniciar sesión con cada una permite verificar RF19 y RF20: el simulador
reproduce los mismos grupos de roles que `back/public/index.php` y oculta el
presupuesto al `PersonalTecnico`.

## Datos

Los datos base están en `src/app/mock/datos.json`. Toman como punto de partida
la información real de la base de producción —las cuatro obras con sus
planificaciones, avances, materiales, maquinaria y reportes— y se completan con
registros de prueba en lo que la base tenía vacío: asistencias, incidencias,
documentos, períodos de inactividad e ítems excedentes. Se agregan además un
cronograma completo para la Obra Vial Ruta 14, un consumo que excede lo asignado
(RF12), reportes en los cuatro estados, los roles que la base no tiene y una
cuenta dada de baja. Así queda cubierta cada pantalla del sistema.

Los cambios que hagas (crear una obra, registrar un avance) se guardan en
`localStorage`, así que sobreviven a una recarga. Para volver al punto de
partida, ejecutar en la consola del navegador:

```js
sgsoMockReset()
```

### Publicar datos nuevos

`localStorage` es por origen, no por versión del sitio: redesplegar no limpia
nada. Sin más, quien ya hubiera entrado seguiría viendo su copia vieja de los
datos, y si además cambió la forma de un registro esa copia queda inconsistente
con el código nuevo.

Por eso la clave de almacenamiento lleva un sufijo derivado de `datos.json`
(`sgso_mock_db_<huella>`). Al publicar datos distintos la clave cambia sola, la
copia guardada deja de encontrarse y cada persona arranca desde los datos
nuevos; las copias anteriores se borran al cargar. No hay que acordarse de
versionar nada a mano ni pedirle a nadie que limpie el navegador.

## Cómo se implementa

- `src/app/mock/datos.json` — datos ficticios.
- `src/app/mock/servidor.ts` — reproduce el contrato de la API PHP: rutas,
  códigos de estado, validaciones (422 por campo, 409 por duplicado), roles y
  los cálculos derivados (resumen de avance, análisis y alertas, totales de
  maquinaria, rendimiento por operario, materiales excedidos).
- `src/app/auth/api.ts` — todas las peticiones pasan por `transporte()`, que
  elige entre el backend real y el simulador. Ningún componente cambia.

Si agregás un endpoint al backend PHP, agregá su equivalente en `servidor.ts` o
el modo de prueba responderá 404 en esa ruta.

## Publicación del sitio de prueba

### Un solo archivo .html

La demo también se puede empaquetar en un único `.html` con el CSS y el JS
incrustados, para compartirla como archivo suelto o subirla a un visor que no
sirve carpetas:

```bash
VITE_MOCK=1 VITE_HASH_ROUTER=1 ARCHIVO_UNICO=1 npm run build
node scripts/demo-un-archivo.mjs   # deja dist/demo.html (~1 MB)
```

Las tres variables son necesarias y solo actúan en este build:

| Variable | Para qué |
|---|---|
| `VITE_MOCK=1` | resuelve las peticiones contra el simulador, sin backend |
| `VITE_HASH_ROUTER=1` | las rutas viajan en el fragmento (`#/proyectos`), así funcionan sin un servidor que devuelva `index.html` en cada ruta |
| `ARCHIVO_UNICO=1` | desactiva la división en chunks, para que todo el JS entre en un bundle |

Sin ellas, `npm run build` produce exactamente el mismo resultado de siempre.

> Al incrustar el bundle hay que pasarlo como **función** de reemplazo, no como
> cadena: el código minificado contiene secuencias como `$&`, que `replace()`
> interpreta como referencias a la coincidencia y sustituye en silencio,
> corrompiendo el JS. El script ya lo hace.

### Sitio portable para GitHub Pages

Compilado así, el sitio funciona en **cualquier subdirectorio** sin conocer de
antemano el nombre del repositorio, y sin necesidad de `404.html`:

```bash
cd FRONT
BASE_PATH=./ VITE_MOCK=1 VITE_HASH_ROUTER=1 npm run build
```

| Variable | Para qué |
|---|---|
| `BASE_PATH=./` | rutas relativas a los assets, así el sitio no depende de la ruta donde se publique |
| `VITE_HASH_ROUTER=1` | las rutas viajan en el fragmento, así recargar en `#/proyectos` no da 404 |
| `VITE_MOCK=1` | resuelve las peticiones contra el simulador |

El contenido de `dist/` se puede subir tal cual a la raíz de un repositorio
público con Pages habilitado (Source: rama `main`, carpeta `/`).

Verificado sirviendo `dist/` desde un subdirectorio: carga, login, navegación y
recarga profunda en `#/proyectos` sin errores.

### GitHub Pages (en uso)

El workflow `.github/workflows/pages-testing.yml` publica la demo en cada push a
**`testing`**, que es la rama del sitio estático. Compila con las tres variables
de arriba y el resultado queda en

`https://acostaalex10.github.io/SCGO/`

**`main` no dispara este workflow.** Lleva la aplicación real, la que consume la
API PHP. Pages sirve un único sitio por repositorio, así que si las dos ramas
publicaran se pisarían entre sí.

Eso incluye dispararlo **a mano** desde la pestaña Actions eligiendo `main`: el
workflow corre igual y publica la versión de `main` encima de la de los testers.
Pasó el 2026-09-23. El entorno `github-pages` tiene que admitir solo `testing`.

Requiere, una sola vez, **Settings → Pages → Source: "GitHub Actions"**. Ya está
hecho; sin eso el despliegue falla con un 404 aunque la compilación pase.

### Vercel

Despliega la aplicación real, sin `VITE_MOCK` y contra la API PHP de Render. Es
un circuito aparte del de Pages y no comparte configuración con él.

Si alguna vez hiciera falta publicar la demo también en Vercel —por ejemplo con
el repositorio en privado, donde Pages no está disponible— alcanza con agregar
`VITE_MOCK` con valor `1` en **Settings → Environment Variables** marcando
únicamente el entorno **Preview**, y redesplegar la rama para que tome la
variable. No marcar Production: eso convertiría el sitio real en datos
ficticios.

### Sin publicar nada

Para probar en la máquina, sin hosting ni backend:

```bash
cd FRONT
VITE_MOCK=1 npm run dev
```
