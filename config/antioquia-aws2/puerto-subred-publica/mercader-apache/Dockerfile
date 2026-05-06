# ============================================================
#  Antioquía (Sede Nube - AWS2) — Mercader Apache
#  Imagen personalizada: PHP 8.2 + Apache + extensiones MySQL
# ============================================================

# Imagen base oficial con Apache y PHP integrados
FROM php:8.2-apache

# Instalar extensiones necesarias para conectar con MySQL
# - mysqli: extensión clásica de MySQL
# - pdo + pdo_mysql: interfaz moderna usada en DB.php
RUN docker-php-ext-install mysqli pdo pdo_mysql \
    && docker-php-ext-enable mysqli

# Copiar ficheros de la aplicación al directorio público de Apache
COPY ./html /var/www/html/

# Puerto de escucha de Apache
EXPOSE 80
