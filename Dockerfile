# Usamos PHP 8.2 FPM sobre Alpine para mayor ligereza
FROM php:8.2-fpm-alpine

# Instalamos dependencias del sistema si fueran necesarias en el futuro
# (Para json y session, PHP ya las trae integradas)
RUN apk add --no-cache bash

# Establecemos el directorio de trabajo
WORKDIR /var/www/html

# 1. Copiamos todo el repositorio hacia adentro de la imagen
COPY . /var/www/html/

# 2. Le damos el control total a www-data (pero solo ADENTRO del contenedor)
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 775 /var/www/html

EXPOSE 9000
CMD ["php-fpm"]
