# Usa una imagen oficial de PHP con Apache
FROM php:8.2-apache

# Copia todos los archivos del proyecto al directorio raíz de Apache
COPY . /var/www/html/

# Habilita el módulo de reescritura de Apache
RUN docker-php-ext-install pdo pdo_mysql && a2enmod rewrite

# Da permisos correctos
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html
