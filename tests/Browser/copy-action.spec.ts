import { expect, test, type Page } from '@playwright/test';

const discordId = '111111111111111111';

const openMemberDiscordSettings = async (page: Page): Promise<void> => {
  await page.goto('/_browser/login/admin?redirect=/admin/users?search=Browser%20Member');

  const memberRow = page.getByRole('row').filter({ hasText: 'Browser Member' }).first();
  await memberRow.getByRole('link', { name: 'Edit' }).click();
  await expect(page.getByRole('heading', { name: /Edit User/i })).toBeVisible();
};

test('keyboard activation copies the exact Discord ID and announces success', async ({ page }) => {
  await page.addInitScript(() => {
    Object.defineProperty(navigator, 'clipboard', {
      configurable: true,
      value: {
        writeText: async (value: string): Promise<void> => {
          (window as typeof window & { copiedOperationalValue?: string }).copiedOperationalValue = value;
        },
      },
    });
  });

  await openMemberDiscordSettings(page);

  const copyButton = page.getByRole('button', { name: `Copy Discord ID: ${discordId}` });
  await copyButton.focus();
  await page.keyboard.press('Enter');

  await expect(copyButton).toBeFocused();
  await expect(copyButton.locator('..').getByRole('status')).toHaveText('Copied Discord ID.');
  await expect.poll(() => page.evaluate(() => (
    (window as typeof window & { copiedOperationalValue?: string }).copiedOperationalValue
  ))).toBe(discordId);
});

test.describe('clipboard-denied touch fallback', () => {
  test.use({ hasTouch: true, viewport: { width: 390, height: 844 } });

  test('selects only the readable canonical value and announces recovery guidance', async ({ page }) => {
    await page.addInitScript(() => {
      Object.defineProperty(navigator, 'clipboard', {
        configurable: true,
        value: {
          writeText: async (): Promise<void> => {
            throw new DOMException('Clipboard permission denied.', 'NotAllowedError');
          },
        },
      });
    });

    await openMemberDiscordSettings(page);

    const copyButton = page.getByRole('button', { name: `Copy Discord ID: ${discordId}` });
    await copyButton.tap();

    await expect(copyButton.locator('..').getByRole('status')).toHaveText(
      "Copy unavailable. Discord ID selected; use your device's copy command.",
    );
    await expect.poll(() => page.evaluate(() => window.getSelection()?.toString())).toBe(discordId);
  });
});
