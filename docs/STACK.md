# Stack tecnológico de SCGO

Qué usa el sistema hoy, con las versiones de los lockfiles. Actualizado el
2026-09-26, después de C-11.

## Frontend — `FRONT/`, en Vercel

| Qué | Tecnología |
|---|---|
| Base | React 18.3.1 y TypeScript 7.0.2 (TypeScript solo verifica tipos: no compila) |
| Build | Vite 6.4.3 con `@vitejs/plugin-react` 4.7, sobre Node 24 |
| Rutas | React Router 7.18.4: rutas normales en Vercel, con `#` en la demo |
| Estilos | Tailwind CSS 4.3.3 y `tw-animate-css` |
| Componentes | shadcn/ui (11 primitivos en `src/app/components/ui/`) sobre Radix UI: `checkbox`, `dialog`, `label`, `select` y `slot`; más `class-variance-authority`, `clsx` y `tailwind-merge` |
| Íconos | `lucide-react` 0.487 |
| Gráficos | recharts 3.10.1, con `react-is` 18.3.1 |
| Avisos | sonner 2.0.8 |
| Demo sin backend | simulador propio en `src/app/mock/`, activado con `VITE_MOCK=1` |

Son 16 dependencias, y se usan todas. **`react-is` no lo importa ningún
archivo, pero no se borra:** lo necesita recharts, y sin él el build falla. Su
versión tiene que ser la misma que la de `react`.

## Backend — `back/`, en Render

| Qué | Tecnología |
|---|---|
| Lenguaje | PHP 8.3, sin framework, en Docker (`php:8.3-apache`) |
| Base de datos | PDO con `pdo_mysql` |
| Dependencias de runtime | ninguna, a propósito (ADR-001): Composer solo genera el autoload PSR-4 |
| Hecho en casa | ruteo declarativo (`Sgso\Ruteo`), reglas de negocio puras (`Sgso\Reglas`), JWT HS256, contraseñas con bcrypt, envío de errores a Sentry sin SDK |
| Servicios externos | Brevo (mails de recuperación), Nominatim/OpenStreetMap (validar ubicaciones), Sentry (errores) |

## Datos

MariaDB/MySQL gestionada en **Aiven**, plan gratuito, con SSL.

## Entornos

| Entorno | Dónde | Sale de |
|---|---|---|
| Sistema real | front en Vercel y API en Render (plan gratuito) | `main` |
| Demo de testers | GitHub Pages, sin backend | `testing`, con el código anterior al renombre |

## Calidad

| Qué | Herramienta |
|---|---|
| CI | GitHub Actions, con 4 chequeos obligatorios para mergear a `main` |
| Backend | PHPStan 2.2.14 (nivel 5) y PHPUnit 11.5.56, con pruebas de integración contra MariaDB 11 |
| Frontend | chequeo de tipos, build, `npm audit` y los headers de `vercel.json` |
| De punta a punta | Playwright: 5 suites sobre la demo |
| Imagen | build de Docker, más un arranque sin secreto y otro con la base caída |
| Dependencias | Dependabot: npm y Composer cada semana, actions cada mes |

## Monitoreo

Sentry para los errores y UptimeRobot para la disponibilidad. Al 2026-09-26 el
código está listo, pero falta configurar las dos cuentas: ver
[OPERACION.md](OPERACION.md) §4 y §5.
