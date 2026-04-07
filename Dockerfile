FROM dunglas/frankenphp:1.12.1-php8-alpine

# 1. Install only what you actually need
# Removed: nginx, git, curl (already in base), dos2unix (try to fix line endings in git instead)
RUN apk add --no-cache \
    bash \
    docker-cli \
    sudo \
    icu-dev \
    libxml2-dev

# 2. Optimized Extension Install
RUN install-php-extensions pdo pdo_sqlite intl opcache

# 3. Use the FrankenPHP-specific path
WORKDIR /app

# 4. App Config
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && { \
        echo "opcache.enable=1"; \
        echo "opcache.memory_consumption=32"; \
        echo "opcache.interned_strings_buffer=4"; \
        echo "opcache.max_accelerated_files=10000"; \
        echo "opcache.validate_timestamps=1"; \
    } > /usr/local/etc/php/conf.d/dockyard-opcache.ini

# echo "opcache.validate_timestamps=0"; \

# 5. Fix the Docker group issue properly
# We create the group and allow the default internal user to run the script via sudo
RUN addgroup -S docker && addgroup root docker \
    && echo "root ALL=(ALL) NOPASSWD: /app/private/manage_containers.sh" > /etc/sudoers.d/manage-containers \
    && chmod 0440 /etc/sudoers.d/manage-containers

# 6. Setup data dirs
RUN mkdir -p /app/data /app/public/logs \
    && touch /app/data/db.sqlite

# 7. Copy Source
COPY ./src /app
RUN chmod +x /app/private/manage_containers.sh

# FrankenPHP Envs
ENV SERVER_NAME=:80
ENV SERVER_ROOT=/app/public

# No need for entrypoint.sh to start two services anymore!
# FrankenPHP handles the server start automatically.