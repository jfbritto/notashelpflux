import { defineConfig, devices } from '@playwright/test';

/**
 * O app do E2E sobe com o EMISSOR FALSO e sem chave da Notaas.
 *
 * As duas variáveis vão aqui, no bloco `env` do webServer, e não só num
 * arquivo de ambiente: arquivo só é lido quando APP_ENV já vale `e2e`, e a
 * garantia mais importante deste projeto (nenhum teste emite nota de verdade)
 * não pode depender dessa ordem. A chave vai explicitamente vazia para que, se
 * algum caminho escapar do emissor falso, ele falhe por falta de chave em vez
 * de emitir.
 */
export default defineConfig({
  testDir: './e2e',
  globalSetup: './e2e/setup.ts',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: 'line',
  use: {
    baseURL: 'http://127.0.0.1:8300',
    trace: 'on-first-retry',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    command: 'php artisan serve --host=127.0.0.1 --port=8300',
    url: 'http://127.0.0.1:8300',
    reuseExistingServer: !process.env.CI,
    env: {
      APP_ENV: 'e2e',
      FISCAL_EMISSOR: 'fake',
      NOTAAS_API_KEY: '',
      DB_HOST: '127.0.0.1',
      DB_PORT: '3311',
      DB_DATABASE: 'notas_e2e',
      DB_USERNAME: 'notas',
      DB_PASSWORD: 'secret',
    },
  },
});
