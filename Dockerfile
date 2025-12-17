# Usamos una imagen oficial de PHP con Apache
FROM php:8.2-apache

# Instalamos las dependencias de sistema necesarias para Postgres
RUN apt-get update && apt-get install -y libpq-dev

# Instalamos y habilitamos la extensión de PHP para Postgres (pdo_pgsql)
RUN docker-php-ext-install pdo pdo_pgsql

# Copiamos nuestro código al servidor
COPY . /var/www/html/

# Exponemos el puerto 80
EXPOSE 80
