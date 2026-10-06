#!/usr/bin/env bash
# scripts/00-laravel-deploy.sh
#
# The richarvey/nginx-php-fpm base image (see Dockerfile) automatically
# runs any script matching scripts/*.sh on container startup when
# RUN_SCRIPTS=1 is set. This means migrations run on every deploy
# automatically on Render — unlike Railway, where this had to be run by
# hand each time in the Console tab (php artisan migrate --force).

php artisan config:cache
php artisan route:cache
php artisan migrate --force
