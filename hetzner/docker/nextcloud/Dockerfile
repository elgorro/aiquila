FROM nextcloud:33-apache

# Upgrade PHP to 8.4 (required by symfony/http-client ^8.0 in AIquila)
#
# nextcloud:33-apache ships source-compiled PHP 8.3 — NC33 recommends PHP 8.4.
# Debian Trixie packages PHP 8.4 in its main repo, but the base image pins all
# php* packages to prevent conflicts. We remove that pin, install PHP 8.4 from
# the official Debian Trixie repo, and switch Apache to use it.
RUN rm /etc/apt/preferences.d/no-debian-php \
    && apt-get update && apt-get install -y --no-install-recommends \
        gosu \
        libapache2-mod-php8.4 \
        php-apcu php-bcmath php-curl php-gd php-gmp \
        php-igbinary php-imagick php-intl php-ldap \
        php-mbstring php-memcached php-mysql \
        php-pgsql php-redis php-sqlite3 php-xml php-zip \
    # Switch Apache from the source-compiled PHP 8.3 module to apt PHP 8.4
    && a2dismod php \
    && a2enmod php8.4 \
    # Redirect the CLI php binary (occ uses /usr/local/bin/php)
    && ln -sf /usr/bin/php8.4 /usr/local/bin/php \
    # Carry over Nextcloud's custom PHP settings to the PHP 8.4 conf.d.
    && cp /usr/local/etc/php/conf.d/nextcloud.ini /etc/php/8.4/apache2/conf.d/nextcloud.ini \
    && cp /usr/local/etc/php/conf.d/nextcloud.ini /etc/php/8.4/cli/conf.d/nextcloud.ini \
    && cp /usr/local/etc/php/conf.d/opcache-recommended.ini /etc/php/8.4/apache2/conf.d/opcache-recommended.ini \
    && cp /usr/local/etc/php/conf.d/opcache-recommended.ini /etc/php/8.4/cli/conf.d/opcache-recommended.ini \
    && rm -rf /var/lib/apt/lists/*

# AIquila is installed at provisioning time via OCC (not baked into the image),
# so the tarball is downloaded and extracted on the server after first boot.
