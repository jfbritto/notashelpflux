import { test, expect } from '@playwright/test';
import { entrarComo, EMISSORA } from './helpers/auth';

/**
 * A lista responde "de quem foi a geração" (serviço + origem) e o cancelamento
 * é um evento fiscal com justificativa, não uma limpeza de tela.
 *
 * POST /cancelar na Notaas é ASSÍNCRONO (conferido em
 * https://docs.notaas.com.br/endpoints): o pedido aceito responde 202 e o
 * desfecho ('cancelada') só chega depois, pela consulta ou pelo webhook. O
 * emissor falso do E2E reflete isso por padrão ('processando'), então o caso
 * comum aqui é "continua Emitida, com aviso de pendência", não "vira
 * Cancelada na hora".
 */
test('a lista identifica o serviço e a origem, e o cancelamento da nota emitida fica pendente até confirmar', async ({ page }) => {
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

  await expect(page.getByText('Cancelamento solicitado')).toBeVisible();

  // Ainda EMITIDA (o documento continua valendo até o emissor confirmar), com
  // o aviso de pendência no lugar do motivo — que só aparece quando a
  // situação vira "Cancelada" de verdade.
  const linhaDepois = page.locator('tr', { hasText: 'Cliente Semente Ltda' });
  await expect(linhaDepois.getByText('Emitida')).toBeVisible();
  await expect(linhaDepois.getByText('Cancelamento pedido, aguardando confirmação')).toBeVisible();
  await expect(linhaDepois.getByRole('button', { name: 'Cancelar' })).toHaveCount(0);

  // Quem não quer esperar a reconciliação (a cada 5 min) pode consultar na
  // hora. O emissor falso do E2E não tem como fingir a confirmação vindo de
  // fora (é estado estático do lado do PHP, sem canal pra mexer daqui), então
  // o único desfecho alcançável aqui é "ainda sem confirmação" — o que já
  // basta pra provar que a rota, o CSRF e o freio contra clique repetido
  // funcionam de ponta a ponta.
  await linhaDepois.getByRole('button', { name: 'Verificar agora' }).click();
  await expect(page.getByText('Ainda sem confirmação do emissor')).toBeVisible();

  await linhaDepois.getByRole('button', { name: 'Verificar agora' }).click();
  await expect(page.getByText(/Aguarde.*antes de tentar de novo/)).toBeVisible();
});
