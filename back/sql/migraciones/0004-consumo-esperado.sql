-- maquinaria.consumo_esperado_por_hora: litros por hora que se esperan de cada
-- máquina (D-13, RF24). Con ese dato, la alerta de consumo compara contra lo
-- esperado y no contra el promedio de la propia máquina.
--
-- Hasta B-03 esto lo hacía `back/sql/migracion-consumo-esperado.php`, un script
-- suelto. En producción ya se corrió (2026-11-05), así que acá es un no-op: lo
-- que cambia es que ahora queda registrado en `schema_migrations`.
--
-- Tiene que ser idempotente, y `ADD COLUMN IF NOT EXISTS` no sirve: lo entiende
-- MariaDB (el CI) pero no MySQL 8.4 (Aiven). Por eso se mira primero
-- `information_schema` y la sentencia se arma según haga falta.
--
-- No modifica ninguna máquina existente: todas quedan con la columna en NULL
-- ("sin dato") y siguen alertando contra su promedio hasta que Gestión de obra
-- cargue el consumo esperado.
SET @falta_consumo_esperado = (
  SELECT COUNT(*) = 0 FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'maquinaria'
     AND column_name = 'consumo_esperado_por_hora'
);
SET @sentencia = IF(
  @falta_consumo_esperado,
  'ALTER TABLE maquinaria ADD COLUMN consumo_esperado_por_hora DECIMAL(8,2) NULL DEFAULT NULL AFTER activa',
  'DO 0'
);
PREPARE aplicar FROM @sentencia;
EXECUTE aplicar;
DEALLOCATE PREPARE aplicar;
