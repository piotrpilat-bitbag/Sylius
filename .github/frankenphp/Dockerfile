# Obraz serwujący aplikację w trybie worker podczas joba CI.
#
# Goły dunglas/frankenphp nie wystarcza: niesie minimalny PHP bez pdo_mysql,
# przez co aplikacja wstaje i zwraca 500 na każde żądanie z komunikatem
# "could not find driver" — serwer wygląda zdrowo, a sledztwo idzie w zla strone.
#
# Lista rozszerzeń jest tą samą, której wymaga sylius/sylius w composer.json.
# install-php-extensions jest częścią obrazu FrankenPHP.

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
