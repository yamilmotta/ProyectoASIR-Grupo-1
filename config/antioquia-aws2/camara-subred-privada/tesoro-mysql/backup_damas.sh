#!/bin/bash
# ============================================================
#  Antioquía (Sede Nube - AWS2) — Tesoro MySQL
#  Script de backup automático de la base de datos damas
#  Cámara Privada: 10.1.0.30
#  Programado: cada noche a las 02:00 AM via crontab
# ============================================================

FECHA=$(date +%Y%m%d_%H%M)
DESTINO="/home/ubuntu/backups"

# Crear carpeta si no existe
mkdir -p "$DESTINO"

# Hacer el backup completo
mysqldump -u root damas > "$DESTINO/damas_$FECHA.sql"

# Verificar que el fichero no está vacío
if [ -s "$DESTINO/damas_$FECHA.sql" ]; then
    echo "$(date) — Backup OK: damas_$FECHA.sql ($(du -h $DESTINO/damas_$FECHA.sql | cut -f1))"
else
    echo "$(date) — ERROR: backup vacío"
    rm "$DESTINO/damas_$FECHA.sql"
fi

# Borrar backups con más de 7 días
find $DESTINO -name "*.sql" -mtime +7 -delete
echo "$(date) — Limpieza de backups antiguos completada"
