# Usa una imagen oficial de PHP con Apache
FROM php:8.2-apache

# Copia todos los archivos al directorio raíz de Apache
COPY . /var/www/html/

# Expone el puerto estándar
EXPOSE 80

# Habilita módulos que pueda requerir PDO/MySQL
RUN docker-php-ext-install pdo pdo_mysql

# Establece el directorio raíz del servidor
WORKDIR /var/www/html
