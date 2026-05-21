# Xboard 传统部署指南

本文说明如何在不使用 Docker 的情况下部署 Xboard。传统部署会把服务拆成几个独立进程，交给宿主机管理：

- Nginx：公网入口和 HTTPS 反向代理
- Octane/Swoole：Laravel HTTP 服务，监听 `127.0.0.1:7001`
- Horizon：Redis 队列消费者
- WebSocket：节点同步服务，监听 `127.0.0.1:8076`
- systemd timer：每分钟执行 Laravel 定时任务

## 1. 服务器依赖

推荐环境：

- Ubuntu 22.04/24.04 或 Debian 12
- PHP 8.2+
- Composer 2
- MySQL 5.7+/8.0+ 或 MariaDB 10.6+
- Redis 6+
- Nginx
- PHP 扩展：`bcmath`、`curl`、`fileinfo`、`mbstring`、`openssl`、`pcntl`、`pdo_mysql`、`redis`、`swoole`、`tokenizer`、`xml`、`zip`

Ubuntu 示例：

```bash
sudo apt update
sudo apt install -y nginx mysql-server redis-server git unzip curl
sudo apt install -y php8.2-cli php8.2-fpm php8.2-mysql php8.2-redis \
  php8.2-bcmath php8.2-curl php8.2-mbstring php8.2-xml php8.2-zip
sudo pecl install swoole
echo "extension=swoole.so" | sudo tee /etc/php/8.2/mods-available/swoole.ini
sudo phpenmod swoole
```

不同系统的 PHP 包名可能不同，请按实际版本调整。

## 2. 创建数据库

```sql
CREATE DATABASE xboard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'xboard'@'127.0.0.1' IDENTIFIED BY 'change-me';
GRANT ALL PRIVILEGES ON xboard.* TO 'xboard'@'127.0.0.1';
FLUSH PRIVILEGES;
```

## 3. 拉取代码并初始化

```bash
sudo mkdir -p /var/www/xboard
sudo chown -R "$USER":www-data /var/www/xboard
git clone https://github.com/cedar2025/Xboard /var/www/xboard/current
cd /var/www/xboard/current
composer install --no-dev --prefer-dist --optimize-autoloader
cp deploy/traditional/env.example .env
```

编辑 `.env`，至少确认这些项：

- `APP_URL`
- `DB_CONNECTION`、`DB_HOST`、`DB_DATABASE`、`DB_USERNAME`、`DB_PASSWORD`
- `REDIS_HOST`、`REDIS_PORT`、`REDIS_PASSWORD`
- `CACHE_DRIVER=redis`
- `QUEUE_CONNECTION=redis`
- 由 Nginx 处理 HTTPS 时设置 `OCTANE_HTTPS=true`

执行安装：

```bash
php artisan xboard:install
php artisan storage:link
sudo chown -R www-data:www-data storage bootstrap/cache public/theme plugins
sudo chmod -R ug+rwX storage bootstrap/cache public/theme plugins
```

安装完成后，保存命令输出的后台入口、管理员账号和密码。

## 4. 安装 systemd 服务

```bash
sudo cp deploy/traditional/systemd/xboard-*.service /etc/systemd/system/
sudo cp deploy/traditional/systemd/xboard-scheduler.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now xboard-octane xboard-horizon xboard-ws xboard-scheduler.timer
```

检查状态：

```bash
systemctl status xboard-octane
systemctl status xboard-horizon
systemctl status xboard-ws
systemctl list-timers xboard-scheduler.timer
```

## 5. 配置 Nginx

```bash
sudo cp deploy/traditional/nginx/xboard.conf /etc/nginx/sites-available/xboard.conf
sudo sed -i 's/example.com/你的域名/g' /etc/nginx/sites-available/xboard.conf
sudo ln -s /etc/nginx/sites-available/xboard.conf /etc/nginx/sites-enabled/xboard.conf
sudo nginx -t
sudo systemctl reload nginx
```

示例配置默认使用 Let's Encrypt 证书路径。如果你的证书路径不同，需要修改 `ssl_certificate` 和 `ssl_certificate_key`。

## 6. 手动小版本更新

生产环境建议用 tag 发布：

```bash
cd /var/www/xboard/current
git fetch --tags --prune origin
git checkout v1.2.3
APP_DIR=/var/www/xboard/current bash deploy/traditional/scripts/deploy-release.sh
```

发布脚本会自动执行：

- 进入维护模式
- `composer install --no-dev`
- `php artisan xboard:update`
- 缓存 Laravel 配置、视图、事件
- 修复运行目录权限
- 重启 `xboard-octane`、`xboard-horizon`、`xboard-ws`
- 退出维护模式

如果发布失败，脚本默认会自动退出维护模式。需要保留维护模式时，设置：

```bash
KEEP_MAINTENANCE_ON_FAILURE=true APP_DIR=/var/www/xboard/current bash deploy/traditional/scripts/deploy-release.sh
```

## 7. CI/CD 建议

仓库提供了 `.github/workflows/traditional-deploy.example.yml`。默认是手动触发，避免未配置密钥时误部署。

需要配置 GitHub Secrets：

- `PROD_HOST`
- `PROD_USER`
- `PROD_SSH_KEY`
- `PROD_SSH_PORT`

推荐发布流程：

```bash
git checkout main
git pull --ff-only
git tag v1.2.3
git push origin v1.2.3
```

然后在 GitHub Actions 手动运行 `Traditional SSH Deploy Example`，`ref` 填 `v1.2.3`。

确认 SSH、sudo 权限和回滚流程都没问题之后，可以在 workflow 中加入 tag 自动触发：

```yaml
push:
  tags:
    - "v*"
```

生产环境更推荐创建专用 deploy 用户，并只允许它免密执行以下命令。示例 workflow 会用
`SYSTEMCTL_BIN="sudo systemctl"` 调用发布脚本：

```text
/bin/systemctl restart xboard-octane
/bin/systemctl restart xboard-horizon
/bin/systemctl restart xboard-ws
```

## 8. 回滚

```bash
cd /var/www/xboard/current
git fetch --tags --prune origin
git checkout v1.2.2
APP_DIR=/var/www/xboard/current bash deploy/traditional/scripts/deploy-release.sh
```

注意：数据库迁移默认是向前执行的。包含数据库结构变更的版本发布前，一定先备份数据库。
