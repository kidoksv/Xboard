# Traditional Deployment Guide

This guide deploys Xboard without Docker. The application is split into four
host-managed services:

- Nginx: public TLS reverse proxy
- Octane/Swoole: HTTP application server on `127.0.0.1:7001`
- Horizon: Redis queue workers
- WebSocket server: node synchronization server on `127.0.0.1:8076`
- systemd timer: Laravel scheduler every minute

## 1. Server Requirements

Recommended baseline:

- Ubuntu 22.04/24.04 or Debian 12
- PHP 8.2 or newer
- Composer 2
- MySQL 5.7+/8.0+ or MariaDB 10.6+
- Redis 6+
- Nginx
- PHP extensions: `bcmath`, `curl`, `fileinfo`, `mbstring`, `openssl`, `pcntl`,
  `pdo_mysql`, `redis`, `swoole`, `tokenizer`, `xml`, `zip`

Install example on Ubuntu:

```bash
sudo apt update
sudo apt install -y nginx mysql-server redis-server git unzip supervisor curl
sudo apt install -y php8.2-cli php8.2-fpm php8.2-mysql php8.2-redis \
  php8.2-bcmath php8.2-curl php8.2-mbstring php8.2-xml php8.2-zip
sudo pecl install swoole
echo "extension=swoole.so" | sudo tee /etc/php/8.2/mods-available/swoole.ini
sudo phpenmod swoole
```

Adjust package names if your distribution ships another PHP version.

## 2. Create Database

```sql
CREATE DATABASE xboard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'xboard'@'127.0.0.1' IDENTIFIED BY 'change-me';
GRANT ALL PRIVILEGES ON xboard.* TO 'xboard'@'127.0.0.1';
FLUSH PRIVILEGES;
```

## 3. Checkout Code

```bash
sudo mkdir -p /var/www/xboard
sudo chown -R "$USER":www-data /var/www/xboard
git clone https://github.com/cedar2025/Xboard /var/www/xboard/current
cd /var/www/xboard/current
composer install --no-dev --prefer-dist --optimize-autoloader
cp deploy/traditional/env.example .env
```

Edit `.env`:

- Set `APP_URL`
- Set `DB_*`
- Set `REDIS_*`
- Keep `CACHE_DRIVER=redis` and `QUEUE_CONNECTION=redis`
- Set `OCTANE_HTTPS=true` when Nginx terminates HTTPS

Then install:

```bash
php artisan xboard:install
php artisan storage:link
sudo chown -R www-data:www-data storage bootstrap/cache public/theme plugins
sudo chmod -R ug+rwX storage bootstrap/cache public/theme plugins
```

Save the admin URL, account, and password printed by the installer.

## 4. Install systemd Services

Copy service templates:

```bash
sudo cp deploy/traditional/systemd/xboard-*.service /etc/systemd/system/
sudo cp deploy/traditional/systemd/xboard-scheduler.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now xboard-octane xboard-horizon xboard-ws xboard-scheduler.timer
```

Check status:

```bash
systemctl status xboard-octane
systemctl status xboard-horizon
systemctl status xboard-ws
systemctl list-timers xboard-scheduler.timer
```

## 5. Configure Nginx

Copy and edit the sample:

```bash
sudo cp deploy/traditional/nginx/xboard.conf /etc/nginx/sites-available/xboard.conf
sudo sed -i 's/example.com/your-domain.com/g' /etc/nginx/sites-available/xboard.conf
sudo ln -s /etc/nginx/sites-available/xboard.conf /etc/nginx/sites-enabled/xboard.conf
sudo nginx -t
sudo systemctl reload nginx
```

The sample expects certificates at the standard Let's Encrypt path. If you use
another certificate provider, update `ssl_certificate` and `ssl_certificate_key`.

## 6. Manual Release Update

For small version updates from Git:

```bash
cd /var/www/xboard/current
git fetch --tags --prune origin
git checkout v1.2.3
APP_DIR=/var/www/xboard/current bash deploy/traditional/scripts/deploy-release.sh
```

The release script runs:

- maintenance mode
- `composer install --no-dev`
- `php artisan xboard:update`
- Laravel config/view/event cache warmup
- permission repair
- systemd restarts for Octane, Horizon, and WebSocket

If a deployment fails, maintenance mode is disabled automatically unless
`KEEP_MAINTENANCE_ON_FAILURE=true` is set.

## 7. CI/CD

Use `.github/workflows/traditional-deploy.example.yml` as a starting point.
It is intentionally manual-only. Configure these GitHub secrets:

- `PROD_HOST`
- `PROD_USER`
- `PROD_SSH_KEY`
- `PROD_SSH_PORT`

Recommended release flow:

```bash
git checkout main
git pull --ff-only
git tag v1.2.3
git push origin v1.2.3
```

Then open GitHub Actions, run `Traditional SSH Deploy Example`, and pass
`v1.2.3` as the `ref` input. If you want fully automatic tag deployments,
add this trigger to the workflow after your secrets and sudo rules are ready:

```yaml
push:
  tags:
    - "v*"
```

For a stricter production setup, create a non-root deploy user and allow only
these systemctl commands through sudo. The example workflow runs the release
script with `SYSTEMCTL_BIN="sudo systemctl"`:

```text
/bin/systemctl restart xboard-octane
/bin/systemctl restart xboard-horizon
/bin/systemctl restart xboard-ws
```

## 8. Rollback

Rollback to a previous tag:

```bash
cd /var/www/xboard/current
git fetch --tags --prune origin
git checkout v1.2.2
APP_DIR=/var/www/xboard/current bash deploy/traditional/scripts/deploy-release.sh
```

Database migrations are forward-only by default. Take a database backup before
each production release if the update includes migrations.
