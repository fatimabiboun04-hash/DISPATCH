FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    zip \
    unzip \
    sqlite3 \
    libsqlite3-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libzip-dev \
    mariadb-client-compat \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install pdo pdo_mysql pdo_sqlite bcmath gd zip && \
    pecl install redis && \
    docker-php-ext-enable redis

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Set working directory
WORKDIR /app

# Copy application files
COPY . .

# Install PHP dependencies with platform reqs ignored
RUN composer install --no-dev --no-interaction --prefer-dist --ignore-platform-req=php || true

# Install Node.js
RUN curl -fsSL https://deb.nodesource.com/setup_18.x | bash - && apt-get install -y nodejs && rm -rf /var/lib/apt/lists/*

# Install NPM dependencies
RUN npm install

# Build frontend assets
RUN npm run build || true

# Set permissions
RUN chown -R www-data:www-data /app && chmod -R 755 /app/storage

# Run PHP-FPM
CMD ["php-fpm"]

