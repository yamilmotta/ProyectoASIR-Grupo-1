<?php
declare(strict_types=1);

// ============================================================
//  Antioquía (Sede Nube - AWS2) — Mercader Apache
//  Lógica del Juego de Damas — sin dependencias de BD
//  Validación de movimientos, capturas y coronación
// ============================================================

class GameLogic {

    /* ── Constantes de pieza ─────────────────────────────── */
    const VACIO  = '';
    const ROJA   = 'R';
    const NEGRA  = 'N';
    const DAMA_R = 'RD';
    const DAMA_N = 'ND';

    /* ── Pertenencia de pieza a jugador ──────────────────── */
    public static function esDel(string $pieza, string $turno): bool {
        return match ($turno) {
            'R' => in_array($pieza, ['R', 'RD'], true),
            'N' => in_array($pieza, ['N', 'ND'], true),
            default => false,
        };
    }

    public static function esEnemiga(string $pieza, string $turno): bool {
        if ($pieza === '') return false;
        return !self::esDel($pieza, $turno);
    }

    public static function esDama(string $pieza): bool {
        return in_array($pieza, ['RD', 'ND'], true);
    }

    /* ── Movimientos diagonales válidos para una pieza ───── */
    public static function movimientosValidos(
        array  $tablero,
        int    $fila,
        int    $col,
        string $turno
    ): array {
        $pieza = $tablero[$fila][$col];
        if (!self::esDel($pieza, $turno)) return [];

        $esDama = self::esDama($pieza);
        $movs   = [];

        // Dirección vertical: rojas suben (delta -1), negras bajan (delta +1)
        // Las damas pueden moverse en ambas direcciones
        $deltas = match (true) {
            $esDama        => [-1, 1],
            $turno === 'R' => [-1],
            default        => [1],
        };

        foreach ($deltas as $df) {
            foreach ([-1, 1] as $dc) {
                $nf = $fila + $df;
                $nc = $col  + $dc;
                if (self::dentroTablero($nf, $nc)
                    && $tablero[$nf][$nc] === '') {
                    $movs[] = ['fila' => $nf, 'col' => $nc, 'captura' => null];
                }
            }
        }

        // Añadir capturas disponibles (prioridad sobre movimiento simple)
        $capturas = self::capturasDisponibles($tablero, $fila, $col, $turno);
        return array_merge($capturas, $movs);
    }

    /* ── Detecta saltos de captura ───────────────────────── */
    public static function capturasDisponibles(
        array  $tablero,
        int    $fila,
        int    $col,
        string $turno,
        array  $yaCapturadas = []   // para cadena de capturas múltiples
    ): array {
        $pieza    = $tablero[$fila][$col];
        $esDama   = self::esDama($pieza);
        $capturas = [];

        $deltas = match (true) {
            $esDama        => [-1, 1],
            $turno === 'R' => [-1],
            default        => [1],
        };

        foreach ($deltas as $df) {
            foreach ([-1, 1] as $dc) {
                $mf = $fila + $df;    // casilla del medio (enemigo)
                $mc = $col  + $dc;
                $lf = $fila + 2*$df;  // casilla de aterrizaje
                $lc = $col  + 2*$dc;

                if (!self::dentroTablero($mf, $mc)
                    || !self::dentroTablero($lf, $lc)) {
                    continue;
                }

                $medio    = $tablero[$mf][$mc];
                $aterriz  = $tablero[$lf][$lc];
                $coordMed = "$mf,$mc";

                // La casilla del medio debe ser enemiga y no haberse capturado ya
                if (self::esEnemiga($medio, $turno)
                    && !in_array($coordMed, $yaCapturadas, true)
                    && $aterriz === '') {
                    $capturas[] = [
                        'fila'    => $lf,
                        'col'     => $lc,
                        'captura' => ['fila' => $mf, 'col' => $mc],
                    ];
                }
            }
        }

        return $capturas;
    }

    /* ── ¿Hay capturas obligatorias para el turno actual? ── */
    public static function hayCapturaObligatoria(
        array  $tablero,
        string $turno
    ): bool {
        for ($f = 0; $f < 8; $f++) {
            for ($c = 0; $c < 8; $c++) {
                if (self::esDel($tablero[$f][$c], $turno)
                    && count(self::capturasDisponibles($tablero, $f, $c, $turno)) > 0) {
                    return true;
                }
            }
        }
        return false;
    }

    /* ── Valida y aplica un movimiento ───────────────────── */
    public static function aplicarMovimiento(
        array  $tablero,
        int    $orFila, int $orCol,
        int    $dsFila, int $dsCol,
        string $turno
    ): array {
        // 1. La pieza origen existe y es del turno correcto
        $pieza = $tablero[$orFila][$orCol];
        if (!self::esDel($pieza, $turno)) {
            return ['ok' => false, 'error' => 'Pieza no válida'];
        }

        // 2. Destino vacío y en diagonal
        if ($tablero[$dsFila][$dsCol] !== '') {
            return ['ok' => false, 'error' => 'Destino ocupado'];
        }
        $df = abs($dsFila - $orFila);
        $dc = abs($dsCol  - $orCol);
        if ($df !== $dc || $df === 0 || $df > 2) {
            return ['ok' => false, 'error' => 'Movimiento no diagonal'];
        }

        // 3. Obtener movimientos legales
        $legales  = self::movimientosValidos($tablero, $orFila, $orCol, $turno);
        $obligado = self::hayCapturaObligatoria($tablero, $turno);

        // Buscar si el destino está en los movimientos legales
        $movElegido = null;
        foreach ($legales as $m) {
            if ($m['fila'] === $dsFila && $m['col'] === $dsCol) {
                $movElegido = $m;
                break;
            }
        }
        if ($movElegido === null) {
            return ['ok' => false, 'error' => 'Movimiento ilegal'];
        }

        // 4. Si hay captura obligatoria, solo se permite el movimiento de captura
        if ($obligado && $movElegido['captura'] === null) {
            return ['ok' => false, 'error' => 'Debes capturar obligatoriamente'];
        }

        // 5. Aplicar el movimiento al tablero
        $tablero[$dsFila][$dsCol] = $pieza;
        $tablero[$orFila][$orCol] = '';

        // 6. Eliminar pieza capturada
        $capturaExtra = false;
        if ($movElegido['captura'] !== null) {
            $cf = $movElegido['captura']['fila'];
            $cc = $movElegido['captura']['col'];
            $tablero[$cf][$cc] = '';

            // ¿Puede seguir capturando (captura múltiple)?
            $siguientes   = self::capturasDisponibles($tablero, $dsFila, $dsCol, $turno);
            $capturaExtra = count($siguientes) > 0;
        }

        // 7. Coronar dama (roja llega a fila 0, negra a fila 7)
        if ($turno === 'R' && $dsFila === 0 && $pieza === 'R') {
            $tablero[$dsFila][$dsCol] = 'RD';
            $capturaExtra = false; // Coronar termina el turno
        }
        if ($turno === 'N' && $dsFila === 7 && $pieza === 'N') {
            $tablero[$dsFila][$dsCol] = 'ND';
            $capturaExtra = false;
        }

        // 8. Detectar victoria (el rival no tiene piezas)
        $ganador = self::detectarVictoria($tablero, $turno);

        // 9. Cambiar turno (salvo captura múltiple pendiente)
        $nuevoTurno = $turno;
        if (!$capturaExtra && $ganador === null) {
            $nuevoTurno = ($turno === 'R') ? 'N' : 'R';
        }

        return [
            'ok'           => true,
            'tablero'      => $tablero,
            'turno'        => $nuevoTurno,
            'ganador'      => $ganador,
            'capturaExtra' => $capturaExtra,
        ];
    }

    /* ── Detecta victoria ────────────────────────────────── */
    public static function detectarVictoria(array $tablero, string $turnoActual): ?string {
        $rival       = ($turnoActual === 'R') ? 'N' : 'R';
        $piezasRival = 0;
        $movRival    = false;

        for ($f = 0; $f < 8; $f++) {
            for ($c = 0; $c < 8; $c++) {
                $p = $tablero[$f][$c];
                if (self::esDel($p, $rival)) {
                    $piezasRival++;
                    if (!$movRival
                        && count(self::movimientosValidos($tablero, $f, $c, $rival)) > 0) {
                        $movRival = true;
                    }
                }
            }
        }

        if ($piezasRival === 0 || !$movRival) {
            return $turnoActual; // El jugador actual gana
        }
        return null;
    }

    /* ── Utilidades ──────────────────────────────────────── */
    private static function dentroTablero(int $f, int $c): bool {
        return $f >= 0 && $f < 8 && $c >= 0 && $c < 8;
    }
}
