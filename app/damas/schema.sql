-- ============================================================
--  DAMAS — Schema MySQL optimizado
-- ============================================================
CREATE DATABASE IF NOT EXISTS damas
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE damas;

CREATE TABLE IF NOT EXISTS partidas (
  id            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  -- tablero: matriz 8×8 serializada como JSON
  -- 0=vacío, R=roja, N=negra, RD=dama roja, ND=dama negra
  tablero       JSON             NOT NULL,
  turno         ENUM('R','N')    NOT NULL DEFAULT 'R',
  ganador       ENUM('R','N')    NULL     DEFAULT NULL,
  activa        TINYINT(1)       NOT NULL DEFAULT 1,
  ultimo_cambio TIMESTAMP        NOT NULL
                DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
  creado_en     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  -- Índice para el polling: busca por timestamp rápido
  INDEX idx_cambio (ultimo_cambio),
  INDEX idx_activa (activa)
) ENGINE=InnoDB;

-- ============================================================
--  Procedimiento: nueva_partida
--  Inserta el tablero inicial y devuelve el id
-- ============================================================
DELIMITER $$
CREATE PROCEDURE nueva_partida(OUT p_id INT)
BEGIN
  -- Las piezas negras ocupan filas 0-2 (casillas oscuras)
  -- Las piezas rojas ocupan filas 5-7 (casillas oscuras)
  -- Representación: array de 8 filas × 8 columnas
  INSERT INTO partidas (tablero, turno) VALUES (
    JSON_ARRAY(
      JSON_ARRAY("","N","","N","","N","","N"),
      JSON_ARRAY("N","","N","","N","","N",""),
      JSON_ARRAY("","N","","N","","N","","N"),
      JSON_ARRAY("","","","","","","",""),
      JSON_ARRAY("","","","","","","",""),
      JSON_ARRAY("R","","R","","R","","R",""),
      JSON_ARRAY("","R","","R","","R","","R"),
      JSON_ARRAY("R","","R","","R","","R","")
    ),
    'R'
  );
  SET p_id = LAST_INSERT_ID();
END$$
DELIMITER ;

-- ============================================================
--  Vista útil para depuración
-- ============================================================
CREATE OR REPLACE VIEW v_partidas_activas AS
  SELECT id, turno, ganador,
         ultimo_cambio,
         UNIX_TIMESTAMP(ultimo_cambio) AS ts_unix
  FROM   partidas
  WHERE  activa = 1
  ORDER  BY ultimo_cambio DESC;