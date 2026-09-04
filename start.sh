#!/bin/bash

# 1. Ejecutar Reverb en segundo plano
php artisan reverb:start --host=0.0.0.0 --port=8081 &

# 2. Iniciar el servidor web en primer plano
vendor/bin/heroku-php-nginx -C nginx.conf public/