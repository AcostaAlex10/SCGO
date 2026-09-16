-- Base de datos: sgso  (MariaDB / MySQL)
-- Esquema del backend PHP: autenticacion (usuario) + proyectos (RF01).
--
-- Crear la base antes de ejecutar:
--   CREATE DATABASE sgso CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- Luego ejecutar este script sobre la base "sgso" (DBeaver o linea de comandos).

-- Tabla de usuarios para el login. La columna `contrasena` guarda el
-- HASH bcrypt, nunca texto plano. El `rol` deja preparada RF19.
CREATE TABLE IF NOT EXISTS usuario (
  id_usuario     INT AUTO_INCREMENT PRIMARY KEY,
  nombre         VARCHAR(100) NOT NULL,
  email          VARCHAR(150) NOT NULL UNIQUE,
  contrasena     VARCHAR(255) NOT NULL,
  rol            ENUM('PersonalAdministrativo','PersonalTecnico','Gerente','AdministradorSistema') NOT NULL,
  activo         TINYINT(1) NOT NULL DEFAULT 1,
  -- Identificador de la sesion activa: cada login lo regenera, invalidando la
  -- sesion anterior (un usuario no puede estar logueado en dos lados a la vez).
  sesion_token   VARCHAR(64) NULL,
  -- Recuperacion de contrasena: token enviado por email y su vencimiento.
  reset_token    VARCHAR(64) NULL,
  reset_expira   DATETIME NULL,
  fecha_creacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Logins fallidos por cuenta, para limitar los intentos (A-03). La clave es
-- 'cuenta:' + SHA-256 del email en minusculas: vale igual para emails sin
-- cuenta y no guarda en claro los que alguien probo. Los tiempos son
-- segundos Unix, para no depender de la zona horaria de la base.
CREATE TABLE IF NOT EXISTS intento_login (
  clave         VARCHAR(191) NOT NULL PRIMARY KEY,
  fallos        INT UNSIGNED NOT NULL,
  ultimo_fallo  INT UNSIGNED NOT NULL
);

-- Tabla de proyectos de obra (RF01: registrar, modificar, eliminar, listar).
-- Los campos coinciden con el contrato que consume el frontend.
CREATE TABLE IF NOT EXISTS proyecto (
  id_proyecto  INT AUTO_INCREMENT PRIMARY KEY,
  nombre       VARCHAR(150) NOT NULL,
  tipo         VARCHAR(100) NOT NULL,
  ubicacion    VARCHAR(255) NOT NULL,
  encargado    VARCHAR(150) NOT NULL,
  fecha_inicio DATE NOT NULL,
  -- Los siete estados del ciclo de vida del TP3. Cuatro todavia no los asigna
  -- ningun controlador (ver docs/REQUERIMIENTOS.md); el ENUM esta para que la base no
  -- acepte un estado inventado mientras se cierran esos huecos.
  estado       ENUM('creada','planificacion','en_ejecucion','pausada',
                    'en_revision','finalizada','cancelada')
               NOT NULL DEFAULT 'planificacion',
  avance       DECIMAL(5,2) NOT NULL DEFAULT 0,
  presupuesto  DECIMAL(15,2) NOT NULL
);

-- Planificacion de cada obra (una por proyecto): avance esperado total.
-- id_proyecto es UNIQUE -> relacion 1 a 1 con proyecto.
-- avance_esperado_total ahora es FALLBACK: si la obra tiene etapas se calcula
-- dinamicamente; solo se usa cuando no hay etapas cargadas.
CREATE TABLE IF NOT EXISTS planificacion (
  id_planificacion      INT AUTO_INCREMENT PRIMARY KEY,
  avance_esperado_total DECIMAL(5,2) NOT NULL DEFAULT 0,
  fecha_carga           DATE NOT NULL,
  id_proyecto           INT NOT NULL UNIQUE,
  CONSTRAINT fk_planificacion_proyecto FOREIGN KEY (id_proyecto)
    REFERENCES proyecto(id_proyecto) ON DELETE CASCADE
);

-- Etapas de una planificacion (1-a-muchos). Cada etapa aporta peso_porcentual
-- al avance total. La fraccion de progreso temporal de cada etapa (0-1 segun
-- fecha_inicio/fecha_fin respecto a hoy) se usa para calcular el esperado a
-- la fecha, reemplazando el avance_esperado_total estatico.
-- La suma de peso_porcentual de todas las etapas de una plan debe ser 100.
CREATE TABLE IF NOT EXISTS etapa_planificacion (
  id_etapa          INT AUTO_INCREMENT PRIMARY KEY,
  id_planificacion  INT NOT NULL,
  nombre            VARCHAR(150) NOT NULL,
  peso_porcentual   DECIMAL(5,2) NOT NULL,
  fecha_inicio      DATE NOT NULL,
  fecha_fin         DATE NOT NULL,
  orden             INT NOT NULL DEFAULT 0,
  presupuesto_base  DECIMAL(15,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_etapa_plan FOREIGN KEY (id_planificacion)
    REFERENCES planificacion(id_planificacion) ON DELETE CASCADE
);
-- Para bases existentes (ya creadas): correr una sola vez:
--   ALTER TABLE planificacion ALTER avance_esperado_total SET DEFAULT 0;
--   ALTER TABLE etapa_planificacion ADD COLUMN presupuesto_base DECIMAL(15,2) NOT NULL DEFAULT 0;

-- Avances fisicos diarios cargados contra una planificacion.
CREATE TABLE IF NOT EXISTS avance_fisico (
  id_avance          INT AUTO_INCREMENT PRIMARY KEY,
  cantidad_ejecutada DECIMAL(12,2) NOT NULL,
  porcentaje_avance  DECIMAL(5,2) NOT NULL,
  fecha              DATE NOT NULL,
  observaciones      TEXT,
  id_planificacion   INT NOT NULL,
  CONSTRAINT fk_avance_planificacion FOREIGN KEY (id_planificacion)
    REFERENCES planificacion(id_planificacion) ON DELETE CASCADE
);

-- ============================================================
--  Sprint 2 - Seguimiento Operativo
-- ============================================================

-- Asistencia diaria del personal en obra (RF06). Una fila por trabajador
-- y por jornada. `justificacion` permite registrar el motivo de una
-- inasistencia o llegada tarde (RF08).
CREATE TABLE IF NOT EXISTS asistencia (
  id_asistencia INT AUTO_INCREMENT PRIMARY KEY,
  id_proyecto   INT NOT NULL,
  fecha         DATE NOT NULL,
  trabajador    VARCHAR(150) NOT NULL,
  estado        ENUM('presente','ausente','tarde') NOT NULL DEFAULT 'presente',
  justificacion VARCHAR(255) NULL,
  CONSTRAINT fk_asistencia_proyecto FOREIGN KEY (id_proyecto)
    REFERENCES proyecto(id_proyecto) ON DELETE CASCADE
);

-- Incidencias externas que pueden justificar retrasos o extensiones de
-- plazo (RF09): clima, fallas de maquinaria, retrasos de proveedores, etc.
-- `gravedad` clasifica la incidencia (RF26) y `dias_retraso` documenta el
-- impacto en el cronograma (RF08).
CREATE TABLE IF NOT EXISTS incidencia (
  id_incidencia INT AUTO_INCREMENT PRIMARY KEY,
  id_proyecto   INT NOT NULL,
  fecha         DATE NOT NULL,
  tipo          ENUM('clima','falla_maquinaria','proveedor','otro') NOT NULL DEFAULT 'otro',
  gravedad      ENUM('baja','media','alta') NOT NULL DEFAULT 'media',
  descripcion   TEXT NOT NULL,
  dias_retraso  INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_incidencia_proyecto FOREIGN KEY (id_proyecto)
    REFERENCES proyecto(id_proyecto) ON DELETE CASCADE
);

-- ============================================================
--  Sprint 3 - Materiales y Documentacion
-- ============================================================

-- Catalogo global de materiales (RF04: listas precargadas). El nombre es
-- unico para poder precargar con INSERT IGNORE sin duplicar.
CREATE TABLE IF NOT EXISTS material (
  id_material INT AUTO_INCREMENT PRIMARY KEY,
  nombre      VARCHAR(120) NOT NULL UNIQUE,
  unidad      VARCHAR(30) NOT NULL
);

-- Material asignado a una obra (RF10): cuanto se preve usar. Un material
-- se asigna una sola vez por obra (los consumos se descuentan de aca).
CREATE TABLE IF NOT EXISTS asignacion_material (
  id_asignacion     INT AUTO_INCREMENT PRIMARY KEY,
  id_proyecto       INT NOT NULL,
  id_material       INT NOT NULL,
  cantidad_asignada DECIMAL(12,2) NOT NULL,
  CONSTRAINT fk_asig_proyecto FOREIGN KEY (id_proyecto) REFERENCES proyecto(id_proyecto) ON DELETE CASCADE,
  CONSTRAINT fk_asig_material FOREIGN KEY (id_material) REFERENCES material(id_material),
  CONSTRAINT uq_asig_proyecto_material UNIQUE (id_proyecto, id_material)
);

-- Consumos de material registrados en obra (RF10). La suma de consumos
-- vs. la cantidad asignada permite detectar excesos (RF12).
CREATE TABLE IF NOT EXISTS consumo_material (
  id_consumo         INT AUTO_INCREMENT PRIMARY KEY,
  id_asignacion      INT NOT NULL,
  fecha              DATE NOT NULL,
  cantidad_consumida DECIMAL(12,2) NOT NULL,
  observaciones      VARCHAR(255) NULL,
  CONSTRAINT fk_consumo_asig FOREIGN KEY (id_asignacion)
    REFERENCES asignacion_material(id_asignacion) ON DELETE CASCADE
);

-- Documentacion de la obra (RF16): se guarda el dato del documento y un
-- enlace (Drive/URL), no el binario.
CREATE TABLE IF NOT EXISTS documento (
  id_documento INT AUTO_INCREMENT PRIMARY KEY,
  id_proyecto  INT NOT NULL,
  nombre       VARCHAR(150) NOT NULL,
  tipo         ENUM('pdf','imagen','otro') NOT NULL DEFAULT 'otro',
  categoria    VARCHAR(60) NOT NULL DEFAULT 'General',
  url          VARCHAR(500) NOT NULL,
  fecha_carga  DATE NOT NULL,
  CONSTRAINT fk_documento_proyecto FOREIGN KEY (id_proyecto)
    REFERENCES proyecto(id_proyecto) ON DELETE CASCADE
);

-- Catalogo de materiales precargado (RF04). INSERT IGNORE => idempotente.
INSERT IGNORE INTO material (nombre, unidad) VALUES
  ('Cemento', 'bolsa'),
  ('Arena', 'm3'),
  ('Piedra triturada', 'm3'),
  ('Hierro del 8', 'kg'),
  ('Hierro del 10', 'kg'),
  ('Ladrillo comun', 'unidad'),
  ('Cal', 'bolsa'),
  ('Hormigon elaborado', 'm3'),
  ('Madera (encofrado)', 'm2'),
  ('Pintura latex', 'litro');

-- ============================================================
--  Sprint 4 - Reportes, validacion e inactividad
-- ============================================================

-- Reportes operativos de obra con flujo de aprobacion (RF21). El revisor
-- deja una observacion al aprobar/rechazar (RF17).
-- Ciclo: borrador -> en_revision -> aprobado | rechazado (-> en_revision).
CREATE TABLE IF NOT EXISTS reporte (
  id_reporte           INT AUTO_INCREMENT PRIMARY KEY,
  id_proyecto          INT NOT NULL,
  id_usuario           INT NOT NULL, -- autor del reporte
  titulo               VARCHAR(150) NOT NULL,
  contenido            TEXT NOT NULL,
  estado               ENUM('borrador','en_revision','aprobado','rechazado') NOT NULL DEFAULT 'borrador',
  -- Marca el reporte de cierre de la obra. Enviarlo lleva la obra a
  -- 'en_revision' y aprobarlo la finaliza: es la certificacion del TP3, la
  -- unica via por la que una obra queda terminada. Un parte diario comun
  -- lleva 0 y no mueve el estado de la obra.
  es_final             TINYINT(1) NOT NULL DEFAULT 0,
  observacion_revision VARCHAR(500) NULL,
  fecha_creacion       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fecha_revision       DATETIME NULL,
  CONSTRAINT fk_reporte_proyecto FOREIGN KEY (id_proyecto) REFERENCES proyecto(id_proyecto) ON DELETE CASCADE,
  CONSTRAINT fk_reporte_usuario FOREIGN KEY (id_usuario) REFERENCES usuario(id_usuario)
);

-- Periodos de inactividad de la obra con su motivo (RF25).
CREATE TABLE IF NOT EXISTS periodo_inactividad (
  id_periodo   INT AUTO_INCREMENT PRIMARY KEY,
  id_proyecto  INT NOT NULL,
  fecha_inicio DATE NOT NULL,
  fecha_fin    DATE NULL,
  motivo       VARCHAR(255) NOT NULL,
  CONSTRAINT fk_inactividad_proyecto FOREIGN KEY (id_proyecto)
    REFERENCES proyecto(id_proyecto) ON DELETE CASCADE
);

-- Items o trabajos excedentes no contemplados en la planificacion (RF22).
CREATE TABLE IF NOT EXISTS item_excedente (
  id_item     INT AUTO_INCREMENT PRIMARY KEY,
  id_proyecto INT NOT NULL,
  descripcion VARCHAR(255) NOT NULL,
  cantidad    DECIMAL(12,2) NULL,
  unidad      VARCHAR(30) NULL,
  fecha       DATE NOT NULL,
  motivo      VARCHAR(255) NULL,
  CONSTRAINT fk_item_proyecto FOREIGN KEY (id_proyecto)
    REFERENCES proyecto(id_proyecto) ON DELETE CASCADE
);

-- ============================================================
--  Sprint 6 (opcional) - Gestion de Maquinaria
-- ============================================================

-- Registro de equipos/maquinaria (catalogo).
CREATE TABLE IF NOT EXISTS maquinaria (
  id_maquinaria INT AUTO_INCREMENT PRIMARY KEY,
  nombre        VARCHAR(120) NOT NULL,
  tipo          VARCHAR(80) NOT NULL,
  activa        TINYINT(1) NOT NULL DEFAULT 1
);

-- Uso diario de cada maquina (RF23): horas, combustible, produccion y
-- operario (este ultimo permite comparar rendimiento entre operarios, RF28).
CREATE TABLE IF NOT EXISTS registro_maquinaria (
  id_registro           INT AUTO_INCREMENT PRIMARY KEY,
  id_maquinaria         INT NOT NULL,
  id_proyecto           INT NULL,
  fecha                 DATE NOT NULL,
  operario              VARCHAR(120) NULL,
  horas_uso             DECIMAL(8,2) NOT NULL DEFAULT 0,
  combustible_consumido DECIMAL(10,2) NOT NULL DEFAULT 0,
  produccion_realizada  DECIMAL(12,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_reg_maq FOREIGN KEY (id_maquinaria) REFERENCES maquinaria(id_maquinaria) ON DELETE CASCADE,
  CONSTRAINT fk_reg_proy FOREIGN KEY (id_proyecto) REFERENCES proyecto(id_proyecto) ON DELETE SET NULL
);

-- Historial de fallas y reemplazos de componentes (RF27).
CREATE TABLE IF NOT EXISTS falla_maquinaria (
  id_falla      INT AUTO_INCREMENT PRIMARY KEY,
  id_maquinaria INT NOT NULL,
  fecha         DATE NOT NULL,
  componente    VARCHAR(120) NULL,
  descripcion   TEXT NOT NULL,
  reemplazo     TINYINT(1) NOT NULL DEFAULT 0,
  resuelto      TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT fk_falla_maq FOREIGN KEY (id_maquinaria) REFERENCES maquinaria(id_maquinaria) ON DELETE CASCADE
);

-- Maquinaria precargada (RF: listas predefinidas). INSERT IGNORE -> idempotente.
INSERT IGNORE INTO maquinaria (id_maquinaria, nombre, tipo) VALUES
  (1, 'Retroexcavadora CAT 320', 'Excavación'),
  (2, 'Cargadora frontal JCB', 'Carga'),
  (3, 'Camión volcador Iveco', 'Transporte'),
  (4, 'Motoniveladora John Deere', 'Nivelación');
