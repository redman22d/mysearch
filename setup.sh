#!/bin/bash
set -e

echo "=== Base44 Setup ==="

# Wait for wp-config.php (created by the wordpress container's entrypoint)
echo "Waiting for wp-config.php..."
for i in $(seq 1 60); do
    [ -f /var/www/html/wp-config.php ] && break
    sleep 2
done
if [ ! -f /var/www/html/wp-config.php ]; then
    echo "ERROR: wp-config.php not found after 120s"
    exit 1
fi

# Install WordPress if not already installed
if ! wp core is-installed --path=/var/www/html 2>/dev/null; then
    echo "Installing WordPress..."
    wp core install \
        --url="https://3000-${BASE44_PUBLIC_HOST_SUFFIX}" \
        --title="Tutor Course Search Pro" \
        --admin_user=admin \
        --admin_password=admin \
        --admin_email=admin@example.com \
        --skip-email \
        --path=/var/www/html
else
    echo "WordPress already installed."
fi

# Install and activate Tutor LMS (free, from WordPress.org)
if ! wp plugin is-installed tutor --path=/var/www/html 2>/dev/null; then
    echo "Installing Tutor LMS..."
    for i in $(seq 1 3); do
        wp plugin install tutor --path=/var/www/html && break
        echo "Retrying Tutor LMS installation (attempt $i)..."
        sleep 5
    done
fi
echo "Activating Tutor LMS..."
wp plugin activate tutor --path=/var/www/html

# Activate our plugin
echo "Activating Tutor Course Search Pro..."
wp plugin activate tutor-course-search-pro --path=/var/www/html

# Flush rewrite rules so the course archive URL works
echo "Flushing rewrite rules..."
wp rewrite flush --path=/var/www/html

# Create sample courses, categories, tags, ratings, enrollments
echo "Creating sample data..."
wp eval-file /setup-data.php --path=/var/www/html

echo "=== Setup complete ==="
