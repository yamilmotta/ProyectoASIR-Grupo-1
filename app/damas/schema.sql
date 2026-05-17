-- ══════════════════════════════════════════════════════════════
--  JUEGO DE DAMAS — TFG ASIR
--  Schema completo v2.0
--  Servidor: MySQL 8.0 en subred privada 10.1.0.30
--  Ejecutar como: sudo mysql < schema.sql
-- ══════════════════════════════════════════════════════════════

-- ── 1. BASE DE DATOS ────────────────────────────────────────
DROP DATABASE IF EXISTS damas;

CREATE DATABASE damas
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE damas;

-- ── 2. TABLA PRINCIPAL DE PARTIDAS ──────────────────────────
--
--  Decisiones de diseño:
--
--  · id          → clave interna, NUNCA se expone al frontend
--  · codigo      → 8 chars aleatorios en mayúsculas (ej: A3F9K2BX)
--                  es el identificador público que ven los jugadores
--                  en su URL. Imposible de enumerar.
--
--  · token_rojo  → SHA-256 (64 chars). Lo recibe solo el jugador Rojo
--  · token_negro → SHA-256 (64 chars). Lo recibe solo el jugador Negro
--                  El servidor valida el token antes de aceptar
--                  cualquier movimiento. Si no coincide → HTTP 403.
--
--  · apodo_rojo / apodo_negro → nombre visible, sin contraseña
--
--  · tablero     → JSON con la matriz 8×8. Valores posibles por celda:
--                  ''=vacío  'R'=Roja  'N'=Negra  'RD'=Dama Roja  'ND'=Dama Negra
--
--  · turno       → ENUM('R','N') — quién mueve ahora
--  · ganador     → NULL mientras la partida sigue, 'R' o 'N' al terminar
--  · activa      → 1=en juego, 0=terminada o expirada
--
--  · expira_en   → 24h desde la creación. Un cron limpia las
--                  partidas abandonadas para que la BD no crezca
--                  indefinidamente cuando el juego esté en internet.
-- ──────────────────────────────────────────────────────────────

CREATE TABLE partidas (
  id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  codigo          CHAR(8)         NOT NULL,
  token_rojo      CHAR(64)        NOT NULL,
  token_negro     CHAR(64)        NOT NULL,
  apodo_rojo      VARCHAR(30)     NOT NULL DEFAULT 'Jugador Rojo',
  apodo_negro     VARCHAR(30)     NOT NULL DEFAULT 'Jugador Negro',
  tablero         JSON            NOT NULL,
  turno           ENUM('R','N')   NOT NULL DEFAULT 'R',
  ganador         ENUM('R','N')   NULL     DEFAULT NULL,
  activa          TINYINT(1)      NOT NULL DEFAULT 1,
  ultimo_cambio   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                           ON UPDATE CURRENT_TIMESTAMP,
  creado_en       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_en       TIMESTAMP       NOT NULL
                  DEFAULT (CURRENT_TIMESTAMP + INTERVAL 24 HOUR),

  PRIMARY KEY (id),

  -- codigo único: búsqueda rápida por URL pública
  UNIQUE INDEX idx_codigo        (codigo),

  -- tokens únicos: imposible colisión entre partidas
  UNIQUE INDEX idx_token_rojo    (token_rojo),
  UNIQUE INDEX idx_token_negro   (token_negro),

  -- índice compuesto para el cron de limpieza
  INDEX         idx_activa_expira (activa, expira_en),

  -- índice para polling diferencial por timestamp
  INDEX         idx_ultimo_cambio (ultimo_cambio)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- ── 3. TABLA DE HISTORIAL DE MOVIMIENTOS ────────────────────
--
--  Cada movimiento queda registrado con:
--  · a qué partida pertenece  (FK con CASCADE)
--  · número de turno secuencial dentro de la partida
--  · qué jugador movió (R o N)
--  · casilla origen y destino
--  · si hubo captura o coronación en ese turno
--  · timestamp exacto del movimiento
--
--  Valor para el TFG: demuestra trazabilidad completa.
--  En la defensa puedes mostrar el historial de una partida
--  y demostrar que la BD registra todo lo que ocurre.
-- ──────────────────────────────────────────────────────────────

CREATE TABLE movimientos (
  id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  partida_id      INT UNSIGNED    NOT NULL,
  numero_turno    SMALLINT        NOT NULL,
  jugador         ENUM('R','N')   NOT NULL,
  origen_fila     TINYINT         NOT NULL,
  origen_col      TINYINT         NOT NULL,
  destino_fila    TINYINT         NOT NULL,
  destino_col     TINYINT         NOT NULL,
  fue_captura     TINYINT(1)      NOT NULL DEFAULT 0,
  fue_coronacion  TINYINT(1)      NOT NULL DEFAULT 0,
  momento         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  -- FK garantiza integridad referencial
  -- CASCADE: si se borra la partida, se borran sus movimientos
  INDEX idx_partida (partida_id),
  CONSTRAINT fk_movimiento_partida
    FOREIGN KEY (partida_id)
    REFERENCES  partidas(id)
    ON DELETE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- ── 4. PROCEDIMIENTO: nueva_partida ─────────────────────────
--
--  Genera en una sola operación atómica:
--  · código público de 8 chars (MD5 truncado, mayúsculas)
--  · token_rojo  SHA-256 con semilla aleatoria + timestamp + jugador
--  · token_negro SHA-256 con semilla aleatoria + timestamp + jugador
--  · el tablero inicial con las 12 piezas de cada color
--
--  Por qué en procedimiento almacenado:
--  · Toda la lógica de generación vive en la BD, no en PHP
--  · La operación es atómica — no puede haber un estado parcial
--  · El damas_user solo necesita EXECUTE, no INSERT directo
-- ──────────────────────────────────────────────────────────────

DELIMITER //

CREATE PROCEDURE nueva_partida(
  IN  p_apodo_rojo   VARCHAR(30),
  IN  p_apodo_negro  VARCHAR(30),
  OUT p_id           INT,
  OUT p_codigo       CHAR(8),
  OUT p_token_rojo   CHAR(64),
  OUT p_token_negro  CHAR(64)
)
BEGIN
  -- Generar código público único (8 chars, mayúsculas)
  SET p_codigo = UPPER(SUBSTRING(MD5(CONCAT(RAND(), UUID())), 1, 8));

  -- Generar tokens únicos por jugador con SHA-256
  -- Semilla: valor aleatorio + timestamp de microsegundos + rol + apodo
  SET p_token_rojo  = SHA2(CONCAT(
    RAND(), NOW(6), UUID(), 'ROJO',  p_apodo_rojo
  ), 256);

  SET p_token_negro = SHA2(CONCAT(
    RAND(), NOW(6), UUID(), 'NEGRO', p_apodo_negro
  ), 256);

  -- Insertar la partida con el tablero inicial
  INSERT INTO partidas (
    codigo, token_rojo, token_negro,
    apodo_rojo, apodo_negro,
    tablero, turno
  ) VALUES (
    p_codigo,
    p_token_rojo,
    p_token_negro,
    COALESCE(NULLIF(TRIM(p_apodo_rojo),  ''), 'Jugador Rojo'),
    COALESCE(NULLIF(TRIM(p_apodo_negro), ''), 'Jugador Negro'),
    JSON_ARRAY(
      JSON_ARRAY('','N','','N','','N','','N'),
      JSON_ARRAY('N','','N','','N','','N',''),
      JSON_ARRAY('','N','','N','','N','','N'),
      JSON_ARRAY('','','','','','','',''),
      JSON_ARRAY('','','','','','','',''),
      JSON_ARRAY('R','','R','','R','','R',''),
      JSON_ARRAY('','R','','R','','R','','R'),
      JSON_ARRAY('R','','R','','R','','R','')
    ),
    'R'
  );

  SET p_id = LAST_INSERT_ID();
END//

DELIMITER ;


-- ── 5. PROCEDIMIENTO: limpiar_expiradas ─────────────────────
--
--  Marca como inactivas las partidas que llevan más de 24h
--  sin actividad y no tienen ganador (fueron abandonadas).
--
--  Se ejecuta desde cron cada hora:
--  0 * * * * sudo mysql damas -e "CALL limpiar_expiradas();"
-- ──────────────────────────────────────────────────────────────

DELIMITER //

CREATE PROCEDURE limpiar_expiradas()
BEGIN
  UPDATE partidas
  SET    activa = 0
  WHERE  activa    = 1
    AND  ganador   IS NULL
    AND  expira_en < NOW();
END//

DELIMITER ;


-- ── 6. USUARIOS DE BASE DE DATOS ────────────────────────────
--
--  Dos usuarios con principio de mínimo privilegio:
--
--  damas_user → usuario de la aplicación web
--    · Solo desde el servidor Apache (10.1.0.85)
--    · Solo los permisos que el juego necesita
--    · Máximo 3 conexiones simultáneas
--    · Nunca puede hacer DROP, ALTER, DELETE global, etc.
--
--  damas_admin → usuario de administración
--    · Solo desde la red VPN (10.100.0.0/24)
--    · Nunca accesible desde internet
--    · Para mantenimiento, backups manuales, consultas
--    · Máximo 5 conexiones simultáneas
--
--  root → solo localhost (comportamiento por defecto de MySQL 8)
--         verificado y documentado como medida de seguridad
-- ──────────────────────────────────────────────────────────────

-- Limpiar si existen de ejecuciones anteriores
DROP USER IF EXISTS 'damas_user'@'10.1.0.85';
DROP USER IF EXISTS 'damas_admin'@'10.100.0.%';

-- Usuario aplicación web
CREATE USER 'damas_user'@'10.1.0.85'
  IDENTIFIED BY 'Damas2026#Seguro'
  WITH MAX_USER_CONNECTIONS 3;

-- Solo los permisos mínimos que necesita el juego
GRANT SELECT, INSERT, UPDATE ON damas.partidas    TO 'damas_user'@'10.1.0.85';
GRANT SELECT, INSERT         ON damas.movimientos TO 'damas_user'@'10.1.0.85';
GRANT EXECUTE                ON damas.*           TO 'damas_user'@'10.1.0.85';

-- Usuario administración (solo accesible por VPN)
CREATE USER 'damas_admin'@'10.100.0.%'
  IDENTIFIED BY 'Admin2026#TFG'
  WITH MAX_USER_CONNECTIONS 5;

GRANT ALL PRIVILEGES ON damas.* TO 'damas_admin'@'10.100.0.%';

FLUSH PRIVILEGES;


-- ── 7. VERIFICACIÓN FINAL ───────────────────────────────────

SELECT '══ TABLAS CREADAS ══' AS '';
SHOW TABLES FROM damas;

SELECT '══ ESTRUCTURA partidas ══' AS '';
DESCRIBE partidas;

SELECT '══ ESTRUCTURA movimientos ══' AS '';
DESCRIBE movimientos;

SELECT '══ PROCEDIMIENTOS ══' AS '';
SHOW PROCEDURE STATUS WHERE Db = 'damas';

SELECT '══ USUARIOS Y CONEXIONES ══' AS '';
SELECT
  user,
  host,
  max_user_connections
FROM mysql.user
WHERE user IN ('damas_user', 'damas_admin', 'root')
ORDER BY user;

SELECT '══ PERMISOS damas_user ══' AS '';
SHOW GRANTS FOR 'damas_user'@'10.1.0.85';

SELECT '══ PERMISOS damas_admin ══' AS '';
SHOW GRANTS FOR 'damas_admin'@'10.100.0.%';

SELECT '✅ Schema instalado correctamente' AS resultado;
