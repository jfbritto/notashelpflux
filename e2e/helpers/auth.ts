import { Page, expect } from '@playwright/test';

export const EMISSORA = { email: 'emissora@e2e.test', senha: 'senha-de-e2e' };
export const ADMIN = { email: 'admin@e2e.test', senha: 'senha-de-e2e' };

export async function entrarComo(page: Page, usuario: { email: string; senha: string }) {
  await page.goto('/login');
  await page.locator('#email').fill(usuario.email);
  await page.locator('#password').fill(usuario.senha);
  await page.getByRole('button', { name: /entrar|log in/i }).click();
  await expect(page).toHaveURL(/dashboard|notas/);
}
