FROM php:8.1.5-fpm

WORKDIR /var/www

RUN apt-get update \
    && apt-get install -y \
    libmagickwand-dev \
    libzip-dev \
    libwebp-dev \
    libpq-dev
RUN docker-php-ext-install exif \
    && pecl install imagick -y \
    && docker-php-ext-enable imagick \
    && PHP_OPENSSL=yes \
    && docker-php-ext-install zip \
    && docker-php-ext-install bcmath \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install gd \
    && docker-php-ext-install intl \
    && docker-php-ext-install pdo \
    && docker-php-ext-install pdo_mysql \
    && docker-php-ext-install pdo_pgsql \
    && docker-php-ext-install pgsql \
    && docker-php-ext-install mysqli
RUN pecl install xdebug && docker-php-ext-enable xdebug

CMD ["php-fpm"]
