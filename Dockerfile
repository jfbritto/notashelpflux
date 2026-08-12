FROM php:8.4-fpm

RUN apt-get update && apt-get install -y \
    git \
    curl \
    libzip-dev \
    zip \
    unzip \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    zip \
    bcmath

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# As dependências entram pelo volume em desenvolvimento (docker-compose monta o
# diretório inteiro). Este Dockerfile é o ambiente, não a imagem de produção.

CMD ["php-fpm"]
