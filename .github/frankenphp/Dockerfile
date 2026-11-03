
ARG FRANKENPHP_VERSION=1.13
ARG PHP_VERSION=8.4

FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION}

RUN install-php-extensions \
        intl \
        pdo_mysql \
        zip \
        gd \
        exif \
        opcache \
        bcmath \
        sodium

RUN mkdir -p /data /config && chmod 777 /data /config
