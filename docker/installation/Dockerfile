FROM nextcloud:34-apache

# Copy the pre-built tarball into a staging area
COPY aiquila.tar.gz /tmp/aiquila.tar.gz

# Copy custom Nextcloud configuration (Redis, apps_paths)
COPY custom.config.php /usr/src/nextcloud/config/custom.config.php

# Keep Server-Sent Events uncompressed. Compression buffers a response, and a
# buffered chat stream arrives all at once instead of as it is written.
# deflate.conf selects by response content type, which cannot be matched before
# the response exists; an SSE client announces itself in the request instead, so
# that is what this keys on.
RUN printf '%s\n' \
    '<IfModule mod_deflate.c>' \
    '  SetEnvIfNoCase Accept text/event-stream no-gzip=1 dont-vary=1' \
    '</IfModule>' \
    > /etc/apache2/conf-available/aiquila-sse.conf \
 && a2enconf aiquila-sse

# Register the AIquila installation as a Nextcloud post-installation hook.
# The hook runs after Nextcloud initialises (but before Apache starts serving),
# so occ commands work without needing to poll for a live web server.
RUN mkdir -p /docker-entrypoint-hooks.d/post-installation
COPY aiquila-install-hook.sh /docker-entrypoint-hooks.d/post-installation/aiquila-install.sh
RUN chmod +x /docker-entrypoint-hooks.d/post-installation/aiquila-install.sh

# Use the default Nextcloud entrypoint — it handles PHP ini env-var substitution,
# Redis, DB auto-configuration, and hook execution automatically.
