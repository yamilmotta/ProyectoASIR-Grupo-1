<?php
header('Content-Type: application/json');
$archivo = 'chat.json';

// Crear archivo si no existe
if (!file_exists($archivo)) {
    file_put_contents($archivo, json_encode([]));
}

$input = json_decode(file_get_contents('php://input'), true);
$accion = $input['accion'] ?? 'leer';

// 1. LEER MENSAJES
if ($accion === 'leer') {
    echo file_get_contents($archivo);
    exit;
}

// 2. ENVIAR MENSAJE
if ($accion === 'enviar') {
    $nuevoMensaje = [
        'jugador' => $input['jugador'], // "Rojo" o "Amarillo"
        'texto' => htmlspecialchars($input['texto']), // Evitar código malicioso
        'hora' => date('H:i:s')
    ];

    $mensajes = json_decode(file_get_contents($archivo), true);
    $mensajes[] = $nuevoMensaje;

    // Guardar solo los últimos 20 mensajes para no llenar el disco
    if (count($mensajes) > 20) {
        array_shift($mensajes);
    }

    file_put_contents($archivo, json_encode($mensajes));
    echo json_encode($mensajes);
    exit;
}
?>