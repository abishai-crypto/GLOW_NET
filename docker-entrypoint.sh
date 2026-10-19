#!/bin/bash
set -e

# Badilisha port ya Apache kulingana na mazingira ya Render ($PORT au 80)
PORT="${PORT:-80}"
sed -i "s/Listen 80/Listen ${PORT}/g" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost \*:${PORT}>/g" /etc/apache2/sites-available/000-default.conf

# Tekeleza Apache
exec apache2-foreground
