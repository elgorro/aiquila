FROM nextcloud:32-apache

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# Install dependencies needed for Composer and PHP packages
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Set Composer environment to avoid prompts
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_NO_INTERACTION=1

# Verify Composer installation
RUN composer --version
