# Usamos PHP 8.2 FPM sobre Alpine para mayor ligereza
FROM php:8.2-fpm-alpine

# Instalamos dependencias del sistema si fueran necesarias en el futuro
# (Para json y session, PHP ya las trae integradas)
RUN apk add --no-cache bash

# Establecemos el directorio de trabajo
WORKDIR /var/www/html

# Creamos el directorio de datos y ajustamos permisos para el usuario www-data
RUN mkdir -p /var/www/html/data && \
    chown -R www-data:www-data /var/www/html/data && \
    chmod 700 /var/www/html/data

# Exponemos el puerto de PHP-FPM
EXPOSE 9000

CMD ["php-fpm"]
