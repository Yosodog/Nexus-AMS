FROM composer:2.10.2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760 AS composer-binary

FROM node:22.23.1-bookworm-slim@sha256:6c74791e557ce11fc957704f6d4fe134a7bc8d6f5ca4403205b2966bd488f6b3 AS frontend

WORKDIR /build

COPY package.json package-lock.json vite.config.js ./

RUN npm ci --ignore-scripts --no-audit --no-fund

COPY resources ./resources
COPY public ./public

RUN npm run build

FROM php:8.3-apache-bookworm@sha256:0540815262141e96282c4734c7c3b8b87733fd97e98d9688a9eadcaeb2adcf88 AS php-runtime

RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
        tini; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j "$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        posix \
        zip; \
    pecl download redis-6.3.0; \
    echo '0d5141f634bd1db6c1ddcda053d25ecf2c4fc1c395430d534fd3f8d51dd7f0b5  redis-6.3.0.tgz' | sha256sum --check --strict; \
    printf '\n' | pecl install ./redis-6.3.0.tgz; \
    rm redis-6.3.0.tgz; \
    docker-php-ext-enable redis; \
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $savedAptMark; \
    apt-mark manual tini; \
    find "$(php-config --extension-dir)" -type f -name '*.so' -exec ldd '{}' ';' \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); print so }' \
        | sort -u \
        | xargs -r dpkg-query --search \
        | cut -d: -f1 \
        | sort -u \
        | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/* /tmp/pear; \
    mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    php -r 'foreach (["bcmath", "gd", "intl", "pcntl", "pdo_mysql", "posix", "redis", "zip"] as $extension) { if (! extension_loaded($extension)) { fwrite(STDERR, "Missing required PHP extension.\n"); exit(1); } }'

COPY docker/runtime/php.ini /usr/local/etc/php/conf.d/zz-nexus-production.ini
COPY docker/runtime/apache-vhost.conf /etc/apache2/sites-available/nexus.conf
COPY docker/runtime/apache-security.conf /etc/apache2/conf-available/nexus-security.conf

RUN set -eux; \
    a2enmod headers rewrite; \
    a2dissite 000-default; \
    a2ensite nexus; \
    a2enconf nexus-security; \
    sed -ri 's!Listen 80!Listen 8080!g' /etc/apache2/ports.conf; \
    sed -ri 's!^export APACHE_PID_FILE=.*!export APACHE_PID_FILE=/tmp/nexus-apache/apache2.pid!' /etc/apache2/envvars; \
    sed -ri 's!^export APACHE_RUN_DIR=.*!export APACHE_RUN_DIR=/tmp/nexus-apache!' /etc/apache2/envvars; \
    sed -ri 's!^export APACHE_LOCK_DIR=.*!export APACHE_LOCK_DIR=/tmp/nexus-apache!' /etc/apache2/envvars; \
    sed -ri 's!^export APACHE_LOG_DIR=.*!export APACHE_LOG_DIR=/tmp/nexus-apache!' /etc/apache2/envvars

FROM php-runtime AS application-build

COPY --from=composer-binary /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./

RUN COMPOSER_CACHE_DIR=/tmp/composer-cache composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --no-scripts \
        --no-autoloader

COPY app ./app
COPY bootstrap/app.php bootstrap/providers.php ./bootstrap/
COPY config ./config
COPY database ./database
COPY docker ./docker
COPY public ./public
COPY resources ./resources
COPY routes ./routes
COPY artisan package-lock.json LICENSE ./

RUN mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs; \
    COMPOSER_CACHE_DIR=/tmp/composer-cache composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --classmap-authoritative; \
    composer check-platform-reqs --no-dev

FROM php-runtime AS production

ARG NEXUS_APPLICATION_VERSION
ARG NEXUS_COMMIT_SHA

LABEL org.opencontainers.image.title="Nexus AMS" \
      org.opencontainers.image.description="Immutable Nexus AMS web and worker runtime" \
      org.opencontainers.image.source="https://github.com/Yosodog/Nexus-AMS" \
      org.opencontainers.image.version="${NEXUS_APPLICATION_VERSION}" \
      org.opencontainers.image.revision="${NEXUS_COMMIT_SHA}" \
      org.opencontainers.image.licenses="GPL-3.0-only" \
      org.opencontainers.image.base.name="docker.io/library/php:8.3-apache-bookworm" \
      org.opencontainers.image.base.digest="sha256:0540815262141e96282c4734c7c3b8b87733fd97e98d9688a9eadcaeb2adcf88" \
      io.nexus.runtime.roles="web,queue,scheduler,migrator,bootstrap,event-consumer" \
      io.nexus.runtime.build-metadata="/usr/share/nexus/build.json" \
      io.nexus.runtime.sbom="/usr/share/nexus/sbom/nexus-ams.cdx.json" \
      io.nexus.runtime.stop-grace-period="960s"

ENV APP_ENV=production \
    APP_DEBUG=false \
    APACHE_LOCK_DIR=/tmp/nexus-apache \
    APACHE_LOG_DIR=/tmp/nexus-apache \
    APACHE_PID_FILE=/tmp/nexus-apache/apache2.pid \
    APACHE_RUN_DIR=/tmp/nexus-apache \
    HOME=/tmp \
    NEXUS_APPLICATION_VERSION="${NEXUS_APPLICATION_VERSION}" \
    NEXUS_COMMIT_SHA="${NEXUS_COMMIT_SHA}"

WORKDIR /var/www/html

COPY --from=application-build --chown=root:root /var/www/html /var/www/html
COPY --from=frontend --chown=root:root /build/public/build /var/www/html/public/build

RUN set -eux; \
    printf '%s' "$NEXUS_APPLICATION_VERSION" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._+-]{0,63}$'; \
    printf '%s' "$NEXUS_COMMIT_SHA" | grep -Eq '^[a-f0-9]{40}$'; \
    mkdir -p \
        /usr/share/nexus \
        /usr/share/nexus/sbom \
        bootstrap/cache \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs; \
    php docker/build/generate-build-metadata.php \
        --output=/usr/share/nexus/build.json \
        --version="$NEXUS_APPLICATION_VERSION" \
        --commit="$NEXUS_COMMIT_SHA"; \
    php docker/build/generate-sbom.php \
        --output=/usr/share/nexus/sbom/nexus-ams.cdx.json \
        --version="$NEXUS_APPLICATION_VERSION" \
        --commit="$NEXUS_COMMIT_SHA" \
        --base-image="docker.io/library/php:8.3-apache-bookworm@sha256:0540815262141e96282c4734c7c3b8b87733fd97e98d9688a9eadcaeb2adcf88"; \
    chmod 0755 docker/runtime/entrypoint.php docker/runtime/healthcheck.php; \
    if [ ! -e public/storage ] && [ ! -L public/storage ]; then ln -s ../storage/app/public public/storage; fi; \
    test "$(id -u www-data)" = 33; \
    test "$(id -g www-data)" = 33; \
    chown -R www-data:www-data bootstrap/cache storage; \
    find bootstrap/cache storage -type d -exec chmod 0770 '{}' +; \
    find bootstrap/cache storage -type f -exec chmod 0660 '{}' +; \
    test -f public/build/manifest.json; \
    test -f /usr/share/nexus/build.json; \
    test -f /usr/share/nexus/sbom/nexus-ams.cdx.json

USER www-data:www-data

EXPOSE 8080

STOPSIGNAL SIGTERM

HEALTHCHECK --interval=15s --timeout=3s --start-period=30s --retries=3 \
    CMD ["php", "/var/www/html/docker/runtime/healthcheck.php"]

ENTRYPOINT ["/usr/bin/tini", "--", "php", "/var/www/html/docker/runtime/entrypoint.php"]
CMD ["web"]
