<?php
header('Content-Type: application/json');
$archivo = 'conecta4_estado.json';

// Inicializar si no existe
if (!file_exists($archivo)) {
    reiniciar();
}

$input = json_decode(file_get_contents('php://input'), true);
$accion = $input['accion'] ?? 'leer';
$estado = json_decode(file_get_contents($archivo), true);

// 1. LEER ESTADO
if ($accion === 'leer') {
    echo json_encode($estado);
    exit;
}

// 2. JUGAR (SOLTAR FICHA)
if ($accion === 'jugar') {
    $columna = $input['columna']; // 0 a 6
    $jugador = $input['jugador']; // "Rojo" o "Amarillo"

    // Validaciones: Es mi turno? Hay ganador?
    if ($estado['turno'] === $jugador && $estado['ganador'] === null) {
        
        // LÓGICA DE GRAVEDAD: Buscar la fila vacía más baja en esa columna
        // Recorremos desde la fila de abajo (5) hasta la de arriba (0)
        for ($fila = 5; $fila >= 0; $fila--) {
            if ($estado['tablero'][$fila][$columna] === "") {
                // Encontramos hueco
                $estado['tablero'][$fila][$columna] = $jugador;
                
                // Verificar victoria
                if (verificarVictoria($estado['tablero'], $jugador)) {
                    $estado['ganador'] = $jugador;
                } else {
                    // Cambio de turno
                    $estado['turno'] = ($jugador === "Rojo") ? "Amarillo" : "Rojo";
                }
                
                guardar($estado);
                break; // Salimos del bucle, ya pusimos la ficha
            }
        }
    }
    echo json_encode($estado);
    exit;
}

// 3. REINICIAR
if ($accion === 'reiniciar') {
    reiniciar();
    echo json_encode(json_decode(file_get_contents($archivo)));
}

// --- FUNCIONES ---

function guardar($data) {
    global $archivo;
    file_put_contents($archivo, json_encode($data));
}

function reiniciar() {
    // Matriz de 6 filas x 7 columnas vacías
    $tableroVacio = array_fill(0, 6, array_fill(0, 7, ""));
    $data = [
        'tablero' => $tableroVacio,
        'turno' => 'Rojo',
        'ganador' => null
    ];
    guardar($data);
}

function verificarVictoria($tablero, $jugador) {
    // Filas = 6, Columnas = 7
    // Chequear Horizontal, Vertical y Diagonales
    // Algoritmo simplificado:
    
    for ($r = 0; $r < 6; $r++) {
        for ($c = 0; $c < 7; $c++) {
            if ($tablero[$r][$c] !== $jugador) continue;

            // Horizontal (-)
            if ($c + 3 < 7 &&
                $tablero[$r][$c+1] == $jugador &&
                $tablero[$r][$c+2] == $jugador &&
                $tablero[$r][$c+3] == $jugador) return true;

            // Vertical (|)
            if ($r + 3 < 6 &&
                $tablero[$r+1][$c] == $jugador &&
                $tablero[$r+2][$c] == $jugador &&
                $tablero[$r+3][$c] == $jugador) return true;

            // Diagonal (\)
            if ($r + 3 < 6 && $c + 3 < 7 &&
                $tablero[$r+1][$c+1] == $jugador &&
                $tablero[$r+2][$c+2] == $jugador &&
                $tablero[$r+3][$c+3] == $jugador) return true;

            // Diagonal (/)
            if ($r - 3 >= 0 && $c + 3 < 7 &&
                $tablero[$r-1][$c+1] == $jugador &&
                $tablero[$r-2][$c+2] == $jugador &&
                $tablero[$r-3][$c+3] == $jugador) return true;
        }
    }
    return false;
}
?>