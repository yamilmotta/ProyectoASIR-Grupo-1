<?php
declare(strict_types=1);

// ============================================================
//  Antioquía (Sede Nube - AWS2) — Tesoro MySQL
//  Conexión Singleton PDO al Servidor-BBDD (Cámara Privada)
//  Host apunta a la subred privada: 10.1.0.30
//  Acceso restringido: solo desde 10.1.0.85 (Mercader Apache)
// ============================================================

class DB {
    private static ?PDO $instance = null;

    private function __construct() {}

    /* ── Conexión Singleton ──────────────────────────────────
       Host apunta al servidor MySQL en la subred privada.
       El usuario 'damas_user' tiene permisos mínimos y
       máximo 3 conexiones simultáneas.
    ── */
    public static function get(): PDO {
        if (self::$instance === null) {
            $dsn  = 'mysql:host=10.1.0.30;dbname=damas;charset=utf8mb4';
            $user = 'damas_user';
            $pass = getenv('DB_PASS') ?: 'CAMBIAR_EN_PRODUCCION';
            self::$instance = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$instance;
    }

    /* ── Crear partida ───────────────────────────────────────
       Llama al procedimiento almacenado que genera el código
       público (8 chars) y los dos tokens SHA-256.
       Devuelve: id, codigo, token_rojo, token_negro
    ── */
    public static function nuevaPartida(string $apodoRojo, string $apodoNegro): array {
        $pdo  = self::get();
        $stmt = $pdo->prepare(
            'CALL nueva_partida(?, ?, @pid, @codigo, @token_rojo, @token_negro)'
        );
        $stmt->execute([$apodoRojo, $apodoNegro]);
        $stmt->closeCursor();

        return $pdo->query(
            'SELECT @pid        AS id,
                    @codigo     AS codigo,
                    @token_rojo AS token_rojo,
                    @token_negro AS token_negro'
        )->fetch();
    }

    /* ── Cargar partida por código público ──────────────────
       El código es el identificador que ven los jugadores.
       El id interno nunca se expone al frontend.
    ── */
    public static function cargarPartida(string $codigo): ?array {
        $stmt = self::get()->prepare(
            'SELECT id, codigo,
                    token_rojo, token_negro,
                    apodo_rojo, apodo_negro,
                    tablero, turno, ganador,
                    UNIX_TIMESTAMP(ultimo_cambio) AS ts
             FROM   partidas
             WHERE  codigo = ?
               AND  activa  = 1
             LIMIT  1'
        );
        $stmt->execute([$codigo]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['tablero'] = json_decode($row['tablero'], true);
        return $row;
    }

    /* ── Validar token ───────────────────────────────────────
       Compara usando hash_equals (protección timing-attack).
       Solo acepta el token del jugador cuyo turno es activo.
    ── */
    public static function validarToken(array $partida, string $token): bool {
        return match ($partida['turno']) {
            'R' => hash_equals($partida['token_rojo'],  $token),
            'N' => hash_equals($partida['token_negro'], $token),
            default => false,
        };
    }

    /* ── Guardar estado + registrar movimiento ───────────────
       Actualiza la partida y deja trazabilidad completa
       en la tabla movimientos para auditoria.
    ── */
    public static function guardarEstado(
        int     $id,
        array   $tablero,
        string  $turno,
        ?string $ganador,
        string  $jugador,
        int     $orFila,
        int     $orCol,
        int     $dsFila,
        int     $dsCol,
        bool    $fueCaptura,
        bool    $fueCoronacion
    ): void {
        $pdo  = self::get();
        $json = json_encode($tablero, JSON_UNESCAPED_UNICODE);

        // 1 - Actualizar estado de la partida
        $pdo->prepare(
            'UPDATE partidas
             SET    tablero = ?,
                    turno   = ?,
                    ganador = ?,
                    activa  = IF(? IS NULL, 1, 0)
             WHERE  id = ?'
        )->execute([$json, $turno, $ganador, $ganador, $id]);

        // 2 - Calcular número de turno secuencial
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM movimientos WHERE partida_id = ?'
        );
        $stmt->execute([$id]);
        $numTurno = (int)$stmt->fetchColumn() + 1;

        // 3 - Registrar en historial de movimientos
        $pdo->prepare(
            'INSERT INTO movimientos
             (partida_id, numero_turno, jugador,
              origen_fila, origen_col, destino_fila, destino_col,
              fue_captura, fue_coronacion)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id, $numTurno, $jugador,
            $orFila, $orCol, $dsFila, $dsCol,
            $fueCaptura    ? 1 : 0,
            $fueCoronacion ? 1 : 0,
        ]);
    }
}
