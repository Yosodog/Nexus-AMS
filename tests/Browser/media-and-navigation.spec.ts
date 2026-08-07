import { expect, test } from '@playwright/test';

test('noncritical settings media reserves space and falls back after a load failure', async ({ page }) => {
  await page.goto('/_browser/login/admin?redirect=/admin/settings/public-site');

  const faviconSettings = page.locator('#favicon-settings');
  await faviconSettings.locator('summary').click();

  const media = faviconSettings.locator('[data-lazy-image][aria-label="Current favicon"]');
  const image = media.locator('[data-lazy-image-source]');
  const fallback = media.locator('[data-lazy-image-fallback]');

  await expect(media).toBeVisible();
  await expect(image).toHaveAttribute('loading', 'lazy');
  await expect(image).toHaveAttribute('decoding', 'async');
  await expect(image).toHaveAttribute('width', '36');
  await expect(image).toHaveAttribute('height', '36');

  await image.evaluate((element: HTMLImageElement) => {
    element.hidden = false;
    element.src = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" />';
  });
  await expect(image).toBeVisible();

  const boundsBefore = await media.boundingBox();
  await image.evaluate((element: HTMLImageElement) => {
    element.src = `/__missing-media-${Date.now()}.png`;
  });

  await expect(image).toBeHidden();
  await expect(fallback).toBeVisible();

  const boundsAfter = await media.boundingBox();
  expect(boundsBefore).not.toBeNull();
  expect(boundsAfter).not.toBeNull();
  expect(boundsAfter?.width).toBe(boundsBefore?.width);
  expect(boundsAfter?.height).toBe(boundsBefore?.height);
});

test('member navigation and settings do not advertise unavailable destinations', async ({ page }) => {
  await page.goto('/_browser/login/member?redirect=/user/settings');

  await expect(page.getByText(/coming soon/i)).toHaveCount(0);
  await expect(page.getByText('Use these shortcuts to return to your dashboard or manage Discord verification.')).toBeVisible();
  await expect(page.locator('nav a:not([href]), nav [disabled], nav [aria-disabled="true"]')).toHaveCount(0);
});
