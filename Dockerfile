# Gunakan image base PHP dengan OpenSwoole
FROM openswoole/swoole:php8.2

# Install dependensi yang diperlukan
RUN apt-get update && apt-get install -y git unzip mc vim nano \
    && docker-php-ext-install pdo_mysql

# Install Composer
ENV MYSQL_HOST="localhost"
ENV MYSQL_PORT=3306
ENV MYSQL_USER="root"
ENV MYSQL_PASSWORD=""
ENV MYSQL_DATABASE="mysql"
ENV SERVER_PORT=9501
ENV GID=1000
ENV UID=1000
# Set working directory
WORKDIR /var/www

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy file aplikasi ke container
RUN mkdir /app
COPY ./run.sh /app/run.sh
COPY ./server.php /app/server.php
COPY ./routes.php /app/routes.php
RUN chmod +x /app/run.sh

# Expose port 9501
EXPOSE ${SERVER_PORT}

# Jalankan OpenSwoole HTTP server
ENTRYPOINT ["/app/run.sh"]
CMD ["bash","-c","/app/run.sh"]