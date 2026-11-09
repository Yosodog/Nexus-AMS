import { expect, test } from '@playwright/test';

test('member can type a full loan payment amount into the transfer form', async ({ page }) => {
  await page.goto('/_browser/login/member?redirect=/accounts');

  await expect(page.getByRole('heading', { name: 'Manage your accounts' })).toBeVisible();

  const destination = page.locator('#tran_to');
  const loanValue = await destination.locator('option[value^="loan_"]').first().getAttribute('value');
  expect(loanValue).not.toBeNull();
  await destination.selectOption(loanValue as string);

  const money = page.locator('#money');
  await expect(money).toHaveAttribute('min', '0.01');

  await money.click();
  await page.keyboard.press('End');
  await page.keyboard.press('Backspace');
  await expect(money).toHaveValue('');

  await page.keyboard.type('50000.25');
  await expect(money).toHaveValue('50000.25');

  await page.keyboard.press('ControlOrMeta+a');
  await page.keyboard.type('0.001');
  await page.keyboard.press('Tab');
  await expect(money).toHaveValue('0.01');
});
