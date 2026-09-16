# Ramas del repositorio

Registro de qué es cada rama y para qué existe. **Se actualiza al crear una
rama, no después**: una rama sin entrada acá es una rama que nadie va a saber
para qué estaba dentro de dos semanas.

---

## Ramas permanentes

| Rama | Qué es | Se despliega | Quién la toca |
|---|---|---|---|
| `main` | La rama de trabajo. Todo el código vive acá. | **Sí**: backend en Render, frontend en Vercel | Todo el equipo, vía pull request |
| `testing` | **Congelada** en `a25da85`. Demo estática que usan los testers, publicada por `.github/workflows/pages-testing.yml`. | GitHub Pages | **Nadie.** No se mergea, no se rebasea, no se actualiza |

> `testing` no se toca. Es lo que ven los testers y quedó congelada a propósito;
> si alguna vez hay que actualizarla, es una decisión aparte y explícita.

---

## Ramas de trabajo abiertas

| Rama | Desde | Para qué | PR |
|---|---|---|---|
| `claude/adr-001-fase-2b-5` | `main` @ `67b479a` (2026-09-12) | Fases 2b y 5 del [plan de ADR-001](docs/adr/PLAN-ADR-001.md): pruebas de integración sobre MariaDB y CI en GitHub Actions. | [#7](https://github.com/AcostaAlex10/ingenieria-en-software-proyecto/pull/7) |

---

## Cómo se trabaja

1. **Salir de `main` actualizado**: `git checkout main && git pull` antes de
   crear la rama. No hacerlo fue lo que causó el enredo de septiembre (ver
   abajo).
2. Una rama por tarea acotada, con su fila en la tabla de arriba apenas se crea.
3. Pull request contra `main`. Nada de ramas apiladas una sobre otra salvo que
   haya un motivo fuerte: si se borra la rama base, GitHub **cierra** el PR
   apilado en vez de reapuntarlo.
4. Al mergear: borrar la rama y sacarla de la tabla.

---

## Historial de ramas ya integradas

Se dejan anotadas porque el trabajo sigue en `main` y porque sus PR son la
explicación de por qué el código quedó como quedó.

| Rama | Qué traía | Cerró en |
|---|---|---|
| `chore/skill-codex` | La skill `codex-programador` (delegar código al Codex CLI). | PR #3 |
| `claude/ingenieria-software-nube-2ws8jg` | ADR-001, su plan de ejecución, el borrado de `back-node/` y la Fase 1 (Composer + PSR-4). | PR #4 |
| `claude/adr-001-fase-2a-3` | Fases 2a y 3: `Sgso\Reglas` (ciclo de vida y permisos) y PHPStan nivel 5. | Entró por PR #6 |
| `claude/adr-001-fase-4` | Fase 4: tabla de rutas declarativa (`Sgso\Ruteo`). | PR #6 |

### Dos cosas que pasaron y conviene no repetir

- **La skill de Codex parecía no existir.** Estaba en `main` desde el PR #3,
  pero las ramas de las fases salieron de `7acc87c`, que es anterior a ese
  merge. En esas ramas el archivo no estaba, y hubo que ir a buscarlo al
  historial. Se arregla saliendo siempre de `main` actualizado.
- **El PR #5 quedó cerrado sin mergear.** Estaba apilado sobre la rama de la
  Fase 1; al borrarse esa rama, GitHub lo cerró automáticamente. Su contenido
  entró igual porque la rama de la Fase 4 lo incluía, pero el PR quedó como
  cerrado en el historial.
