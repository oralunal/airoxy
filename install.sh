#!/bin/bash
set -e

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

export COMPOSER_ALLOW_SUPERUSER=1

INSTALL_PATH="/var/www/airoxy"

# Root check
if [ "$(id -u)" -ne 0 ]; then
    echo -e "${RED}Error: This script must be run as root.${NC}"
    exit 1
fi

echo -e "${GREEN}=== Airoxy Installer ===${NC}"
echo ""

# 1. Preflight checks
echo -e "${YELLOW}[1/7] Preflight checks...${NC}"

# Check Ubuntu
if ! grep -qi ubuntu /etc/os-release 2>/dev/null; then
    echo -e "${RED}Error: Ubuntu is required.${NC}"
    exit 1
fi

# Check PHP version
PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;' 2>/dev/null || echo "0")
PHP_MINOR=$(php -r 'echo PHP_MINOR_VERSION;' 2>/dev/null || echo "0")
if [ "$PHP_MAJOR" -lt 8 ] || { [ "$PHP_MAJOR" -eq 8 ] && [ "$PHP_MINOR" -lt 5 ]; }; then
    echo -e "${RED}Error: PHP 8.5+ is required (found: ${PHP_MAJOR}.${PHP_MINOR}).${NC}"
    exit 1
fi
PHP_VERSION="${PHP_MAJOR}.${PHP_MINOR}"
echo "  PHP $PHP_VERSION ✓"

# Check PHP extensions
for ext in curl mbstring sqlite3 openssl tokenizer xml; do
    if ! php -m | grep -qi "^$ext$"; then
        echo -e "${RED}Error: PHP extension '$ext' is missing.${NC}"
        exit 1
    fi
done
echo "  PHP extensions ✓"

# Check/install composer
if ! command -v composer &>/dev/null; then
    echo "  Installing Composer..."
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi
echo "  Composer ✓"

# Check/install supervisor
if ! command -v supervisorctl &>/dev/null; then
    echo "  Installing Supervisor..."
    apt-get update -qq && apt-get install -y -qq supervisor
fi
echo "  Supervisor ✓"

# Check git
if ! command -v git &>/dev/null; then
    echo -e "${RED}Error: git is required.${NC}"
    exit 1
fi
echo "  Git ✓"

# 2. Clone & setup
echo ""
echo -e "${YELLOW}[2/7] Cloning repository...${NC}"

git config --global --add safe.directory "$INSTALL_PATH" 2>/dev/null || true

if [ -d "$INSTALL_PATH" ]; then
    echo -e "${YELLOW}  $INSTALL_PATH already exists. Pulling latest...${NC}"
    cd "$INSTALL_PATH" && git pull
else
    git clone https://github.com/oralunal/airoxy.git "$INSTALL_PATH"
    cd "$INSTALL_PATH"
fi

echo ""
echo -e "${YELLOW}[3/7] Installing dependencies...${NC}"
composer install --no-dev --optimize-autoloader --no-interaction

if [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate --no-interaction
fi

touch database/database.sqlite
php artisan migrate --force --no-interaction

# 3. Install Octane/FrankenPHP
echo ""
echo -e "${YELLOW}[4/7] Setting up Octane + FrankenPHP...${NC}"
php artisan octane:install --server=frankenphp --no-interaction 2>/dev/null || true

# 4. Permissions
echo ""
echo -e "${YELLOW}[5/7] Setting permissions...${NC}"
chown -R www-data:www-data "$INSTALL_PATH"
chmod -R 775 storage bootstrap/cache database

# 5. Global alias
echo ""
echo -e "${YELLOW}[6/7] Installing global alias...${NC}"
cp "$INSTALL_PATH/bin/airoxy" /usr/local/bin/airoxy
chmod +x /usr/local/bin/airoxy
echo "  /usr/local/bin/airoxy ✓"

# 6. Supervisor
echo ""
echo -e "${YELLOW}[7/7] Configuring Supervisor...${NC}"
mkdir -p /var/log/airoxy
cp "$INSTALL_PATH/supervisor/airoxy-worker.conf" /etc/supervisor/conf.d/airoxy.conf
supervisorctl reread
supervisorctl update
echo "  Supervisor configured ✓"

# 7. Summary
echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  Airoxy installed successfully!${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo "  Next steps:"
echo "    1. Add API keys:    airoxy api-key:add YOUR_KEY --name='Key 1'"
echo "    2. Add tokens:      airoxy token:auto"
echo "    3. Start server:    supervisorctl start airoxy"
echo ""
echo "  Or start manually:    airoxy serve"
echo "  View logs:            airoxy logs"
echo "  View stats:           airoxy stats"
echo ""
