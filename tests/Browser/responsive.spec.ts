import { expect, test, type Page } from '@playwright/test';

const representativePages = ['/', '/login', '/apply'];
const viewports = [
  { width: 390, height: 844 },
  { width: 768, height: 1024 },
  { width: 1024, height: 900 },
  { width: 1440, height: 900 },
];

const expectNoHorizontalOverflow = async (page: Page, path: string, width: number): Promise<void> => {
  const report = await page.evaluate(() => {
    const viewportWidth = document.documentElement.clientWidth;

    return {
      rootOverflow: document.documentElement.scrollWidth - viewportWidth,
      bodyOverflow: document.body.scrollWidth - viewportWidth,
      offenders: Array.from(document.querySelectorAll<HTMLElement>('body *'))
        .map((element) => {
          const bounds = element.getBoundingClientRect();

          return {
            element: element.tagName.toLowerCase(),
            id: element.id,
            classes: element.className?.toString().slice(0, 160) ?? '',
            left: Math.round(bounds.left),
            right: Math.round(bounds.right),
            width: Math.round(bounds.width),
          };
        })
        .filter((element) => element.right > viewportWidth + 1 || element.left < -1)
        .sort((left, right) => right.right - left.right)
        .slice(0, 8),
      overflowContainers: Array.from(document.querySelectorAll<HTMLElement>('.overflow-x-auto'))
        .filter((element) => element.scrollWidth > element.clientWidth + 1)
        .map((element) => {
          const bounds = element.getBoundingClientRect();
          const style = getComputedStyle(element);

          return {
            classes: element.className.toString().slice(0, 160),
            left: Math.round(bounds.left),
            right: Math.round(bounds.right),
            width: Math.round(bounds.width),
            clientWidth: element.clientWidth,
            scrollWidth: element.scrollWidth,
            overflowX: style.overflowX,
          };
        })
        .slice(0, 8),
      uncontainedOffenders: Array.from(document.querySelectorAll<HTMLElement>('body *'))
        .filter((element) => !element.closest('.overflow-x-auto'))
        .map((element) => {
          const bounds = element.getBoundingClientRect();

          return {
            element: element.tagName.toLowerCase(),
            classes: element.className?.toString().slice(0, 160) ?? '',
            left: Math.round(bounds.left),
            right: Math.round(bounds.right),
            width: Math.round(bounds.width),
          };
        })
        .filter((element) => element.right > viewportWidth + 1 || element.left < -1)
        .sort((left, right) => right.right - left.right)
        .slice(0, 8),
    };
  });

  const diagnostic = JSON.stringify({
    offenders: report.offenders,
    overflowContainers: report.overflowContainers,
    uncontainedOffenders: report.uncontainedOffenders,
  });
  expect(report.rootOverflow, `${path} should not overflow the ${width}px root viewport: ${diagnostic}`).toBeLessThanOrEqual(1);
  expect(report.bodyOverflow, `${path} should not overflow the ${width}px body viewport: ${diagnostic}`).toBeLessThanOrEqual(1);
};

for (const viewport of viewports) {
  test(`public and auth surfaces avoid horizontal overflow at ${viewport.width}px`, async ({ page }) => {
    await page.setViewportSize(viewport);

    for (const path of representativePages) {
      await page.goto(path);
      await expect(page.locator('#main-content')).toBeVisible();
      await expect(page.locator('h1').first()).toBeVisible();
      await expectNoHorizontalOverflow(page, path, viewport.width);
    }
  });
}

for (const viewport of viewports.slice(0, 2)) {
  test(`member and admin workspaces avoid horizontal overflow at ${viewport.width}px`, async ({ page }) => {
    await page.setViewportSize(viewport);
    await page.goto('/_browser/login/member?redirect=/accounts');
    await expect(page.getByRole('heading', { name: 'Manage your accounts' })).toBeVisible();
    await expectNoHorizontalOverflow(page, '/accounts', viewport.width);

    await page.goto('/defense/simulators');
    await expect(page.getByRole('heading', { name: 'War simulators' })).toBeVisible();
    await expectNoHorizontalOverflow(page, '/defense/simulators', viewport.width);

    await page.goto('/_browser/login/admin?redirect=/admin/grants');
    await expect(page.getByRole('heading', { name: 'Grant programs' })).toBeVisible();
    await expectNoHorizontalOverflow(page, '/admin/grants', viewport.width);

    await page.goto('/admin/finance');
    await expect(page.getByRole('heading', { name: 'Alliance finance ledger' })).toBeVisible();
    await expectNoHorizontalOverflow(page, '/admin/finance', viewport.width);
  });
}
