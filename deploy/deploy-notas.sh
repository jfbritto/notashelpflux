#!/usr/bin/env bash
#
# O deploy de cada merge. Vive em /home/deploy/deploy-notas.sh na VPS e é
# chamado pelo GitHub Actions (ou à mão: su - deploy -c '/home/deploy/deploy-notas.sh').
#
# Instalação/atualização na VPS:
#   curl -fsSL https://raw.githubusercontent.com/jfbritto/notashelpflux/main/deploy/deploy-notas.sh -o /home/deploy/deploy-notas.sh
#   chown deploy:deploy /home/deploy/deploy-notas.sh && chmod 755 /home/deploy/deploy-notas.sh
set -euo pipefail

cd /var/www/notas

echo ">> Atualizando o codigo..."
git fetch origin main
git reset --hard origin/main

echo ">> Dependencias..."
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
npm ci && npm run build

echo ">> Migracoes e caches..."
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Permissões: mesmo remédio do TreinaEdu (12/08/2026). O chown não entra
# porque deploy não pode mudar grupo; o setgid das pastas resolve o grupo dos
# arquivos novos, aqui só se garante a escrita. Sem reload de fpm (o opcache
# revalida sozinho) e sem supervisor (não há worker de fila na fase 1).
echo ">> Permissoes..."
chmod -R g+w storage bootstrap/cache || echo "AVISO: permissao nao ajustada"
find storage bootstrap/cache -type d -exec chmod g+s {} \; || echo "AVISO: setgid nao aplicado"

echo ">> Deploy das notas completo"
