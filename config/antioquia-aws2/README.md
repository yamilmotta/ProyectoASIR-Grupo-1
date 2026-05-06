# Antioquía — Sede Nube (AWS2)

Esta es la sede nube del proyecto, montada sobre la Cuenta AWS2. Es la parte más visible porque es la que está abierta a internet y donde corre el juego de Damas.

---

## Cómo está organizado

```
antioquia-aws2/
│
├── puerto-subred-publica/          # Subred Pública (10.1.0.64/26)
│   └── mercader-apache/            # Servidor Web — Apache + PHP en Docker
│       ├── docker-compose.yml      # Levanta el contenedor
│       ├── Dockerfile              # PHP 8.2 con pdo_mysql incluido
│       └── DB.php                  # Conexión a MySQL en la subred privada
│
├── camara-subred-privada/          # Subred Privada (10.1.0.0/26)
│   └── tesoro-mysql/               # Servidor MySQL 8.0
│       ├── schema.sql              # Tablas, procedimientos y usuarios
│       └── backup_damas.sh         # Backup automático cada noche
│
└── enlace-vpn/                     # Cliente VPN WireGuard (10.100.0.3)
    └── crontab.txt                 # Tareas programadas del servidor de BD
```

---

## Qué hay en cada sitio

| Nombre | Qué es realmente | IP |
|---|---|---|
| Puerto | Subred Pública | 10.1.0.64/26 |
| Mercader | Apache + PHP en Docker | 10.1.0.85 |
| Cámara | Subred Privada | 10.1.0.0/26 |
| Tesoro | MySQL 8.0 | 10.1.0.30 |
| Enlace | Cliente VPN WireGuard | 10.100.0.3 |

---

## Cosas importantes a tener en cuenta

- La contraseña de la base de datos va en una variable de entorno `DB_PASS`, no hardcodeada en el código. Nada de contraseñas reales subidas al repositorio.
- El usuario `damas_user` solo puede conectarse desde `10.1.0.85`. Desde cualquier otra IP, MySQL directamente rechaza la conexión.
- MySQL está en la subred privada y no tiene salida directa a internet. Solo el servidor web puede hablar con él.
