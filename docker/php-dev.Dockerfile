# Образ разработки: PHP 8.3.36, собранный из исходников.
#
# Почему не официальный образ: 21 октября 2026 года вышел PHP 8.3.36 — обновление
# безопасности, закрывающее CVE-2026-109618 и CVE-2026-109619 (повреждение состояния
# движка при аварийном выходе; заявлено возможное удалённое выполнение кода без
# аутентификации). Частичных мер защиты нет: обновление движка — единственный способ
# закрыть дыру, поэтому «просто подождём» не является решением. На момент написания
# официальные образы docker-library/php версии 8.3.36 ещё не опубликованы: в реестре
# лежит 8.3.35, то есть уязвимая версия. Образ ниже собирает нужную версию из
# исходников по официальному рецепту.
#
# Контрольная сумма исходников взята из репозитория официальных образов
# (docker-library/php, ветка 8.3/bookworm/cli): php-8.3.36.tar.xz —
# dd54693aa334c5c4d72e9aee96b082c2473750a18a6c1d105789c93a3b1692fd. Сборка падает,
# если файл не совпадает с этой суммой.
#
# Когда официальный образ 8.3.36 появится, этот файл можно заменить одной строкой
# FROM php:8.3.36-cli и вернуть сборку расширений через docker-php-ext-install.

FROM debian:bookworm-slim AS build

ENV PHP_VERSION 8.3.36
ENV PHP_URL "https://www.php.net/distributions/php-8.3.36.tar.xz"
ENV PHP_SHA256 "dd54693aa334c5c4d72e9aee96b082c2473750a18a6c1d105789c93a3b1692fd"
ENV PHP_INI_DIR /usr/local/etc/php

# Пакеты Debian'а с PHP запрещены: иначе в образ попадёт не та версия.
RUN set -eux; \
    { \
        echo 'Package: php*'; \
        echo 'Pin: release *'; \
        echo 'Pin-Priority: -1'; \
    } > /etc/apt/preferences.d/no-debian-php

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        autoconf \
        bison \
        ca-certificates \
        curl \
        dpkg-dev \
        file \
        g++ \
        gcc \
        libc-dev \
        libargon2-dev \
        libcurl4-openssl-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libreadline-dev \
        libsodium-dev \
        libsqlite3-dev \
        libssl-dev \
        libwebp-dev \
        libxml2-dev \
        libzip-dev \
        make \
        pkg-config \
        re2c \
        xz-utils \
        zlib1g-dev \
    ; \
    rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    mkdir -p /usr/src; \
    cd /usr/src; \
    curl -fsSL -o php.tar.xz "$PHP_URL"; \
    echo "$PHP_SHA256 *php.tar.xz" | sha256sum -c -; \
    tar -xf php.tar.xz; \
    rm php.tar.xz; \
    cd "php-$PHP_VERSION"

# Флаги сборки повторяют официальный образ (8.3/bookworm/cli) и добавляют то, что нужно
# проекту: GD с JPEG и WebP, zip, pdo_mysql. Отладочные SAPI (phpdbg, embed) и PEAR не
# собираются: в разработке и на обычном хостинге они не нужны, а сборка без них быстрее.
RUN set -eux; \
    export \
        CFLAGS="-fstack-protector-strong -fpic -fpie -O2 -D_LARGEFILE_SOURCE -D_FILE_OFFSET_BITS=64" \
        CPPFLAGS="-fstack-protector-strong -fpic -fpie -O2 -D_LARGEFILE_SOURCE -D_FILE_OFFSET_BITS=64" \
        LDFLAGS="-Wl,-O1 -pie" \
    ; \
    cd "/usr/src/php-$PHP_VERSION"; \
    ./configure \
        --build="$(dpkg-architecture --query DEB_BUILD_GNU_TYPE)" \
        --with-config-file-path=/usr/local/etc/php \
        --with-config-file-scan-dir=/usr/local/etc/php/conf.d \
        --enable-option-checking=fatal \
        --with-mhash \
        --with-pic \
        --enable-mbstring \
        --enable-mysqlnd \
        --with-pdo-mysql=mysqlnd \
        --with-password-argon2 \
        --with-sodium=shared \
        --with-pdo-sqlite=/usr \
        --with-sqlite3=/usr \
        --with-curl \
        --with-iconv \
        --with-openssl \
        --with-readline \
        --with-zlib \
        --enable-exif \
        --enable-gd --with-jpeg --with-webp \
        --with-zip \
        --enable-opcache \
        --disable-cgi \
        --without-pear \
    ; \
    make -j "$(nproc)"

# Установка отдельно от сборки: если этот шаг сорвётся, собранный PHP остаётся в кэше слоёв и
# повторная попытка не компилирует его заново.
RUN set -eux; \
    cd "/usr/src/php-$PHP_VERSION"; \
    mkdir -p "$PHP_INI_DIR"; \
    make install; \
    cp -v php.ini-* "$PHP_INI_DIR/"; \
    make clean

# Готовый PHP с настройками и расширениями: то, что нужно в образе разработки.
FROM debian:bookworm-slim

RUN set -eux; \
    { \
        echo 'Package: php*'; \
        echo 'Pin: release *'; \
        echo 'Pin-Priority: -1'; \
    } > /etc/apt/preferences.d/no-debian-php; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        git \
        libargon2-1 \
        libcurl4 \
        libjpeg62-turbo \
        libonig5 \
        libpng16-16 \
        libreadline8 \
        libsodium23 \
        libsqlite3-0 \
        libssl3 \
        libwebp7 \
        libxml2 \
        libzip4 \
        procps \
        unzip \
        zlib1g \
    ; \
    rm -rf /var/lib/apt/lists/*

COPY --from=build /usr/local /usr/local

ENV PHP_INI_DIR /usr/local/etc/php
RUN set -eux; \
    mkdir -p "$PHP_INI_DIR/conf.d"; \
    cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    printf 'extension=sodium\n' > "$PHP_INI_DIR/conf.d/docker-php-ext-sodium.ini"; \
    printf 'zend_extension=opcache\n' > "$PHP_INI_DIR/conf.d/docker-php-ext-opcache.ini"; \
    php --version

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app
