ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-apache AS test-runtime

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev libxml2-dev libzip-dev libcurl4-openssl-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && chronos_extensions="" \
    && for chronos_extension in pdo_mysql mbstring dom zip curl gd; do \
         if ! php -r 'exit(extension_loaded($argv[1]) ? 0 : 1);' "$chronos_extension"; then \
           chronos_extensions="$chronos_extensions $chronos_extension"; \
         fi; \
       done \
    && if [ -n "$chronos_extensions" ]; then docker-php-ext-install $chronos_extensions; fi \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

FROM test-runtime AS test-dependencies
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock /opt/chronos/
WORKDIR /opt/chronos
RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts

FROM test-runtime AS test-web
COPY api /opt/chronos/api
COPY core /opt/chronos/core
COPY libraries /opt/chronos/libraries
COPY webroot /opt/chronos/webroot
COPY constants.php config.template.php chronos.sql /opt/chronos/
COPY tests/docker /opt/chronos/tests/docker
COPY tests/fixtures /opt/chronos/tests/fixtures
COPY tests/unit /opt/chronos/tests/unit
COPY tests/integration /opt/chronos/tests/integration
COPY tests/bootstrap.php /opt/chronos/tests/bootstrap.php
COPY phpunit.xml.dist /opt/chronos/phpunit.xml.dist
COPY --from=test-dependencies /opt/chronos/vendor /opt/chronos/vendor

RUN cp /opt/chronos/tests/docker/apache.conf /etc/apache2/sites-available/000-default.conf \
    && sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && cp /opt/chronos/tests/docker/php.ini /usr/local/etc/php/conf.d/chronos-test.ini \
    && cp /opt/chronos/tests/docker/command-stub /usr/local/bin/git \
    && cp /opt/chronos/tests/docker/command-stub /usr/local/bin/hg \
    && cp /opt/chronos/tests/docker/command-stub /usr/local/bin/sudo \
    && chmod 755 /usr/local/bin/git /usr/local/bin/hg /usr/local/bin/sudo \
    && mkdir -p /opt/chronos/logs /opt/chronos/locks /opt/chronos/temp \
       /opt/chronos/webroot/data/evaluation /opt/chronos/webroot/data/log \
       /opt/chronos/webroot/media /opt/chronos/webroot/systems \
       /opt/chronos/tests/runtime/sessions \
       /var/run/apache2 /var/lock/apache2 /var/log/apache2 \
    && chown -R www-data:www-data /opt/chronos /var/run/apache2 /var/lock/apache2 /var/log/apache2

USER www-data
WORKDIR /opt/chronos
EXPOSE 8080
ENTRYPOINT ["/bin/sh", "/opt/chronos/tests/docker/entrypoint.sh"]

FROM test-web AS v3-test-web
COPY frontend/dist/chronos-ui/browser /opt/chronos/webroot/ui
COPY install.php /opt/chronos/install.php
COPY tests/deployment /opt/chronos/tests/deployment
USER root
RUN chown -R www-data:www-data /opt/chronos/webroot/ui /opt/chronos/tests/deployment
USER www-data
