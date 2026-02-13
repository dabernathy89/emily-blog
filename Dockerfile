FROM dunglas/frankenphp:1.3-php8.4.1-bookworm AS base
ARG USER=www-data
ARG WWWGROUP=www-data

WORKDIR /app

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y git

# pcntl and sockets required by spatie fork
RUN install-php-extensions mbstring exif gd fileinfo pdo_sqlite pcntl sockets

RUN apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

RUN chown -R $USER:$WWWGROUP /app

FROM base AS dev

ENV NODE_VERSION=22.16.0

RUN mkdir /usr/local/nvm
ENV NVM_DIR=/usr/local/nvm

RUN curl https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.3/install.sh | bash \
    && . $NVM_DIR/nvm.sh \
    && nvm install $NODE_VERSION \
    && nvm alias default $NODE_VERSION \
    && nvm use default

ENV NODE_PATH=$NVM_DIR/v$NODE_VERSION/lib/node_modules
ENV PATH=$NVM_DIR/versions/node/v$NODE_VERSION/bin:$PATH

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN mkdir /.composer && \
    chmod -R ugo+rw /.composer && \
    chown -R "$USER" /.composer

ENV SERVER_NAME=:80

FROM node:22.16.0 AS node-build

WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM composer:2 AS composer-build

RUN apk add --no-cache linux-headers \
    && docker-php-ext-install pcntl sockets

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-dev --prefer-dist --optimize-autoloader --no-scripts
ENV SERVER_NAME=:80

FROM base AS production

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
ENV SERVER_NAME=:80

# Ensure www-data home directory exists and is writable (for git config)
RUN mkdir -p /var/www && chown $USER:$WWWGROUP /var/www

# Copy entrypoint script before switching user
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

USER ${USER}

COPY --chown=$USER:$WWWGROUP . /app

# Copy built assets from node stage
COPY --from=node-build --chown=$USER:$WWWGROUP /app/public/build /app/public/build

# Copy vendor dependencies from composer stage
COPY --from=composer-build --chown=$USER:$WWWGROUP /app/vendor /app/vendor

RUN mkdir -p /app/storage/logs \
    /app/storage/framework/cache \
    /app/storage/framework/sessions \
    /app/storage/framework/views \
    /app/bootstrap/cache \
    /app/database

RUN php /app/artisan package:discover --no-interaction

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
