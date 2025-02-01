#!/bin/bash
cd /var/www
composer create-project slim/slim-skeleton html
cd /var/www/html
cp /app/server.php /var/www/html/server.php
cp -f /app/routes.php /var/www/html/app/routes.php
chown -R 1000:1000 /var/www/html
composer install --no-dev --optimize-autoloader
composer require openswoole/core slim/slim mevdschee/php-crud-api firebase/php-jwt jimtools/jwt-auth
php -q server.php