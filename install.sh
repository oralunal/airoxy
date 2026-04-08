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

APT_UPDATED=0

# Check/install git
if ! command -v git &>/dev/null; then
    echo "  Installing Git..."
    apt-get update -qq && APT_UPDATED=1
    apt-get install -y -qq git
fi
echo "  Git ✓"

# Check/install PHP 8.5+
PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;' 2>/dev/null || echo "0")
PHP_MINOR=$(php -r 'echo PHP_MINOR_VERSION;' 2>/dev/null || echo "0")
if [ "$PHP_MAJOR" -lt 8 ] || { [ "$PHP_MAJOR" -eq 8 ] && [ "$PHP_MINOR" -lt 5 ]; }; then
    echo "  Installing PHP 8.5..."
    if [ "$APT_UPDATED" -eq 0 ]; then apt-get update -qq && APT_UPDATED=1; fi
    apt-get install -y -qq software-properties-common
    add-apt-repository -y ppa:ondrej/php
    apt-get update -qq
    apt-get install -y -qq php8.5-cli php8.5-curl php8.5-mbstring php8.5-sqlite3 php8.5-xml php8.5-zip
    PHP_MAJOR=8
    PHP_MINOR=5
fi
PHP_VERSION="${PHP_MAJOR}.${PHP_MINOR}"
echo "  PHP $PHP_VERSION ✓"

# Check PHP extensions
MISSING_EXTS=""
for ext in curl mbstring sqlite3 openssl tokenizer xml; do
    if ! php -m | grep -qi "^$ext$"; then
        MISSING_EXTS="$MISSING_EXTS php${PHP_VERSION}-$ext"
    fi
done
if [ -n "$MISSING_EXTS" ]; then
    echo "  Installing missing PHP extensions..."
    if [ "$APT_UPDATED" -eq 0 ]; then apt-get update -qq && APT_UPDATED=1; fi
    apt-get install -y -qq $MISSING_EXTS
fi
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
    if [ "$APT_UPDATED" -eq 0 ]; then apt-get update -qq && APT_UPDATED=1; fi
    apt-get install -y -qq supervisor
fi
echo "  Supervisor ✓"

# 2. Clone & setup
echo ""
echo -e "${YELLOW}[2/7] Cloning repository...${NC}"

git config --global --add safe.directory "$INSTALL_PATH" 2>/dev/null || true

REPO_URL="https://github.com/oralunal/airoxy.git"
LATEST_TAG=$(git ls-remote --tags --sort=-v:refname "$REPO_URL" 'refs/tags/v*' 2>/dev/null | head -n 1 | sed 's|.*refs/tags/||')

if [ -z "$LATEST_TAG" ]; then
    echo -e "${YELLOW}  No release tags found. Using main branch.${NC}"
    LATEST_TAG=""
fi

if [ -d "$INSTALL_PATH" ]; then
    echo -e "${YELLOW}  $INSTALL_PATH already exists. Updating...${NC}"
    cd "$INSTALL_PATH"
    git fetch origin --tags
    if [ -n "$LATEST_TAG" ]; then
        git checkout "$LATEST_TAG" --force
    else
        git reset --hard origin/main
    fi
else
    if [ -n "$LATEST_TAG" ]; then
        echo "  Installing release ${LATEST_TAG}..."
        git clone --branch "$LATEST_TAG" "$REPO_URL" "$INSTALL_PATH"
    else
        git clone "$REPO_URL" "$INSTALL_PATH"
    fi
    cd "$INSTALL_PATH"
fi

if [ -n "$LATEST_TAG" ]; then
    echo "  Release: ${LATEST_TAG} ✓"
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
cp "$INSTALL_PATH/logrotate/airoxy" /etc/logrotate.d/airoxy
echo "  Supervisor configured ✓"
echo "  Logrotate configured ✓"

# 7. Summary
echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  Airoxy installed successfully!${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo "  Next steps:"
echo "    1. Add tokens:      airoxy token:auto"
echo "    2. Add API keys:    airoxy api-key:add --name='My App'"
echo "    3. Start server:    airoxy start"
echo ""
echo "  View logs:            airoxy logs"
echo "  View stats:           airoxy stats"
echo "  Check health:         airoxy doctor"
echo ""
