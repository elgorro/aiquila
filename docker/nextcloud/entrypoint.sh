#!/bin/bash
set -e

echo "🚀 AIquila Custom Entrypoint Starting..."

# Run the original Nextcloud entrypoint in the background
/entrypoint.sh apache2-foreground &
APACHE_PID=$!

# Wait for Nextcloud to be ready
echo "⏳ Waiting for Nextcloud to initialize..."
until curl -s http://localhost/status.php > /dev/null 2>&1; do
    sleep 2
done

echo "✅ Nextcloud is ready!"

# Function to run occ commands
run_occ() {
    su -p www-data -s /bin/sh -c "php /var/www/html/occ $*"
}

# Wait a bit more for complete initialization
sleep 5

# Check if AIquila app is already enabled
if ! run_occ app:list | grep -q "aiquila"; then
    echo "📦 Enabling AIquila app..."
    run_occ app:enable aiquila || echo "⚠️  Could not enable AIquila (might not be installed yet)"
fi

# Create test user if it doesn't exist
if [ -n "$AIQUILA_TEST_USER" ] && [ -n "$AIQUILA_TEST_PASSWORD" ]; then
    if ! run_occ user:list | grep -q "$AIQUILA_TEST_USER"; then
        echo "👤 Creating test user: $AIQUILA_TEST_USER"
        run_occ user:add --password-from-env --display-name="Test User" --group="users" "$AIQUILA_TEST_USER" <<< "$AIQUILA_TEST_PASSWORD" || echo "⚠️  Could not create test user"
    else
        echo "✅ Test user already exists: $AIQUILA_TEST_USER"
    fi
fi

# Configure Claude API key if provided
if [ -n "$AIQUILA_CLAUDE_API_KEY" ]; then
    echo "🔑 Configuring Claude API key..."
    run_occ config:app:set aiquila api_key --value="$AIQUILA_CLAUDE_API_KEY" || echo "⚠️  Could not set API key"
fi

# Set recommended Nextcloud settings for development
echo "⚙️  Applying recommended development settings..."
run_occ config:system:set debug --value=true --type=boolean || true
run_occ config:system:set loglevel --value=0 --type=integer || true

# Configure mail settings (MailHog)
echo "📧 Configuring mail settings for MailHog..."
run_occ config:system:set mail_smtpmode --value=smtp || true
run_occ config:system:set mail_smtphost --value=mailhog || true
run_occ config:system:set mail_smtpport --value=1025 --type=integer || true
run_occ config:system:set mail_from_address --value=noreply || true
run_occ config:system:set mail_domain --value=aiquila.local || true

echo "✨ AIquila setup complete!"
echo ""
echo "🌐 Access Nextcloud at: http://localhost:8080"
echo "👤 Admin user: ${NEXTCLOUD_ADMIN_USER:-admin}"
echo "👤 Test user: ${AIQUILA_TEST_USER:-testuser}"
echo "📧 MailHog UI: http://localhost:8025"
echo "🗄️  Adminer UI: http://localhost:8081"
echo ""

# Keep the script running and wait for Apache
wait $APACHE_PID
