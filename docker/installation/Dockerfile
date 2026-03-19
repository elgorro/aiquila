FROM nextcloud:33-apache

# Copy the pre-built tarball into a staging area
COPY aiquila.tar.gz /tmp/aiquila.tar.gz

# Copy custom Nextcloud configuration (Redis, apps_paths)
COPY custom.config.php /usr/src/nextcloud/config/custom.config.php

# Register the AIquila installation as a Nextcloud post-installation hook.
# The hook runs after Nextcloud initialises (but before Apache starts serving),
# so occ commands work without needing to poll for a live web server.
RUN mkdir -p /docker-entrypoint-hooks.d/post-installation
COPY aiquila-install-hook.sh /docker-entrypoint-hooks.d/post-installation/aiquila-install.sh
RUN chmod +x /docker-entrypoint-hooks.d/post-installation/aiquila-install.sh

# Use the default Nextcloud entrypoint — it handles PHP ini env-var substitution,
# Redis, DB auto-configuration, and hook execution automatically.
