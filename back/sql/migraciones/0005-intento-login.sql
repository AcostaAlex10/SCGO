-- Logins fallidos por cuenta, para limitar los intentos (A-03).
--
-- La tabla ya existe en schema.sql desde el PR #11, pero hasta ahora no había
-- forma de aplicarla a una base viva, así que `Sgso\Seguridad\IntentosLogin` la
-- creaba en tiempo de ejecución en cada arranque. Con esta migración esa
-- creación sale del código: la tabla se crea acá, una vez y registrada.
--
-- Es idempotente a propósito: sobre una base que ya la tiene (producción, o
-- cualquiera creada desde schema.sql) no cambia nada.
CREATE TABLE IF NOT EXISTS intento_login (
  clave         VARCHAR(191) NOT NULL PRIMARY KEY,
  fallos        INT UNSIGNED NOT NULL,
  ultimo_fallo  INT UNSIGNED NOT NULL
);
