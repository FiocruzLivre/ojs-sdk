FROM php:8.2-fpm

RUN apt-get update

RUN apt-get install -y \
        git \
        libicu-dev \
        libpq-dev \
        libzip-dev \
    && docker-php-ext-configure mysqli -with-mysqli=mysqlnd \
    && docker-php-ext-configure pdo_mysql -with-pdo-mysql=mysqlnd \
    && docker-php-ext-configure intl \
    && docker-php-ext-install gettext \
    && docker-php-ext-install intl \
    && docker-php-ext-install pdo pdo_mysql mysqli \
    && docker-php-ext-install zip

RUN pecl install xdebug-3.2.2 \
    && docker-php-ext-enable xdebug

RUN curl https://getcomposer.org/composer.phar --output /usr/bin/composer
RUN chmod +x /usr/bin/composer

WORKDIR /app
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
ENTRYPOINT ["bash", "/usr/local/bin/entrypoint.sh"]
