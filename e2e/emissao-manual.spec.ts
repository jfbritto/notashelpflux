import { test, expect } from '@playwright/test';
import { entrarComo, EMISSORA } from './helpers/auth';

/**
 * O caminho que a plataforma existe para resolver: emitir uma nota de nutrição
 * sem entrar no portal nacional e sem digitar código fiscal nenhum.
 */
test.describe('emissão manual', () => {
  test.beforeEach(async ({ page }) => {
    // O ViaCEP é conveniência; no E2E ele é simulado para o teste não depender
    // de rede externa nem ficar lento por causa dela.
    await page.route('**/consultas/cep/**', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          logradouro: 'Rua João da Silva Abreu',
          bairro: 'Praia do Canto',
          cidade: 'Vitória',
          uf: 'ES',
          ibge: '3205309',
        }),
      }),
    );

    // A Receita também: por padrão "não achei", para os testes que digitam o
    // CNPJ e a razão social à mão não dependerem de quando (ou se) a consulta
    // de verdade responderia.
    await page.route('**/consultas/cnpj/**', (route) => route.fulfill({ status: 204 }));

    await entrarComo(page, EMISSORA);
  });

  test('colar o CNPJ preenche o cliente pela Receita', async ({ page }) => {
    await page.route('**/consultas/cnpj/**', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          nome: 'CLINICA EXEMPLO LTDA',
          email: 'financeiro@exemplo.test',
          logradouro: 'RUA JOAO DA SILVA ABREU',
          numero: '78',
          bairro: 'PRAIA DO CANTO',
          cidade: 'VITORIA',
          uf: 'ES',
          cep: '29055450',
          ibge: '3205309',
          situacao: 'ATIVA',
        }),
      }),
    );

    await page.goto('/notas/nova');
    await page.getByRole('button', { name: 'Desenvolvimento de sistemas' }).click();

    await page.locator('#tomador_documento').fill('11.222.333/0001-81');

    await expect(page.locator('#tomador_nome')).toHaveValue('CLINICA EXEMPLO LTDA');
    await expect(page.locator('#tomador_email')).toHaveValue('financeiro@exemplo.test');
    await expect(page.locator('#tomador_numero')).toHaveValue('78');
    await expect(page.locator('#tomador_cep')).toHaveValue('29055-450');
    // A cidade da Receita vem em maiúsculas e sem acento; o CEP a corrige.
    await expect(page.locator('#tomador_cidade')).toHaveValue('Vitória');
    await expect(page.locator('#tomador_logradouro')).toHaveValue('Rua João da Silva Abreu');
    await expect(page.getByText('Preenchemos com os dados da Receita Federal')).toBeVisible();
    // Não emite: os testes dividem o banco, e uma nota a mais na lista mudaria
    // o que os seguintes encontram. Que os campos preenchidos chegam ao
    // servidor, os testes de emissão abaixo já provam.
  });

  test('emite uma nota e ela aparece na lista', async ({ page }) => {
    await page.goto('/notas/nova');

    await page.locator('#tomador_documento').fill('11222333000181');
    await expect(page.locator('#tomador_documento')).toHaveValue('11.222.333/0001-81');

    await page.locator('#tomador_nome').fill('Clínica Exemplo Ltda');
    await page.locator('#tomador_email').fill('financeiro@exemplo.test');

    await page.locator('#tomador_cep').fill('29055450');
    await page.locator('#tomador_cep').blur();

    // O CEP preenche endereço e, junto, sugere onde o atendimento foi feito.
    await expect(page.locator('#tomador_cidade')).toHaveValue('Vitória');
    await expect(page.locator('#tomador_uf')).toHaveValue('ES');
    await expect(page.locator('#local_prestacao_nome')).toHaveValue('Vitória');

    await page.locator('#tomador_numero').fill('78');
    await page.locator('#valor').fill('2100.00');

    // A descrição já vem do perfil, e ela só complementa.
    await expect(page.locator('#descricao')).toHaveValue(/Atendimentos nutricionais/);
    await page.locator('#descricao').fill('Atendimentos nutricionais - projeto de agosto');

    await page.getByRole('button', { name: 'Emitir nota' }).click();

    await expect(page.getByText(/Nota enviada para emissão/i)).toBeVisible();
    await expect(page.getByText('Clínica Exemplo Ltda')).toBeVisible();
    await expect(page.getByText('R$ 2.100,00')).toBeVisible();
  });

  /**
   * Trocar o tipo troca a tributação da nota. Na tela isso precisa aparecer
   * como escolha de serviço, e trocar junto o texto e onde o serviço consta
   * como prestado: nutrição acontece onde o cliente está, desenvolvimento sai do
   * estabelecimento da empresa.
   */
  test('o tipo de serviço muda a descrição e o local', async ({ page }) => {
    await page.goto('/notas/nova');

    // Nutrição é o padrão.
    await expect(page.locator('#descricao')).toHaveValue('Atendimentos nutricionais');

    await page.locator('#tomador_cep').fill('29055450');
    await page.locator('#tomador_cep').blur();
    await expect(page.locator('#local_prestacao_nome')).toHaveValue('Vitória');

    // Desenvolvimento: o serviço passa a constar como prestado na sede.
    await page.getByRole('button', { name: 'Desenvolvimento de sistemas' }).click();
    await expect(page.locator('#descricao')).toHaveValue('Serviços de análise e desenvolvimento de sistemas');
    await expect(page.locator('#local_prestacao_nome')).toHaveValue('Santa Maria de Jetibá');

    await page.getByRole('button', { name: 'Atendimento nutricional' }).click();
    await expect(page.locator('#local_prestacao_nome')).toHaveValue('Vitória');
  });

  test('o texto escrito à mão não é perdido ao trocar o tipo', async ({ page }) => {
    await page.goto('/notas/nova');

    await page.locator('#descricao').fill('Consultoria do projeto de agosto');
    await page.getByRole('button', { name: 'Desenvolvimento de sistemas' }).click();

    await expect(page.locator('#descricao')).toHaveValue('Consultoria do projeto de agosto');
  });

  test('emite uma nota de desenvolvimento com a tributação certa', async ({ page }) => {
    await page.goto('/notas/nova');

    await page.getByRole('button', { name: 'Desenvolvimento de sistemas' }).click();
    await page.locator('#tomador_documento').fill('11222333000181');
    await page.locator('#tomador_nome').fill('Empresa Cliente Ltda');
    await page.locator('#tomador_cep').fill('29055450');
    await page.locator('#tomador_cep').blur();
    await expect(page.locator('#tomador_cidade')).toHaveValue('Vitória');
    await page.locator('#tomador_numero').fill('78');
    await page.locator('#valor').fill('500.00');

    await page.getByRole('button', { name: 'Emitir nota' }).click();

    await expect(page.getByText(/Nota enviada para emissão/i)).toBeVisible();
    await expect(page.getByText('Empresa Cliente Ltda')).toBeVisible();
    // O serviço consta prestado na sede, não na cidade do cliente.
    await expect(page.getByText('Santa Maria de Jetibá')).toBeVisible();
  });

  test('nenhum código fiscal aparece na tela', async ({ page }) => {
    await page.goto('/notas/nova');

    // Se algum destes vazar para o formulário, quem emite passa a ter que
    // entender tributação para usar a ferramenta.
    await expect(page.getByText('041001')).toHaveCount(0);
    await expect(page.getByText('4.10')).toHaveCount(0);
    await expect(page.getByText(/IBGE/i)).toHaveCount(0);
  });

  test('repetir nota abre o formulário com o cliente preenchido', async ({ page }) => {
    await page.goto('/notas/nova');
    await page.locator('#tomador_documento').fill('11222333000181');
    await page.locator('#tomador_nome').fill('Clínica Recorrente Ltda');
    await page.locator('#tomador_cep').fill('29055450');
    await page.locator('#tomador_cep').blur();
    await expect(page.locator('#tomador_cidade')).toHaveValue('Vitória');
    await page.locator('#valor').fill('300.00');
    await page.getByRole('button', { name: 'Emitir nota' }).click();

    await expect(page.getByText('Clínica Recorrente Ltda')).toBeVisible();

    await page.getByRole('link', { name: 'Repetir' }).first().click();

    await expect(page.getByText(/Repetindo a nota/i)).toBeVisible();
    await expect(page.locator('#tomador_nome')).toHaveValue('Clínica Recorrente Ltda');
    await expect(page.locator('#tomador_documento')).toHaveValue('11.222.333/0001-81');
  });

  test('a tela recusa valor zerado sem perder o que foi digitado', async ({ page }) => {
    await page.goto('/notas/nova');

    await page.locator('#tomador_documento').fill('11222333000181');
    await page.locator('#tomador_nome').fill('Clínica Exemplo Ltda');
    await page.locator('#tomador_cep').fill('29055450');
    await page.locator('#tomador_cep').blur();
    await expect(page.locator('#tomador_cidade')).toHaveValue('Vitória');
    await page.locator('#valor').fill('0');

    await page.getByRole('button', { name: 'Emitir nota' }).click();

    await expect(page.getByText(/valor da nota precisa ser maior que zero/i)).toBeVisible();
    await expect(page.locator('#tomador_nome')).toHaveValue('Clínica Exemplo Ltda');
  });
});
