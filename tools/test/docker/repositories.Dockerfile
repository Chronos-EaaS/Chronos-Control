ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli
RUN apt-get update \
    && apt-get install -y --no-install-recommends git mercurial libonig-dev libsqlite3-dev \
    && chronos_extensions="" \
    && for chronos_extension in mbstring pdo_sqlite; do \
         if ! php -r 'exit(extension_loaded($argv[1]) ? 0 : 1);' "$chronos_extension"; then \
           chronos_extensions="$chronos_extensions $chronos_extension"; \
         fi; \
       done \
    && if [ -n "$chronos_extensions" ]; then docker-php-ext-install $chronos_extensions; fi \
    && rm -rf /var/lib/apt/lists/*
COPY backend /opt/chronos/backend
USER www-data
WORKDIR /opt/chronos/backend
ENTRYPOINT ["php", "/opt/chronos/backend/tests/integration/system-repositories.php"]
