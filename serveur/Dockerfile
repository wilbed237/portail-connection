# Application PHP (API de paiement, webhook, espace admin) et worker.
FROM php:8.3.12-apache-bookworm

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev \
 && docker-php-ext-install pdo_pgsql \
 && rm -rf /var/lib/apt/lists/* \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && printf 'expose_php = Off\nsession.cookie_httponly = 1\nsession.use_strict_mode = 1\n' > "$PHP_INI_DIR/conf.d/gandal.ini" \
 && printf 'ServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-enabled/gandal-security.conf \
 && sed -ri 's!/var/www/html!/var/www/app/public!g' /etc/apache2/sites-available/000-default.conf /etc/apache2/apache2.conf

COPY --chown=root:root . /var/www/app
WORKDIR /var/www/app
# Seul public/ est servi par Apache ; src/, bin/ et sql/ ne sont pas accessibles depuis le web.
