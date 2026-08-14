import { test, expect } from '@playwright/test';
import { entrarComo, EMISSORA } from './helpers/auth';

/**
 * A lista responde "de quem foi a geração" (serviço + origem) e o cancelamento
 * é um evento fiscal com justificativa, não uma limpeza de tela.
 */
test('a lista identifica o serviço e a origem, e a nota emitida se cancela com motivo', async ({ page }) => {
  await entrarComo(page, EMISSORA);
  await page.goto('/notas');

  const linha = page.locator('tr', { hasText: 'Cliente Semente Ltda' });

  // De quem foi a geração: tipo de serviço por extenso e o selo de origem.
  await expect(linha.getByText('Atendimento nutricional')).toBeVisible();
  await expect(linha.getByText('Manual')).toBeVisible();
  await expect(linha.getByText('Emitida')).toBeVisible();

  // O filtro separa: só desenvolvimento, a nota de nutrição some.
  await page.locator('select[name="perfil"]').selectOption('desenvolvimento');
  await expect(page.getByText('Cliente Semente Ltda')).toHaveCount(0);
  await page.getByRole('link', { name: 'Limpar filtros' }).click();

  // Cancelar exige motivo, e motivo curto não passa nem no navegador
  // (minlength) nem no servidor (min:15).
  await linha.getByRole('button', { name: 'Cancelar' }).click();
  await expect(page.getByText('Cancelar a nota de')).toBeVisible();

  await page.locator('#motivo').fill('Nota de validação da plataforma, valor simbólico');
  await page.getByRole('button', { name: 'Cancelar a nota' }).click();

  await expect(page.getByText('Nota cancelada no emissor.')).toBeVisible();

  const linhaDepois = page.locator('tr', { hasText: 'Cliente Semente Ltda' });
  await expect(linhaDepois.getByText('Cancelada')).toBeVisible();
  await expect(linhaDepois.getByText('Nota de validação da plataforma, valor simbólico')).toBeVisible();
});
