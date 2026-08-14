# Subir para produção

`notas.helpflux.com.br` roda na mesma VPS dos outros sistemas, como subdomínio
da HelpFlux. O deploy é o mesmo padrão do TreinaEdu: GitHub Actions roda os
testes e, passando, executa um script por SSH como usuário `deploy`.

**Fase 1: a plataforma vive SEM webhook.** A conta da Notaas tem um webhook só
e ele aponta para o TreinaEdu, que precisa dele em produção. Quem fecha as
notas daqui é a reconciliação (`notas:reconciliar`), a cada 5 minutos, até a
virada do plano 2. Não mexer no webhook do painel da Notaas.

## Antes de tudo: DNS

Registro **A** para `notas.helpflux.com.br` apontando para `129.121.50.200`,
no mesmo lugar onde o DNS de `helpflux.com.br` é gerenciado. O certbot só
consegue emitir o certificado depois que isso propagar.

## Pré-requisitos na VPS (conferir antes de instalar)

```bash
node -v            # Vite 8 exige Node 20.19+ (ideal: 22). Sem Node, instalar antes.
php8.4 -v          # o site vai no pool 8.4 (o 8.3 é do TreinaEdu)
php8.4 -m | grep -E "pdo_mysql|bcmath|zip"
```

Se não houver Node 20+:

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | bash - && apt-get install -y nodejs
```

## Uma vez, na VPS

Como `root`, criar o banco e o usuário:

```sql
CREATE DATABASE notas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'notas'@'localhost' IDENTIFIED BY '<senha>';
GRANT ALL PRIVILEGES ON notas.* TO 'notas'@'localhost';
FLUSH PRIVILEGES;
```

Clonar (o repositório é público, HTTPS resolve, sem deploy key) e acertar as
permissões **do jeito que o TreinaEdu aprendeu a fazer**: dono `deploy`, grupo
`www-data` no que o php-fpm escreve, com setgid para arquivo novo já nascer no
grupo certo:

```bash
git clone https://github.com/jfbritto/notashelpflux.git /var/www/notas
chown -R deploy:deploy /var/www/notas
chown -R deploy:www-data /var/www/notas/storage /var/www/notas/bootstrap/cache
chmod -R 775 /var/www/notas/storage /var/www/notas/bootstrap/cache
find /var/www/notas/storage /var/www/notas/bootstrap/cache -type d -exec chmod g+s {} \;
```

> Rodar `artisan` como root deixa arquivos de root em `storage/` e o php-fpm
> para de conseguir escrever; foi a causa de um 500 sem rastro no TreinaEdu em
> 12/08/2026. **Tudo daqui em diante roda como `deploy`.**

nginx servindo `/var/www/notas/public` no socket do **PHP 8.4**
(`unix:/var/run/php/php8.4-fpm.sock`; o 8.3 é do TreinaEdu). Depois do DNS
propagar: `certbot --nginx -d notas.helpflux.com.br`.

O `.env` de produção (como `deploy`, a partir do `.env.example`):

```
APP_NAME="Notas HelpFlux"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://notas.helpflux.com.br
APP_LOCALE=pt_BR

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=notas
DB_USERNAME=notas
DB_PASSWORD=<a senha criada acima>

# Nada despacha job na fase 1; fila database sem worker engoliria em silêncio.
QUEUE_CONNECTION=sync

FISCAL_EMISSOR=notaas
NOTAAS_API_KEY=<chave da conta Notaas>
# SEM webhook na fase 1 (ele é do TreinaEdu). Deixar vazio; entra no plano 2.
NOTAAS_WEBHOOK_SECRET=
```

Depois do `.env`: `php artisan key:generate && php artisan migrate --force`
(como `deploy`).

O agendador entra no cron **do `deploy`** (não do root, pela mesma razão das
permissões). É ele que roda a reconciliação que fecha as notas na fase 1:

```bash
sudo -u deploy bash -c '(crontab -l 2>/dev/null; echo "* * * * * cd /var/www/notas && php artisan schedule:run >> /dev/null 2>&1") | crontab -'
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
npm ci && npm run build

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Permissões: mesmo remédio do TreinaEdu (12/08/2026). O chown não entra
# porque deploy não pode mudar grupo; o setgid das pastas resolve o grupo dos
# arquivos novos, aqui só se garante a escrita.
chmod -R g+w storage bootstrap/cache || echo "AVISO: permissao nao ajustada"
find storage bootstrap/cache -type d -exec chmod g+s {} \; || echo "AVISO: setgid nao aplicado"

echo ">> Deploy das notas completo"
```

Sem reload do php-fpm: o opcache revalida sozinho (mesmo comportamento do
deploy do TreinaEdu) e isso evita dar sudoers ao script. Sem supervisor: não
há worker de fila na fase 1.

## GitHub Actions (deploy automático)

O workflow espera os secrets `SSH_HOST`, `SSH_USER`, `SSH_PRIVATE_KEY` e
`SSH_PORT` no repositório `notashelpflux`. Gerar um par NOVO na VPS, dedicado
a isso:

```bash
sudo -u deploy ssh-keygen -t ed25519 -f /home/deploy/.ssh/gh_actions_notas -N ""
sudo -u deploy bash -c 'cat /home/deploy/.ssh/gh_actions_notas.pub >> /home/deploy/.ssh/authorized_keys'
sudo -u deploy cat /home/deploy/.ssh/gh_actions_notas
```

A última linha imprime a chave PRIVADA: colar direto em GitHub → notashelpflux
→ Settings → Secrets and variables → Actions (`SSH_PRIVATE_KEY`), junto de
`SSH_HOST=129.121.50.200`, `SSH_USER=deploy` e `SSH_PORT`. **A chave não passa
por chat nem por e-mail.** Enquanto os secrets não existirem, o job de Deploy
falha e o deploy é manual: `su - deploy -c '/home/deploy/deploy-notas.sh'`.

O sshd desta VPS **não escuta na porta 22 padrão** (o firewall libera a 22,
mas nada está de fato ouvindo nela): confirme com `sudo ss -tlnp | grep ssh`
ou olhando como você mesmo conecta (`ssh ... -p <porta>`) antes de assumir
22. Nesta VPS é `22022`. Porta errada aqui não dá erro óbvio: o job falha com
"connection refused", parecendo problema de rede, quando na verdade é só o
valor do secret (aconteceu de verdade em 14/08/2026, o secret nasceu com 22).

## Depois do primeiro deploy

1. Criar os dois usuários (como `deploy`, em `/var/www/notas`):

```bash
php artisan usuario:criar "João" jf.britto0@gmail.com --papel=admin
php artisan usuario:criar "<nome>" <email> --papel=emissor
```

A senha aparece uma vez só, na saída do comando.

2. **Emitir uma nota de nutrição de verdade e conferir campo a campo** contra a
   DANFSe de 06/08/2026: código de tributação (04.10.01), item da lista (4.10),
   NBS (1.2301.99.00), local da prestação e município de incidência do ISS. É o
   único jeito de saber que o perfil está certo, porque nota errada só aparece
   depois de emitida. Ela fecha em até 5 minutos, pela reconciliação.

3. **Não emitir Desenvolvimento de sistemas antes de conferir o item 1.01 com a
   contabilidade** (ou contra uma nota real desse serviço). É o único código
   fiscal do `config/fiscal.php` que não veio de nota real.

4. Conferir o limite do plano da Notaas. O gratuito é de 50 notas por mês, e a
   partir daqui as origens somam.

## O que fica para o plano 2

API para os SaaS, retorno assinado, virada do webhook (junto com
`NFSE_DRIVER=plataforma` no TreinaEdu, na mesma janela, com a fila vazia) e,
aí sim, `NOTAAS_WEBHOOK_SECRET` preenchido e a reconciliação relaxando para a
cadência de rede de segurança.
