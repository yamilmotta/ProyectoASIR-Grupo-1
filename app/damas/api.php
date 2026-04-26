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
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/* ── Router ──────────────────────────────────────────────── */
$accion = $_GET['accion'] ?? '';

try {
    match ($accion) {
        'nueva'   => accionNueva(),
        'estado'  => accionEstado(),
        'mover'   => accionMover(),
        'movs'    => accionMovimientos(),
        default   => err('Acción desconocida', 404),
    };
} catch (Throwable $e) {
    err('Error interno: ' . $e->getMessage(), 500);
}

/* ── POST /api.php?accion=nueva ──────────────────────────── */
function accionNueva(): void {
    $id = DB::nuevaPartida();
    ok(['id' => $id]);
}

/* ── GET /api.php?accion=estado&id=X[&ts=Y] ─────────────── */
// ts: unix timestamp del cliente para polling diferencial
function accionEstado(): void {
    $id = (int)($_GET['id'] ?? 0);
    $ts = (int)($_GET['ts'] ?? 0);
    if ($id <= 0) err('id inválido');

    $partida = DB::cargarPartida($id);
    if (!$partida) err('Partida no encontrada', 404);

    // Si el cliente ya tiene la versión más reciente → 304 sin cuerpo
    if ($ts > 0 && (int)$partida['ts'] <= $ts) {
        http_response_code(304);
        exit;
    }

    // Calcular movimientos disponibles para el turno actual
    $movsPorPieza = [];
    $haCaptura    = GameLogic::hayCapturaObligatoria($partida['tablero'], $partida['turno']);
    for ($f = 0; $f < 8; $f++) {
        for ($c = 0; $c < 8; $c++) {
            if (GameLogic::esDel($partida['tablero'][$f][$c], $partida['turno'])) {
                $m = GameLogic::movimientosValidos($partida['tablero'], $f, $c, $partida['turno']);
                // Si hay captura obligatoria, filtrar solo capturas
                if ($haCaptura) {
                    $m = array_values(array_filter($m, fn($x) => $x['captura'] !== null));
                }
                if (count($m) > 0) {
                    $movsPorPieza["$f,$c"] = $m;
                }
            }
        }
    }

    ok([
        'id'            => $partida['id'],
        'tablero'       => $partida['tablero'],
        'turno'         => $partida['turno'],
        'ganador'       => $partida['ganador'],
        'ts'            => $partida['ts'],
        'capturaOblg'   => $haCaptura,
        'movimientos'   => $movsPorPieza,
    ]);
}

/* ── POST /api.php?accion=mover ──────────────────────────── */
function accionMover(): void {
    $body = bodyJson();
    $id     = (int)($body['id']     ?? 0);
    $orFila = (int)($body['orFila'] ?? -1);
    $orCol  = (int)($body['orCol']  ?? -1);
    $dsFila = (int)($body['dsFila'] ?? -1);
    $dsCol  = (int)($body['dsCol']  ?? -1);

    if ($id <= 0) err('id inválido');

    $partida = DB::cargarPartida($id);
    if (!$partida) err('Partida no encontrada', 404);
    if ($partida['ganador'] !== null) err('La partida ya terminó');

    $resultado = GameLogic::aplicarMovimiento(
        $partida['tablero'],
        $orFila, $orCol,
        $dsFila, $dsCol,
        $partida['turno']
    );

    if (!$resultado['ok']) {
        err($resultado['error']);
    }

    DB::guardarEstado(
        $id,
        $resultado['tablero'],
        $resultado['turno'],
        $resultado['ganador']
    );

    ok([
        'tablero'      => $resultado['tablero'],
        'turno'        => $resultado['turno'],
        'ganador'      => $resultado['ganador'],
        'capturaExtra' => $resultado['capturaExtra'],
    ]);
}

/* ── GET /api.php?accion=movs&id=X&fila=F&col=C ─────────── */
function accionMovimientos(): void {
    $id   = (int)($_GET['id']   ?? 0);
    $fila = (int)($_GET['fila'] ?? -1);
    $col  = (int)($_GET['col']  ?? -1);
    if ($id <= 0) err('id inválido');

    $partida = DB::cargarPartida($id);
    if (!$partida) err('Partida no encontrada', 404);

    $movs = GameLogic::movimientosValidos(
        $partida['tablero'], $fila, $col, $partida['turno']
    );
    ok(['movimientos' => $movs]);
}