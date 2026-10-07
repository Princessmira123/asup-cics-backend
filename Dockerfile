# Dockerfile — only needed for deploying on Render (or anywhere else that
# builds via Docker). Railway doesn't need this at all — it auto-detects
# and builds PHP/Laravel apps on its own, which is why this file never
# existed before now. Render's supported path for PHP specifically goes
# through Docker, so this gives it a straightforward, known-working image
# (richarvey/nginx-php-fpm — a widely used base image that bundles nginx,
# PHP-FPM, and Laravel-aware startup scripts in one container, commonly
# used for exactly this Render/Heroku-style deployment).

FROM richarvey/nginx-php-fpm:3.1.6

COPY . .

# Laravel-specific settings this base image reads on startup.
ENV SKIP_COMPOSER=0
ENV WEBROOT=/var/www/html/public
ENV PHP_ERRORS_STDERR=1
ENV RUN_SCRIPTS=1
ENV REAL_IP_HEADER=1

# Run migrations on every deploy automatically, so you never have to
# remember to do it by hand in a console tab the way Railway needed.
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-dev --optimize-autoloader

CMD ["/start.sh"]
