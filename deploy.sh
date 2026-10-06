#!/bin/bash
# 兰台观局 一键部署脚本
# 用法: sudo ./deploy.sh [配置文件路径]
# 支持: Ubuntu 20.04+/22.04/24.04, Debian 11+, CentOS Stream 8/9, RHEL 8/9

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SITE_DIR="$PROJECT_DIR/site"
PIPELINE_DIR="$PROJECT_DIR/pipeline"
WEB_ROOT="/var/www/lantai"
PYTHON_BIN="/usr/bin/python3"

# 用户配置（可由 config.sh 覆盖）
DOMAIN="localhost"
DB_NAME="lantai"
DB_USER="lantai"
DB_PASS="lantai_password"
DB_ROOT_PASS=""
LLM_API_KEY=""
LLM_BASE_URL=""
LLM_MODEL=""

# OS 派生变量（由 detect_os 填充）
OS_ID=""
OS_VERSION=""
PKG_MGR=""
DB_PKG=""
DB_SERVICE=""
WEB_USER=""
NGINX_CONF_FILE=""
FIREWALL_TOOL=""

# PHP-FPM 运行时探测（由 setup_php_fpm 填充）
PHP_FPM_SERVICE=""
PHP_FPM_SOCK=""

log() { echo -e "${GREEN}[✓]$NC $1"; }
warn() { echo -e "${YELLOW}[!]$NC $1"; }
error() { echo -e "${RED}[✗]$NC $1" >&2; exit 1; }
info() { echo -e "${BLUE}[i]$NC $1"; }

check_root() {
    [ "$EUID" -eq 0 ] || error "请使用 sudo 或 root 运行"
}

detect_os() {
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        OS_ID=$ID
        OS_VERSION=$VERSION_ID
    elif [ -f /etc/redhat-release ]; then
        OS_ID="centos"
        OS_VERSION=$(grep -oE '[0-9]+\.[0-9]+' /etc/redhat-release | head -1)
    else
        error "无法识别操作系统"
    fi

    case "$OS_ID" in
        ubuntu|debian)
            PKG_MGR="apt"
            DB_PKG="mysql-server"
            DB_SERVICE="mysql"
            WEB_USER="www-data"
            NGINX_CONF_FILE="/etc/nginx/sites-available/lantai"
            FIREWALL_TOOL="ufw"
            ;;
        centos|rhel|rocky|almalinux)
            PKG_MGR="yum"
            DB_PKG="mariadb-server"
            DB_SERVICE="mariadb"
            WEB_USER="nginx"
            NGINX_CONF_FILE="/etc/nginx/conf.d/lantai.conf"
            FIREWALL_TOOL="firewalld"
            ;;
        *)
            error "不支持的操作系统: $OS_ID"
            ;;
    esac

    info "OS: $OS_ID $OS_VERSION · 包管理: $PKG_MGR"
}

install_packages() {
    info "安装系统依赖..."
    case "$PKG_MGR" in
        apt)
            apt-get update -y
            apt-get install -y \
                php php-fpm php-mysql php-xml php-curl php-mbstring \
                php-zip php-gd php-bcmath php-intl php-sqlite3 \
                nginx "$DB_PKG" rsync git curl wget unzip \
                python3 python3-pip
            ;;
        yum)
            yum install -y epel-release
            yum module enable php:8.2 -y 2>/dev/null || true
            yum install -y \
                php php-cli php-fpm php-mysqlnd php-xml php-curl php-mbstring \
                php-zip php-gd php-bcmath php-intl php-opcache php-process php-sockets \
                nginx "$DB_PKG" rsync git curl wget unzip \
                python3 python3-pip
            ;;
    esac
    log "系统依赖安装完成"

    # 校验 PHP 版本（Laravel 11 要求 8.2+）
    local php_major php_minor
    php_major=$(php -r 'echo PHP_MAJOR_VERSION;' 2>/dev/null || echo 0)
    php_minor=$(php -r 'echo PHP_MINOR_VERSION;' 2>/dev/null || echo 0)
    if [ "$php_major" -lt 8 ] || { [ "$php_major" -eq 8 ] && [ "$php_minor" -lt 2 ]; }; then
        warn "PHP $php_major.$php_minor < 8.2，Laravel 11 可能无法运行。CentOS 8 可启用 Remi repo。"
    else
        info "PHP $php_major.$php_minor ✓"
    fi
}

install_composer() {
    if command -v composer &> /dev/null; then
        info "Composer 已安装: $(composer --version 2>&1 | head -1)"
        return
    fi
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
    log "Composer 安装完成"
}

install_nodejs() {
    if command -v node &> /dev/null; then
        local ver
        ver=$(node --version | grep -oE '[0-9]+' | head -1)
        if [ "$ver" -ge 18 ]; then
            info "Node.js: $(node --version)"
            return
        fi
    fi
    info "安装 Node.js 22.x..."
    case "$PKG_MGR" in
        apt)
            curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
            apt-get install -y nodejs
            ;;
        yum)
            curl -fsSL https://rpm.nodesource.com/setup_22.x | bash -
            yum install -y nodejs
            ;;
    esac
    log "Node.js: $(node --version)"
}

copy_project() {
    info "复制项目到 $WEB_ROOT..."
    mkdir -p "$WEB_ROOT/site" "$WEB_ROOT/pipeline"
    rsync -a --delete \
        --exclude='vendor/' --exclude='node_modules/' \
        --exclude='.env' \
        --exclude='storage/framework/' --exclude='storage/logs/*' \
        --exclude='bootstrap/cache/*' \
        --exclude='data/' \
        "$SITE_DIR/" "$WEB_ROOT/site/"
    rsync -a --delete \
        --exclude='__pycache__/' --exclude='.pytest_cache/' \
        --exclude='data/' \
        "$PIPELINE_DIR/" "$WEB_ROOT/pipeline/"
    log "项目文件复制完成"
}

# 最小 .env：让 composer install 阶段的 artisan package:discover 能启动
init_env() {
    cat > "$WEB_ROOT/site/.env" << 'EOF'
APP_KEY=
APP_ENV=production
APP_DEBUG=false
EOF
}

install_dependencies() {
    info "安装项目依赖..."
    cd "$WEB_ROOT/site"
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress
    log "Composer 依赖完成"
    npm install --no-audit --no-fund
    log "npm 依赖完成"
    npm run build:css
    log "前端资源构建完成"
}

setup_env() {
    info "配置 .env..."
    cat > "$WEB_ROOT/site/.env" << EOF
APP_NAME="兰台观局"
APP_ENV=production
APP_DEBUG=false
APP_URL=http://$DOMAIN

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=$DB_NAME
DB_USERNAME=$DB_USER
DB_PASSWORD=$DB_PASS

CACHE_STORE=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync

LANTAI_DATA_DIR=
LANTAI_LEDGER_PREVIEW=60
LANTAI_OG_IMAGE=android-chrome-512x512.png
EOF
    log ".env 已写入"

    info "生成 APP_KEY..."
    (cd "$WEB_ROOT/site" && php artisan key:generate --force)
    log "APP_KEY 已生成"

    # config.py 读 REPO_ROOT.parent/llm key.txt，VPS 上 REPO_ROOT=WEB_ROOT
    # 即 WEB_ROOT 的父目录 /var/www/llm key.txt。cron 里另带 LANTAI_LLM_KEY 作双保险。
    if [ -n "$LLM_API_KEY" ]; then
        local key_file
        key_file="$(dirname "$WEB_ROOT")/llm key.txt"
        printf '%s\n' "$LLM_API_KEY" > "$key_file"
        chmod 600 "$key_file"
        log "LLM 凭证写入: $key_file"
    fi
}

setup_database() {
    info "配置数据库..."
    systemctl start "$DB_SERVICE"
    systemctl enable "$DB_SERVICE"

    local mysql_args=("-uroot")
    [ -n "$DB_ROOT_PASS" ] && mysql_args+=("--password=$DB_ROOT_PASS")

    mysql "${mysql_args[@]}" << SQL
CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
    log "数据库 $DB_NAME 已创建"

    (cd "$WEB_ROOT/site" && php artisan migrate --force)
    log "迁移完成"
}

setup_permissions() {
    info "设置文件权限..."
    chown -R "$WEB_USER:$WEB_USER" "$WEB_ROOT"
    chmod -R u=rwX,go=rX "$WEB_ROOT/site/public"
    chmod -R u=rwX,go=rX "$WEB_ROOT/site/storage"
    chmod -R u=rwX,go=rX "$WEB_ROOT/site/bootstrap/cache"
    chmod 600 "$WEB_ROOT/site/.env"
    log "权限设置完成"
}

setup_php_fpm() {
    info "配置 PHP-FPM..."
    PHP_FPM_SERVICE=$(systemctl list-unit-files --type=service 2>/dev/null \
        | grep -oE 'php[0-9.]*-fpm\.service' | head -1 | sed 's/\.service$//')
    [ -z "$PHP_FPM_SERVICE" ] && PHP_FPM_SERVICE="php-fpm"

    systemctl start "$PHP_FPM_SERVICE"
    systemctl enable "$PHP_FPM_SERVICE"

    PHP_FPM_SOCK=$(ls /run/php/php*-fpm.sock /run/php-fpm/*.sock /var/run/php-fpm/*.sock 2>/dev/null | head -1)
    [ -z "$PHP_FPM_SOCK" ] && PHP_FPM_SOCK="127.0.0.1:9000"

    log "PHP-FPM: service=$PHP_FPM_SERVICE sock=$PHP_FPM_SOCK"
}

setup_nginx() {
    info "配置 Nginx..."

    local fcgi_pass
    if [[ "$PHP_FPM_SOCK" = /* ]]; then
        fcgi_pass="unix:$PHP_FPM_SOCK"
    else
        fcgi_pass="$PHP_FPM_SOCK"
    fi

    cat > "$NGINX_CONF_FILE" << EOF
server {
    listen 80;
    server_name $DOMAIN;
    root $WEB_ROOT/site/public;
    index index.php;

    client_max_body_size 20M;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        fastcgi_pass $fcgi_pass;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.ht {
        deny all;
    }
}
EOF

    # Debian 风格需软链到 sites-enabled
    if [ -d /etc/nginx/sites-enabled ]; then
        ln -sf "$NGINX_CONF_FILE" /etc/nginx/sites-enabled/
        rm -f /etc/nginx/sites-enabled/default
    fi

    nginx -t
    systemctl restart nginx
    systemctl enable nginx
    log "Nginx 配置完成"
}

setup_cron() {
    info "配置定时任务..."
    local cron_tmp
    cron_tmp=$(mktemp)
    crontab -l 2>/dev/null | sed '/# BEGIN lantai/,/# END lantai/d' > "$cron_tmp"

    local llm_env=""
    [ -n "$LLM_API_KEY" ]  && llm_env="LANTAI_LLM_KEY=\"$LLM_API_KEY\" "
    [ -n "$LLM_BASE_URL" ] && llm_env+="LANTAI_LLM_BASE_URL=\"$LLM_BASE_URL\" "
    [ -n "$LLM_MODEL" ]    && llm_env+="LANTAI_LLM_MODEL=\"$LLM_MODEL\" "

    cat >> "$cron_tmp" << EOF
# BEGIN lantai
* * * * * cd $WEB_ROOT/site && php artisan schedule:run >> /dev/null 2>&1
0 8 * * * $llm_env cd $WEB_ROOT && $PYTHON_BIN pipeline/run.py --days 1 >> /var/log/lantai-pipeline.log 2>&1
0 8 * * 1 $llm_env cd $WEB_ROOT && $PYTHON_BIN pipeline/run.py --days 7 >> /var/log/lantai-pipeline.log 2>&1
# END lantai
EOF

    crontab "$cron_tmp" && rm -f "$cron_tmp"
    touch /var/log/lantai-pipeline.log
    log "定时任务已配置（幂等，重跑不重复）"
}

setup_firewall() {
    info "配置防火墙..."
    case "$FIREWALL_TOOL" in
        ufw)
            if command -v ufw &> /dev/null; then
                ufw allow 22/tcp
                ufw allow 80/tcp
                ufw allow 443/tcp
                ufw --force enable
                log "ufw 已配置"
            else
                warn "ufw 未安装，跳过"
            fi
            ;;
        firewalld)
            if command -v firewall-cmd &> /dev/null; then
                firewall-cmd --permanent --add-service=ssh
                firewall-cmd --permanent --add-service=http
                firewall-cmd --permanent --add-service=https
                if systemctl is-active --quiet firewalld; then
                    firewall-cmd --reload
                    log "firewalld 已配置"
                else
                    log "firewalld 规则已写入（服务未运行，启动后生效）"
                fi
            else
                warn "firewalld 未安装，跳过"
            fi
            ;;
    esac
}

load_config() {
    if [ -n "$1" ] && [ -f "$1" ]; then
        info "加载配置: $1"
        source "$1"
    else
        read -rp "域名或服务器 IP (默认 localhost): " DOMAIN_INPUT
        DOMAIN="${DOMAIN_INPUT:-localhost}"
        read -rp "数据库名 (默认 lantai): " DB_NAME_INPUT
        DB_NAME="${DB_NAME_INPUT:-lantai}"
        read -rp "数据库用户名 (默认 lantai): " DB_USER_INPUT
        DB_USER="${DB_USER_INPUT:-lantai}"
        read -rp "数据库密码 (默认 lantai_password): " DB_PASS_INPUT
        DB_PASS="${DB_PASS_INPUT:-lantai_password}"
        read -rp "MySQL root 密码 (如已设置): " DB_ROOT_PASS
        read -rp "LLM API 密钥 (可稍后配置): " LLM_API_KEY
        read -rp "LLM 端点 (留空用 sensenova 默认): " LLM_BASE_URL
        read -rp "LLM 模型 (留空用 sensenova 默认): " LLM_MODEL
    fi
}

summary() {
    echo ""
    echo "=========================================="
    echo -e "${GREEN}✓ 部署完成$NC"
    echo "=========================================="
    echo ""
    echo "站点地址: http://$DOMAIN"
    echo ""
    echo "常用命令:"
    echo "  应用日志:   tail -f $WEB_ROOT/site/storage/logs/laravel.log"
    echo "  内核日志:   tail -f /var/log/lantai-pipeline.log"
    echo "  定时任务:   crontab -l"
    echo "  重启服务:   systemctl restart nginx $PHP_FPM_SERVICE"
    echo "  手工出刊:   cd $WEB_ROOT && $PYTHON_BIN pipeline/run.py --days 1"
    echo ""
    echo "如需 HTTPS，请单独配置 certbot（本脚本未包含）"
    echo "如果配置了域名，请确保 DNS 指向本服务器 IP"
    echo ""
}

main() {
    echo "=========================================="
    echo "  兰台观局 一键部署脚本"
    echo "=========================================="
    echo ""

    check_root
    detect_os
    load_config "$1"

    install_packages
    install_composer
    install_nodejs

    copy_project
    init_env
    install_dependencies

    setup_env
    setup_database

    setup_permissions
    setup_php_fpm
    setup_nginx

    setup_cron
    setup_firewall

    summary
}

main "$@"
