#!/bin/bash
#
# Coloca a fase 1 no ar: banco, clone, .env, permissões e app instalado.
#
# Roda como ROOT na VPS, e é IDEMPOTENTE: pode rodar de novo depois de uma
# falha parcial (banco já criado, clone pela metade) sem estragar nada.
#
#   curl -fsSL https://raw.githubusercontent.com/jfbritto/notashelpflux/main/deploy/setup-fase1.sh -o /root/setup-notas.sh
#   bash /root/setup-notas.sh
#
# Ele existe como arquivo no repositório porque colar script longo no terminal
# já mutilou a colagem duas vezes; baixar por curl não tem esse modo de falha.
set -euo pipefail

APP=/var/www/notas

if [ ! -d "$APP/.git" ]; then
  git clone https://github.com/jfbritto/notashelpflux.git "$APP"
fi
cd "$APP"

if [ ! -f .env ]; then
  # A senha precisa passar na política do MySQL do servidor (maiúscula,
  # minúscula, dígito e símbolo): a primeira tentativa, hex puro, foi
  # recusada com ERROR 1819.
  DBPASS="Nh$(openssl rand -hex 14)aA!9"

  mysql -e "CREATE DATABASE IF NOT EXISTS notas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER IF NOT EXISTS 'notas'@'localhost' IDENTIFIED BY '$DBPASS';
  ALTER USER 'notas'@'localhost' IDENTIFIED BY '$DBPASS';
  GRANT ALL PRIVILEGES ON notas.* TO 'notas'@'localhost';
  FLUSH PRIVILEGES;"

  cp .env.example .env
  sed -i "s|^APP_NAME=.*|APP_NAME=\"Notas HelpFlux\"|; s|^APP_ENV=.*|APP_ENV=production|; s|^APP_DEBUG=.*|APP_DEBUG=false|; s|^APP_URL=.*|APP_URL=https://notas.helpflux.com.br|; s|^DB_CONNECTION=.*|DB_CONNECTION=mysql|; s|^# DB_HOST=.*|DB_HOST=127.0.0.1|; s|^# DB_PORT=.*|DB_PORT=3306|; s|^# DB_DATABASE=.*|DB_DATABASE=notas|; s|^# DB_USERNAME=.*|DB_USERNAME=notas|; s|^# DB_PASSWORD=.*|DB_PASSWORD=$DBPASS|; s|^QUEUE_CONNECTION=.*|QUEUE_CONNECTION=sync|" .env

  # Fila sync na fase 1: nada despacha job, e fila database sem worker
  # engoliria em silêncio. O bloco fiscal entra com a chave em branco, que é
  # a única coisa que o dono cola à mão (segredo não viaja por chat nem repo).
  printf '\nFISCAL_EMISSOR=notaas\nNOTAAS_API_KEY=COLE_A_CHAVE_AQUI\nNOTAAS_WEBHOOK_SECRET=\n' >> .env
fi

# Permissões do jeito que o TreinaEdu aprendeu em 12/08/2026: deploy é dono,
# www-data escreve onde o php-fpm precisa, e o setgid faz arquivo novo já
# nascer no grupo certo. O .env fica 640 (terceiros não leem).
chown -R deploy:deploy "$APP"
chown -R deploy:www-data "$APP/storage" "$APP/bootstrap/cache"
chmod -R 775 "$APP/storage" "$APP/bootstrap/cache"
find "$APP/storage" "$APP/bootstrap/cache" -type d -exec chmod g+s {} \;
chown deploy:www-data "$APP/.env"
chmod 640 "$APP/.env"

# Tudo que é composer/npm/artisan roda como deploy: artisan como root já
# deixou arquivo de root em storage e causou 500 sem rastro no TreinaEdu.
su - deploy -c "cd $APP && composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev"
su - deploy -c "cd $APP && npm ci && npm run build"

# Só gera APP_KEY se ainda não houver: regenerar depois de dados cifrados
# no banco os tornaria ilegíveis.
if grep -q '^APP_KEY=$' "$APP/.env"; then
  su - deploy -c "cd $APP && php artisan key:generate --force"
fi

su - deploy -c "cd $APP && php artisan migrate --force"

echo ""
echo "PRONTO. Banco, app e permissoes no lugar."
echo "Falta colar a chave da Notaas no .env (linha NOTAAS_API_KEY), como deploy."
