FROM php:8.5-apache

# OS deps (now includes libwebp-dev + build-essential)
RUN apt-get update && apt-get install -y \
    git \
    zip \
    unzip \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libwebp-dev \
    build-essential \
 && rm -rf /var/lib/apt/lists/*

# Configure GD with WebP (plus JPEG & Freetype)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp
RUN docker-php-ext-install -j"$(nproc)" pdo pdo_mysql gd zip pcntl sockets

COPY . /var/www/html
COPY .env.example /var/www/html/.env
RUN chown -R www-data:www-data /var/www/html
RUN a2enmod rewrite

WORKDIR /var/www/html

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN composer install
RUN php artisan key:generate
