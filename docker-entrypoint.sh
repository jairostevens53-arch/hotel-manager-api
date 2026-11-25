#!/bin/bash
set -e

PORT="${PORT:-80}"

php artisan migrate --force
php artisan schedule:work &

sed -i "s/80/${PORT}/g" /etc/apache2/ports.conf
sed -i "s/:80/:${PORT}/g" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
