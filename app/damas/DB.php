<?php
declare(strict_types=1);

class DB {
    private static ?PDO $instance = null;

    private function __construct() {}

    public static function get(): PDO {
        if (self::$instance === null) {
            $host   = getenv('DB_HOST') ?: '10.1.0.30';
            $dbname = getenv('DB_NAME') ?: 'damas';
            $user   = getenv('DB_USER') ?: 'damas_user';
            $pass   = getenv('DB_PASS'); // Obligatorio, sin fallback hardcodeado

            $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";

            self::$instance = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$instance;
    }

    /* Carga una partida por id */
    public static function cargarPartida(int $id): ?array {
        $stmt = self::get()->prepare(
            'SELECT id, tablero, turno, ganador,
                    UNIX_TIMESTAMP(ultimo_cambio) AS ts
             FROM   partidas
             WHERE  id = ? AND activa = 1
             LIMIT  1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['tablero'] = json_decode($row['tablero'], true);
        return $row;
    }

    /* Guarda el estado tras un movimiento */
    public static function guardarEstado(
        int    $id,
        array  $tablero,
        string $turno,
        ?string $ganador = null
    ): void {
        $pdo  = self::get();
        $json = json_encode($tablero, JSON_UNESCAPED_UNICODE);
        $stmt = $pdo->prepare(
            'UPDATE partidas
             SET    tablero = ?,
                    turno   = ?,
                    ganador = ?,
                    activa  = IF(? IS NULL, 1, 0)
             WHERE  id = ?'
        );
        $stmt->execute([$json, $turno, $ganador, $ganador, $id]);
    }

    /* Crea una nueva partida usando el procedimiento almacenado */
    public static function nuevaPartida(): int {
        $pdo = self::get();
        $pdo->exec('CALL nueva_partida(@pid)');
        return (int) $pdo->query('SELECT @pid')->fetchColumn();
    }
}
