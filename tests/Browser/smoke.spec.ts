import { expect, test } from '@playwright/test';

test('home page renders', async ({ page }) => {
  await page.goto('/');

  await expect(page.getByText('Applications open')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Start your application' })).toBeVisible();
});

test('verified user can reach settings and api docs', async ({ page }) => {
  await page.goto('/_browser/login/member?redirect=/user/settings');

  await expect(page.getByRole('heading', { name: 'Your settings hub' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'View API documentation' })).toBeVisible();

  await page.getByRole('link', { name: 'View API documentation' }).click();

  await expect(page.getByRole('heading', { name: /API reference/i })).toBeVisible();
  await expect(page.getByText('Authorization: Bearer <token>')).toBeVisible();
});

test('admin can reach the users index', async ({ page }) => {
  await page.goto('/_browser/login/admin?redirect=/admin/users');

  await expect(page.getByRole('heading', { name: 'Users' })).toBeVisible();
  await expect(page.getByText('Member directory')).toBeVisible();
  await expect(page.getByText('browser.member@example.test')).toBeVisible();
});

test('non-admin users are blocked from the admin area', async ({ page }) => {
  const response = await page.goto('/_browser/login/member?redirect=/admin/users');

  expect(response?.status()).toBe(403);
  await expect(page.getByText('You must be an administrator to view this page.')).toBeVisible();
});
