-- ============================================================
--  Antioquía (Sede Nube - AWS2) — Tesoro MySQL
--  Cámara Privada: 10.1.0.0/26
--  Base de datos del Juego de Damas
--  MySQL 8.0 — utf8mb4
-- ============================================================

CREATE DATABASE IF NOT EXISTS damas
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE damas;

-- ── Tabla principal de partidas ───────────────────────────
CREATE TABLE IF NOT EXISTS `partidas` (
  `id`            int unsigned     NOT NULL AUTO_INCREMENT,
  `codigo`        char(8)          COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_rojo`    char(64)         COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_negro`   char(64)         COLLATE utf8mb4_unicode_ci NOT NULL,
  `apodo_rojo`    varchar(30)      COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Jugador Rojo',
  `apodo_negro`   varchar(30)      COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Jugador Negro',
  `tablero`       json             NOT NULL,
  `turno`         enum('R','N')    COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'R',
  `ganador`       enum('R','N')    COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activa`        tinyint(1)       NOT NULL DEFAULT '1',
  `ultimo_cambio` timestamp        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `creado_en`     timestamp        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expira_en`     timestamp        NOT NULL DEFAULT (now() + interval 24 hour),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_codigo`      (`codigo`),
  UNIQUE KEY `idx_token_rojo`  (`token_rojo`),
  UNIQUE KEY `idx_token_negro` (`token_negro`),
  KEY `idx_activa_expira`      (`activa`, `expira_en`),
  KEY `idx_ultimo_cambio`      (`ultimo_cambio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Tabla de historial de movimientos ────────────────────
CREATE TABLE IF NOT EXISTS `movimientos` (
  `id`             int unsigned  NOT NULL AUTO_INCREMENT,
  `partida_id`     int unsigned  NOT NULL,
  `numero_turno`   int unsigned  NOT NULL,
  `jugador`        enum('R','N') NOT NULL,
  `origen_fila`    tinyint       NOT NULL,
  `origen_col`     tinyint       NOT NULL,
  `destino_fila`   tinyint       NOT NULL,
  `destino_col`    tinyint       NOT NULL,
  `fue_captura`    tinyint(1)    NOT NULL DEFAULT 0,
  `fue_coronacion` tinyint(1)    NOT NULL DEFAULT 0,
  `creado_en`      timestamp     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_partida` (`partida_id`),
  CONSTRAINT `fk_movimientos_partida`
    FOREIGN KEY (`partida_id`) REFERENCES `partidas` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Usuario restringido para el Mercader Apache ──────────
-- Solo acepta conexiones desde 10.1.0.85 (Servidor Web)
-- Ejecutar como root la primera vez:
-- CREATE USER 'damas_user'@'10.1.0.85' IDENTIFIED BY 'CONTRASEÑA_SEGURA';
-- GRANT ALL PRIVILEGES ON damas.* TO 'damas_user'@'10.1.0.85';
-- FLUSH PRIVILEGES;

-- ── Procedimiento: nueva_partida ─────────────────────────
DELIMITER $$
CREATE PROCEDURE IF NOT EXISTS nueva_partida(
    IN  p_apodo_rojo  VARCHAR(30),
    IN  p_apodo_negro VARCHAR(30),
    OUT p_id          INT,
    OUT p_codigo      CHAR(8),
    OUT p_token_rojo  CHAR(64),
    OUT p_token_negro CHAR(64)
)
BEGIN
    SET p_codigo      = SUBSTRING(MD5(RAND()), 1, 8);
    SET p_token_rojo  = SHA2(CONCAT(RAND(), NOW(), 'R'), 256);
    SET p_token_negro = SHA2(CONCAT(RAND(), NOW(), 'N'), 256);

    INSERT INTO partidas (
        codigo, token_rojo, token_negro,
        apodo_rojo, apodo_negro, tablero, turno
    ) VALUES (
        p_codigo, p_token_rojo, p_token_negro,
        p_apodo_rojo, p_apodo_negro,
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
END$$
DELIMITER ;

-- ── Procedimiento: limpiar_expiradas ─────────────────────
-- Ejecutado cada hora por cron para limpiar partidas vencidas
DELIMITER $$
CREATE PROCEDURE IF NOT EXISTS limpiar_expiradas()
BEGIN
    DELETE FROM partidas
    WHERE  activa = 1
      AND  expira_en < NOW();
END$$
DELIMITER ;

-- ── Vista de partidas activas ─────────────────────────────
CREATE OR REPLACE VIEW v_partidas_activas AS
    SELECT id, codigo, turno, ganador,
           apodo_rojo, apodo_negro,
           ultimo_cambio,
           UNIX_TIMESTAMP(ultimo_cambio) AS ts_unix,
           expira_en
    FROM   partidas
    WHERE  activa = 1
    ORDER  BY ultimo_cambio DESC;
