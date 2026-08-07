import { expect, test, type Page } from '@playwright/test';

const expectUniqueIds = async (page: Page, path: string): Promise<void> => {
  const duplicates = await page.locator('[id]').evaluateAll((elements) => {
    const counts = new Map<string, number>();

    for (const element of elements) {
      counts.set(element.id, (counts.get(element.id) ?? 0) + 1);
    }

    return [...counts.entries()]
      .filter(([, count]) => count > 1)
      .map(([id]) => id);
  });

  expect(duplicates, `${path} contains duplicate IDs`).toEqual([]);
};

test('server validation focuses the grant error summary and its keyboard link returns to the field', async ({ page }) => {
  await page.goto('/_browser/login/member?redirect=/grants/infrastructure-reserve');

  const account = page.getByLabel('Deposit account');
  await account.focus();
  await expect(account).toBeFocused();

  const form = page.locator('#grant-application-form');
  await form.evaluate((element: HTMLFormElement) => {
    element.noValidate = true;
  });

  await account.selectOption('');
  await page.getByRole('button', { name: 'Apply for grant' }).click();
  await page.waitForLoadState('domcontentloaded');

  const summary = page.locator('#grant-application-errors');
  const errorLink = summary.getByRole('link', { name: 'Select an account for the grant disbursement.' });

  await expect(summary).toBeFocused();
  await expect(errorLink).toHaveAttribute('href', '#grant-account');
  await expect(account).toHaveAttribute('aria-invalid', 'true');
  await expect(account).toHaveAttribute('aria-errormessage', 'grant-account-error');

  await errorLink.press('Enter');
  await expect(account).toBeFocused();
});

test('migrated grant forms keep every id unique', async ({ page }) => {
  await page.goto('/_browser/login/member?redirect=/grants/infrastructure-reserve');
  await expectUniqueIds(page, '/grants/infrastructure-reserve');

  await page.goto('/grants/city');
  await expectUniqueIds(page, '/grants/city');
});
