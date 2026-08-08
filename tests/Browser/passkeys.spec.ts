import { expect, test } from '@playwright/test';

test('keeps password sign-in available when passkeys are unsupported', async ({ page }) => {
  await page.addInitScript(() => {
    (window as typeof window & { NexusPasskeysClient?: object }).NexusPasskeysClient = {
      isSupported: () => false,
    };
  });

  await page.goto('/login');

  await expect(page.getByRole('button', { name: 'Sign in to member app' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Sign in with a passkey' })).toBeHidden();
  await expect(page.getByText('Passkeys are not supported in this browser.')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Reset your password' })).toBeVisible();
});

test('announces recoverable login errors and prevents duplicate passkey requests', async ({ page }) => {
  await page.addInitScript(() => {
    const browserWindow = window as typeof window & {
      NexusPasskeysClient?: object;
      passkeyVerifyCalls?: number;
    };
    browserWindow.passkeyVerifyCalls = 0;
    browserWindow.NexusPasskeysClient = {
      isSupported: () => true,
      autofill: async () => undefined,
      verify: async () => {
        browserWindow.passkeyVerifyCalls = (browserWindow.passkeyVerifyCalls ?? 0) + 1;
        await new Promise((resolve) => window.setTimeout(resolve, 800));
        const error = new Error('The ceremony was cancelled.');
        error.name = 'UserCancelledError';
        throw error;
      },
    };
  });

  await page.goto('/login');

  const button = page.getByRole('button', { name: 'Sign in with a passkey' });
  await expect(button).toBeVisible();
  await button.focus();
  const loginBusyState = await button.evaluate((element) => {
    (element as HTMLButtonElement).click();
    (element as HTMLButtonElement).click();

    return {
      disabled: (element as HTMLButtonElement).disabled,
      ariaBusy: element.getAttribute('aria-busy'),
    };
  });

  expect(loginBusyState).toEqual({ disabled: true, ariaBusy: 'true' });
  await expect.poll(() => page.evaluate(() => (
    window as typeof window & { passkeyVerifyCalls?: number }
  ).passkeyVerifyCalls)).toBe(1);

  const status = page.locator('[data-passkey-status]');
  await expect(status).toContainText('No passkey was used.');
  await expect(status).toHaveAttribute('role', 'alert');
  await expect(button).toBeEnabled();
  await expect(button).toBeFocused();
});

test('focuses registration errors, explains duplicate credentials, and confirms server success', async ({ page }) => {
  await page.addInitScript(() => {
    const browserWindow = window as typeof window & {
      NexusPasskeysClient?: object;
      passkeyRegisterCalls?: number;
      passkeyRegisterMode?: 'duplicate' | 'success';
    };
    browserWindow.passkeyRegisterCalls = 0;
    browserWindow.passkeyRegisterMode = 'duplicate';
    browserWindow.NexusPasskeysClient = {
      isSupported: () => true,
      register: async ({ name }: { name: string }) => {
        browserWindow.passkeyRegisterCalls = (browserWindow.passkeyRegisterCalls ?? 0) + 1;
        await new Promise((resolve) => window.setTimeout(resolve, 800));

        if (browserWindow.passkeyRegisterMode === 'duplicate') {
          const error = new Error('Credential already exists.');
          error.name = 'PasskeyExistsError';
          throw error;
        }

        return { id: 'server-confirmed-id', name };
      },
    };
  });

  const redirect = encodeURIComponent('/user/confirm-password?return_to=passkeys');
  await page.goto('/_browser/login/member?redirect=' + redirect);
  await page.getByLabel('Current password').fill('password');
  await page.getByRole('button', { name: 'Confirm password and continue' }).click();
  await page.waitForURL(/\/user\/settings#passkeys$/);

  const nameInput = page.getByLabel('Passkey name');
  const addButton = page.getByRole('button', { name: 'Add passkey' });
  const status = page.locator('#passkeys [data-passkey-status]');

  await expect(addButton).toBeVisible();
  await addButton.click();
  await expect(nameInput).toBeFocused();
  await expect(status).toContainText('Enter a name');

  await nameInput.fill('Personal laptop');
  await addButton.focus();
  const registrationBusyState = await addButton.evaluate((element) => {
    (element as HTMLButtonElement).click();
    (element.closest('form') as HTMLFormElement).requestSubmit();

    return {
      disabled: (element as HTMLButtonElement).disabled,
      ariaBusy: element.getAttribute('aria-busy'),
    };
  });

  expect(registrationBusyState).toEqual({ disabled: true, ariaBusy: 'true' });
  await expect.poll(() => page.evaluate(() => (
    window as typeof window & { passkeyRegisterCalls?: number }
  ).passkeyRegisterCalls)).toBe(1);
  await expect(status).toContainText('already registered');
  await expect(status).toHaveAttribute('role', 'alert');
  await expect(addButton).toBeEnabled();
  await expect(addButton).toBeFocused();

  await page.evaluate(() => {
    (window as typeof window & { passkeyRegisterMode?: string }).passkeyRegisterMode = 'success';
  });
  await addButton.click();

  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('#passkeys [data-passkey-status]')).toContainText(
    'Passkey added. It is now available for sign-in.',
  );
});

test('completes registration, login, confirmation, and revocation with a virtual authenticator', async ({
  browserName,
  context,
  page,
}) => {
  test.skip(browserName !== 'chromium', 'The WebAuthn virtual authenticator uses Chromium DevTools.');

  const devtools = await context.newCDPSession(page);
  await devtools.send('WebAuthn.enable');
  const { authenticatorId } = await devtools.send('WebAuthn.addVirtualAuthenticator', {
    options: {
      protocol: 'ctap2',
      transport: 'internal',
      hasResidentKey: true,
      hasUserVerification: true,
      isUserVerified: true,
      automaticPresenceSimulation: true,
    },
  });

  try {
    const redirect = encodeURIComponent('/user/confirm-password?return_to=passkeys');
    await page.goto('/_browser/login/member?redirect=' + redirect);
    await page.getByLabel('Current password').fill('password');
    await page.getByRole('button', { name: 'Confirm password and continue' }).click();
    await page.waitForURL(/\/user\/settings#passkeys$/);

    await page.getByLabel('Passkey name').fill('Chromium virtual authenticator');
    await page.getByRole('button', { name: 'Add passkey' }).click();
    await expect(page.getByRole('heading', { name: 'Chromium virtual authenticator' })).toBeVisible({
      timeout: 15_000,
    });

    await page.getByLabel('Passkey name').fill('Duplicate virtual authenticator');
    await page.getByRole('button', { name: 'Add passkey' }).click();
    await expect(page.locator('#passkeys [data-passkey-status]')).toContainText('already registered');
    await expect(page.getByRole('article')).toHaveCount(1);

    await page.getByLabel('Open account menu').click();
    await page.getByRole('button', { name: 'Sign out' }).click();
    await page.waitForURL('/');
    await page.goto('/login');
    await page.getByRole('button', { name: 'Sign in with a passkey' }).click();
    await page.waitForURL('/user/dashboard');

    await page.goto('/user/settings');
    const passkeyCard = page.getByRole('article').filter({ hasText: 'Chromium virtual authenticator' });
    await expect(passkeyCard).not.toContainText('Never used');

    await page.getByRole('link', { name: 'Confirm identity to manage passkeys' }).click();
    await page.getByRole('button', { name: 'Confirm with a passkey' }).click();
    await page.waitForURL(/\/user\/settings#passkeys$/);
    await page.getByRole('button', { name: 'Revoke', exact: true }).click();
    await page.getByRole('button', { name: 'Revoke passkey', exact: true }).click();
    await expect(page.getByText('The passkey was revoked after the server confirmed the request.')).toBeVisible();
    await expect(page.getByText('No passkeys are registered.')).toBeVisible();
  } finally {
    await devtools.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId });
    await devtools.send('WebAuthn.disable');
  }
});
