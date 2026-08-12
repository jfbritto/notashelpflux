import { execSync } from 'node:child_process';

/**
 * Banco do E2E do zero a cada rodada, com o usuário de senha conhecida.
 *
 * As variáveis são repetidas aqui porque este script roda ANTES do webServer:
 * ele não herda o `env` do Playwright.
 */
const ambiente = {
  ...process.env,
  APP_ENV: 'e2e',
  FISCAL_EMISSOR: 'fake',
  NOTAAS_API_KEY: '',
  DB_HOST: '127.0.0.1',
  DB_PORT: '3311',
  DB_DATABASE: 'notas_e2e',
  DB_USERNAME: 'notas',
  DB_PASSWORD: 'secret',
};

export default function globalSetup() {
  execSync('php artisan migrate:fresh --force --seed --seeder=Database\\\\Seeders\\\\E2ESeeder', {
    env: ambiente,
    stdio: 'inherit',
  });
}
