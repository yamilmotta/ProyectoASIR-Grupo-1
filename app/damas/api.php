<?php
declare(strict_types=1);
require_once __DIR__ . '/DB.php';
require_once __DIR__ . '/GameLogic.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* ── Helpers ─────────────────────────────────────────────── */
function ok(array $data): void {
    echo json_encode(['ok' => true, ...$data], JSON_UNESCAPED_UNICODE);
    exit;
}

function err(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function bodyJson(): array {
    $raw     = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/* ── Router ──────────────────────────────────────────────── */
$accion = $_GET['accion'] ?? '';

try {
    match ($accion) {
        'nueva'  => accionNueva(),
        'estado' => accionEstado(),
        'mover'  => accionMover(),
        'movs'   => accionMovimientos(),
	'identificar' => accionIdentificar(),        
	default  => err('Acción desconocida', 404),
    };
} catch (Throwable $e) {
    err('Error interno: ' . $e->getMessage(), 500);
}

/* ── POST /api.php?accion=nueva ──────────────────────────────
   Body JSON: { apodo_rojo, apodo_negro }
   Devuelve:  { id, codigo, token_rojo, token_negro }

   El frontend debe mostrar al jugador Rojo su enlace con
   ?codigo=XXXX&token=TOKEN_ROJO  y al Negro con su token.
   Los tokens nunca se vuelven a devolver en otro endpoint.
── */
function accionNueva(): void {
    $body       = bodyJson();
    $apodoRojo  = trim((string)($body['apodo_rojo']  ?? 'Jugador Rojo'));
    $apodoNegro = trim((string)($body['apodo_negro'] ?? 'Jugador Negro'));

    // Sanitizar apodos: máximo 30 caracteres, solo texto visible
    $apodoRojo  = mb_substr(strip_tags($apodoRojo),  0, 30) ?: 'Jugador Rojo';
    $apodoNegro = mb_substr(strip_tags($apodoNegro), 0, 30) ?: 'Jugador Negro';

    $resultado = DB::nuevaPartida($apodoRojo, $apodoNegro);

    ok([
        'id'          => (int)$resultado['id'],
        'codigo'      => $resultado['codigo'],
        'token_rojo'  => $resultado['token_rojo'],
        'token_negro' => $resultado['token_negro'],
        'apodo_rojo'  => $apodoRojo,
        'apodo_negro' => $apodoNegro,
    ]);
}

/* ── GET /api.php?accion=estado&codigo=X[&ts=Y] ─────────────
   El cliente identifica la partida por su código público.
   ts: unix timestamp para polling diferencial (evita tráfico).
   No requiere token — el estado del tablero es público.
── */
function accionEstado(): void {
    $codigo = strtoupper(trim($_GET['codigo'] ?? ''));
    $ts     = (int)($_GET['ts'] ?? 0);

    if (strlen($codigo) !== 8) err('Código de partida inválido');

    $partida = DB::cargarPartida($codigo);
    if (!$partida) err('Partida no encontrada', 404);

    // Polling diferencial: si el cliente ya tiene la versión actual → 304
    if ($ts > 0 && (int)$partida['ts'] <= $ts) {
        http_response_code(304);
        exit;
    }

    // Calcular movimientos válidos para el turno actual
    $movsPorPieza = [];
    $haCaptura    = GameLogic::hayCapturaObligatoria($partida['tablero'], $partida['turno']);

    for ($f = 0; $f < 8; $f++) {
        for ($c = 0; $c < 8; $c++) {
            if (GameLogic::esDel($partida['tablero'][$f][$c], $partida['turno'])) {
                $m = GameLogic::movimientosValidos(
                    $partida['tablero'], $f, $c, $partida['turno']
                );
                if ($haCaptura) {
                    $m = array_values(
                        array_filter($m, fn($x) => $x['captura'] !== null)
                    );
                }
                if (count($m) > 0) {
                    $movsPorPieza["$f,$c"] = $m;
                }
            }
        }
    }

    ok([
        'codigo'      => $partida['codigo'],
        'apodo_rojo'  => $partida['apodo_rojo'],
        'apodo_negro' => $partida['apodo_negro'],
        'tablero'     => $partida['tablero'],
        'turno'       => $partida['turno'],
        'ganador'     => $partida['ganador'],
        'ts'          => $partida['ts'],
        'capturaOblg' => $haCaptura,
        'movimientos' => $movsPorPieza,
    ]);
}

/* ── POST /api.php?accion=mover ──────────────────────────────
   Body JSON: { codigo, token, orFila, orCol, dsFila, dsCol }

   Seguridad:
   1. El token identifica al jugador (R o N)
   2. Solo puede mover si el turno le corresponde
   3. Si el token no coincide con el turno → error 403
   4. GameLogic valida la legalidad del movimiento
   5. El movimiento queda registrado en la tabla movimientos
── */
function accionMover(): void {
    $body   = bodyJson();
    $codigo = strtoupper(trim((string)($body['codigo'] ?? '')));
    $token  = trim((string)($body['token']  ?? ''));
    $orFila = (int)($body['orFila'] ?? -1);
    $orCol  = (int)($body['orCol']  ?? -1);
    $dsFila = (int)($body['dsFila'] ?? -1);
    $dsCol  = (int)($body['dsCol']  ?? -1);

    if (strlen($codigo) !== 8)  err('Código de partida inválido');
    if (strlen($token)  !== 64) err('Token inválido');

    $partida = DB::cargarPartida($codigo);
    if (!$partida)                       err('Partida no encontrada', 404);
    if ($partida['ganador'] !== null)    err('La partida ya terminó');

    // Validación de identidad: el token debe corresponder al turno activo
    if (!DB::validarToken($partida, $token)) {
        err('No es tu turno o token incorrecto', 403);
    }

    // Pieza antes del movimiento (para detectar coronación)
    $piezaAntes = $partida['tablero'][$orFila][$orCol] ?? '';

    $resultado = GameLogic::aplicarMovimiento(
        $partida['tablero'],
        $orFila, $orCol,
        $dsFila, $dsCol,
        $partida['turno']
    );

    if (!$resultado['ok']) {
        err($resultado['error']);
    }

    // Detectar si hubo coronación comparando pieza antes/después
    $piezaDespues  = $resultado['tablero'][$dsFila][$dsCol] ?? '';
    $fueCaptura    = abs($dsFila - $orFila) === 2;
    $fueCoronacion = ($piezaAntes === 'R' && $piezaDespues === 'RD')
                  || ($piezaAntes === 'N' && $piezaDespues === 'ND');

    DB::guardarEstado(
        (int)$partida['id'],
        $resultado['tablero'],
        $resultado['turno'],
        $resultado['ganador'],
        $partida['turno'],   // jugador que acaba de mover
        $orFila, $orCol,
        $dsFila, $dsCol,
        $fueCaptura,
        $fueCoronacion
    );

    ok([
        'tablero'      => $resultado['tablero'],
        'turno'        => $resultado['turno'],
        'ganador'      => $resultado['ganador'],
        'capturaExtra' => $resultado['capturaExtra'],
    ]);
}

/* ── GET /api.php?accion=movs&codigo=X&fila=F&col=C ─────────
   Consulta puntual de movimientos válidos para una pieza.
── */
function accionMovimientos(): void {
    $codigo = strtoupper(trim($_GET['codigo'] ?? ''));
    $fila   = (int)($_GET['fila'] ?? -1);
    $col    = (int)($_GET['col']  ?? -1);

    if (strlen($codigo) !== 8) err('Código de partida inválido');

    $partida = DB::cargarPartida($codigo);
    if (!$partida) err('Partida no encontrada', 404);

    $movs = GameLogic::movimientosValidos(
        $partida['tablero'], $fila, $col, $partida['turno']
    );

    ok(['movimientos' => $movs]);

/* ── GET /api.php?accion=identificar&codigo=X&token=T ───────
   Devuelve qué jugador ('R' o 'N') corresponde al token.
   Si el token no coincide con ninguno → 403.
── */
function accionIdentificar(): void {
    $codigo = strtoupper(trim($_GET['codigo'] ?? ''));
    $token  = trim($_GET['token'] ?? '');

    if (strlen($codigo) !== 8)  err('Código inválido');
    if (strlen($token)  !== 64) err('Token inválido');

    $partida = DB::cargarPartida($codigo);
    if (!$partida) err('Partida no encontrada', 404);

    if (hash_equals($partida['token_rojo'], $token)) {
        ok(['jugador' => 'R', 'apodo' => $partida['apodo_rojo']]);
    } elseif (hash_equals($partida['token_negro'], $token)) {
        ok(['jugador' => 'N', 'apodo' => $partida['apodo_negro']]);
    } else {
        err('Token no válido para esta partida', 403);
    }
}

}
