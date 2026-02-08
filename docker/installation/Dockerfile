FROM nextcloud:32-apache

# Copy the pre-built tarball into a staging area
COPY aiquila.tar.gz /tmp/aiquila.tar.gz

# Copy custom Nextcloud configuration (Redis, apps_paths)
COPY custom.config.php /usr/src/nextcloud/config/custom.config.php

# Copy installation entrypoint
COPY entrypoint.sh /usr/local/bin/aiquila-install-entrypoint.sh
RUN chmod +x /usr/local/bin/aiquila-install-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/aiquila-install-entrypoint.sh"]
