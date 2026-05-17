# Usamos la imagen oficial de PHP con Apache incorporado
FROM php:8.2-apache

# Instalamos la extensión mysqli para que PHP pueda hablar con MySQL
RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli

# Copiamos todos tus archivos PHP a la carpeta pública de Apache dentro del contenedor
COPY ./ /var/www/html/

# Le decimos a Apache que escuche por el puerto 80
EXPOSE 80







