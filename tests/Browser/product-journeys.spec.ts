import { expect, test, type Page } from '@playwright/test';

const expectApplicationShell = async (page: Page): Promise<void> => {
  await expect(page.locator('#main-content')).toBeVisible();
  await expect(page.locator('h1').first()).toBeVisible();
};

test('registration explains the complete onboarding path', async ({ page }) => {
  await page.goto('/register');

  await expectApplicationShell(page);
  await expect(page.getByRole('heading', { name: 'Create your member account' })).toBeVisible();
  await expect(page.getByText('Verify your nation', { exact: true })).toBeVisible();
  await expect(page.getByText('Complete access checks', { exact: true })).toBeVisible();
  await expect(page.getByLabel('Politics & War nation ID')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Create member account' })).toBeVisible();
});

test('member can move from overview into finance and defense workflows', async ({ page }) => {
  await page.goto('/_browser/login/member?redirect=/user/dashboard');

  await expectApplicationShell(page);
  await expect(page.getByRole('heading', { name: 'Browser Member Nation' })).toBeVisible();
  await expect(page.getByText('Military readiness')).toBeVisible();

  await page.goto('/accounts');
  await expectApplicationShell(page);
  await expect(page.getByRole('heading', { name: 'Manage your accounts' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Operations reserve', exact: true })).toBeVisible();
  await expect(page.getByRole('cell', { name: '$1,250,000.00', exact: true })).toBeVisible();

  await page.goto('/lottery');
  await expectApplicationShell(page);
  await expect(page.getByRole('heading', { name: 'Three characters. One weekly draw.' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Buy tickets' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Buy lottery tickets' })).toBeVisible();

  await page.goto('/loans');
  await expectApplicationShell(page);
  await expect(page.getByRole('heading', { name: 'Loans' })).toBeVisible();
  await expect(page.getByRole('cell', { name: '$7,500,000.00', exact: true })).toBeVisible();

  await page.goto('/defense/simulators');
  await expectApplicationShell(page);
  await expect(page.getByRole('heading', { name: 'War simulators' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Run Simulation' })).toBeVisible();
  await expect(page.getByText('Failed to load defaults.')).toHaveCount(0);

  await page.goto('/leaderboards');
  await expectApplicationShell(page);
  await expect(page.getByRole('heading', { name: 'Leaderboards' })).toBeVisible();
  await expect(page.getByRole('link', { name: /Open Profitability/i })).toBeVisible();

  await page.goto('/leaderboards/profitability');
  await expectApplicationShell(page);
  await expect(page.getByRole('heading', { name: 'Daily nation profitability' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Calculation context' })).toBeVisible();
});

test('full admin can reach core operations and inspect ledger activity', async ({ page }) => {
  await page.goto('/_browser/login/admin?redirect=/admin');

  await expectApplicationShell(page);
  await expect(page.getByRole('heading', { name: 'Operations overview' })).toBeVisible();

  await page.goto('/admin/accounts');
  await expectApplicationShell(page);
  await expect(page.getByRole('heading', { name: 'Account Management' })).toBeVisible();

  await page.goto('/admin/lottery');
  await expectApplicationShell(page);
  await expect(page.getByRole('heading', { name: 'Weekly Lottery' })).toBeVisible();
  await expect(page.getByText('Lottery configuration', { exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Save lottery configuration' })).toBeVisible();

  await page.goto('/admin/finance');
  await expectApplicationShell(page);
  await expect(page.getByRole('heading', { name: 'Alliance finance ledger' })).toBeVisible();
  await expect(page.getByText('$2,400,000.00', { exact: true }).first()).toBeVisible();
  await expect(page.locator('#financeNetChart')).toContainText('$2,400,000');
  await expect(page.locator('#financeCategoryChart')).toContainText('$3,150,000');
  const ledgerDay = page.getByRole('button', { name: /2 recorded entries/i });
  await ledgerDay.click();
  await expect(page.getByText('Member tax settlement')).toBeVisible();
  await expect(page.getByText('Infrastructure grant reserve')).toBeVisible();

  await page.goto('/admin/members');
  await expectApplicationShell(page);
  await expect(page.getByRole('heading', { name: 'Alliance Members' })).toBeVisible();
});

test('full admin can review populated grant, city grant, and loan queues', async ({ page }) => {
  await page.goto('/_browser/login/admin?redirect=/admin/grants');

  await expectApplicationShell(page);
  await expect(page.getByRole('heading', { name: 'Grant programs' })).toBeVisible();
  await expect(page.getByText('Pending grant requests')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Infrastructure reserve' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Approve and deposit' })).toBeVisible();

  await page.goto('/admin/grants/city');
  await expectApplicationShell(page);
  await expect(page.getByRole('heading', { name: 'City grants', exact: true })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'City #13' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Approve and deposit' })).toBeVisible();

  await page.goto('/admin/loans');
  await expectApplicationShell(page);
  await expect(page.getByRole('heading', { name: 'Loans' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Review and approve' })).toBeVisible();

});

test('full admin can reach war planning, settings, and custom-page editing', async ({ page }) => {
  await page.goto('/_browser/login/admin?redirect=/admin/war-room');

  await expectApplicationShell(page);
  await expect(page.getByRole('heading', { name: 'War Room Dashboard' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Create War Plan' })).toBeVisible();

  await page.goto('/admin/settings');
  await expectApplicationShell(page);
  await expect(page.getByRole('heading', { name: 'Admin Settings' })).toBeVisible();
  await expect(page.getByText('Synchronization')).toBeVisible();

  await page.goto('/admin/customization');
  await expectApplicationShell(page);
  await expect(page.getByRole('heading', { name: 'Custom Page Management' })).toBeVisible();
  await expect(page.getByText('/browser-operations-guide')).toBeVisible();

  await page.getByRole('link', { name: 'Edit', exact: true }).click();
  await expect(page).toHaveURL(/\/admin\/customization\/pages\/\d+$/);
  await expect(page.getByRole('heading', { name: 'Customize Page: /browser-operations-guide' })).toBeVisible();
  await expect(page.getByRole('button', { name: /Save Draft/i })).toBeVisible();
  await expect(page.getByRole('button', { name: /Publish/i })).toBeVisible();
});

test('limited admin navigation and routes respect view permissions', async ({ page }) => {
  await page.goto('/_browser/login/limited?redirect=/admin/users');

  await expectApplicationShell(page);
  await expect(page.getByRole('heading', { name: 'Manage Users' })).toBeVisible();

  const navigation = page.getByRole('navigation', { name: 'Administrative navigation' });
  await expect(navigation.getByRole('link', { name: 'Users', exact: true })).toBeVisible();
  await expect(navigation.getByRole('link', { name: 'Accounts', exact: true })).toHaveCount(0);
  await expect(navigation.getByRole('link', { name: 'War room', exact: true })).toHaveCount(0);

  const forbiddenResponse = await page.goto('/admin/accounts');
  expect(forbiddenResponse?.status()).toBe(403);
  await expect(page.getByRole('heading', { name: 'You do not have access to this area' })).toBeVisible();
});
