FROM php:8.3-cli-alpine

RUN apk add --no-cache postgresql-libs \
 && apk add --no-cache --virtual .build-deps \
      $PHPIZE_DEPS postgresql-dev oniguruma-dev \
 && docker-php-ext-install pdo_pgsql mbstring \
 && apk del .build-deps

ENV PHP_CLI_SERVER_WORKERS=4
WORKDIR /app
COPY . /app

EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
