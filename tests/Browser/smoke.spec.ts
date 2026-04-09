import { expect, test } from '@playwright/test';

test('home page renders', async ({ page }) => {
  await page.goto('/');

  await expect(page.getByText('Recruiting now')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Start your application' }).first()).toBeVisible();
  await page.getByRole('link', { name: 'Apply' }).first().click();

  await expect(page).toHaveURL(/\/apply$/);
  await expect(page.getByText('YosoNET Alliance Management System')).toBeVisible();
});

test('public login route and form actions are interactive', async ({ page }) => {
  await page.goto('/');
  await page.getByRole('link', { name: 'Login' }).first().click();

  await expect(page).toHaveURL(/\/login$/);
  await page.getByLabel('Username').fill('nobody');
  await page.getByLabel('Password').fill('wrong-password');
  await page.getByRole('button', { name: 'Log in' }).click();

  await expect(page.getByText('We couldn’t sign you in.')).toBeVisible();
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

  await expect(page.getByText('Manage Users').first()).toBeVisible();
  await expect(page.getByText('User Directory')).toBeVisible();
  await expect(page.getByText('browser.member@example.test')).toBeVisible();
});

test('non-admin users are blocked from the admin area', async ({ page }) => {
  const response = await page.goto('/_browser/login/member?redirect=/admin/users');

  expect(response?.status()).toBe(403);
  await expect(page.getByText('You must be an administrator to view this page.')).toBeVisible();
});
