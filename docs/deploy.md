# Subir para produção

`notas.helpflux.com.br` roda na mesma VPS dos outros sistemas, como subdomínio
da HelpFlux. O deploy é o mesmo padrão do TreinaEdu: GitHub Actions roda os
testes e, passando, executa um script por SSH como usuário `deploy`.

## Uma vez, na VPS

Como `root`, criar o banco e o usuário:

```sql
CREATE DATABASE notas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'notas'@'localhost' IDENTIFIED BY '<senha>';
GRANT ALL PRIVILEGES ON notas.* TO 'notas'@'localhost';
FLUSH PRIVILEGES;
```

Clonar em `/var/www/notas`, com o dono certo desde o começo:

```bash
git clone https://github.com/jfbritto/notashelpflux.git /var/www/notas
chown -R deploy:deploy /var/www/notas
```

> Rodar `artisan` como root deixa arquivos de root em `storage/` e
> `bootstrap/cache/`, e o php-fpm (que roda como www-data) para de conseguir
> escrever. Já aconteceu no TreinaEdu. **Tudo aqui roda como `deploy`.**

nginx, servindo `/var/www/notas/public`, com o certificado emitido pelo
Let's Encrypt (`certbot --nginx -d notas.helpflux.com.br`).

O `.env` de produção precisa de:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://notas.helpflux.com.br
FISCAL_EMISSOR=notaas
NOTAAS_API_KEY=<chave da conta Notaas>
NOTAAS_WEBHOOK_SECRET=<segredo do webhook>
```

O agendador precisa estar no cron do `deploy`, senão a reconciliação de notas
paradas nunca roda:

```
* * * * * cd /var/www/notas && php artisan schedule:run >> /dev/null 2>&1
```

## O script de deploy

Em `/home/deploy/deploy-notas.sh`, dono `deploy`, modo 755:

```bash
#!/usr/bin/env bash
set -euo pipefail

cd /var/www/notas

git fetch origin main
git reset --hard origin/main

composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
npm ci --omit=dev && npm run build

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

sudo systemctl reload php8.4-fpm
```

## Depois do primeiro deploy

1. Criar os dois usuários:

```bash
php artisan usuario:criar "João" jf.britto0@gmail.com --papel=admin
php artisan usuario:criar "<nome>" <email> --papel=emissor
```

A senha aparece uma vez só, na saída do comando.

2. Cadastrar o webhook no painel da Notaas apontando para
   `https://notas.helpflux.com.br/webhooks/notaas`, com o mesmo segredo do
   `.env`.

   **Atenção:** enquanto o TreinaEdu ainda emite direto, a conta da Notaas tem
   um webhook só. Apontá-lo para cá faz a nota do TreinaEdu parar de fechar
   sozinha. Ou se cria um segundo webhook no painel, se eles permitirem, ou
   este passo espera a virada (plano 2).

3. **Emitir uma nota de nutrição de verdade e conferir campo a campo** contra a
   DANFSe de 06/08/2026: código de tributação, item da lista, NBS, local da
   prestação e município de incidência do ISS. É o único jeito de saber que o
   perfil está certo, porque nota errada só aparece depois de emitida.

4. Conferir o limite do plano da Notaas. O gratuito é de 50 notas por mês, e a
   partir daqui as origens somam.
