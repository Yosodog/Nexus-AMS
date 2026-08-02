import { expect, test, type Locator, type Page } from '@playwright/test';

test.setTimeout(60_000);

const chooseField = async (
  page: Page,
  condition: Locator,
  fieldName: string,
  keyboardOnly = false,
): Promise<void> => {
  await condition.locator('[data-audit-field-button]').click();

  const picker = page.locator('dialog[data-audit-field-picker][open]');
  const search = picker.getByLabel('Search audit fields');
  await expect(picker).toBeVisible();
  await search.fill(fieldName);

  if (keyboardOnly) {
    await search.press('ArrowDown');
    await expect(picker.getByRole('option', { name: fieldName, exact: true })).toBeFocused();
    await page.keyboard.press('Enter');
  } else {
    await picker.getByRole('option', { name: fieldName, exact: true }).click();
  }

  await expect(picker).not.toBeVisible();
  await expect(condition.locator('[data-audit-field-button]')).toHaveText(fieldName);
};

const expectNoHorizontalOverflow = async (page: Page): Promise<void> => {
  const overflow = await page.evaluate(() => ({
    document: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    body: document.body.scrollWidth - document.documentElement.clientWidth,
  }));

  expect(overflow.document).toBeLessThanOrEqual(1);
  expect(overflow.body).toBeLessThanOrEqual(1);
};

test('admin builds a nested guided rule and confirms activation impact', async ({ page }) => {
  await page.addInitScript(() => {
    if (!window.localStorage.getItem('nexus-theme')) {
      window.localStorage.setItem('nexus-theme', 'light');
    }
  });
  await page.goto('/_browser/login/admin?redirect=/admin/audits/rules/create');

  await expect(page.getByRole('heading', { name: 'New Audit Rule' })).toBeVisible();
  await expect.poll(async () => page.evaluate(() => document.documentElement.dataset.theme)).toBe('light');

  await page.getByLabel('Rule name').fill('Browser guided readiness rule');
  await page.getByLabel('Finding explanation').fill('Your nation needs attention under the browser readiness check.');
  await page.getByLabel('Remediation guidance').fill('Review the matched values and bring them up to the alliance target.');

  const criteria = page.locator('[data-audit-criteria]');
  await criteria.getByRole('button', { name: 'Add condition', exact: true }).click();

  const firstCondition = criteria.locator('.audit-rule-condition-row').first();
  await chooseField(page, firstCondition, 'Nation ID', true);
  await firstCondition.getByLabel('Operator for Nation ID').selectOption('gt');
  await firstCondition.getByLabel('Value for Nation ID').fill('0');

  await criteria.getByRole('button', { name: 'Add subgroup', exact: true }).click();
  const subgroup = criteria.locator('section[aria-label^="Nested"]').last();
  await subgroup.getByLabel('Nested group matching logic').selectOption('any');
  await subgroup.getByRole('button', { name: 'Add condition', exact: true }).click();

  const nestedCondition = subgroup.locator('.audit-rule-condition-row').first();
  await chooseField(page, nestedCondition, 'City count');
  await nestedCondition.getByLabel('Operator for City count').selectOption('gt');
  await nestedCondition.getByLabel('Value for City count').fill('0');

  await firstCondition.getByRole('button', { name: 'Move down' }).click();
  const reorderedDefinition = JSON.parse(await page.locator('[data-audit-definition-input]').inputValue());
  expect(reorderedDefinition.criteria.rules[0].group).toBe('any');
  expect(reorderedDefinition.criteria.rules[1].field).toBe('nation.id');

  const exceptionDisclosure = page.locator('[data-audit-exceptions-disclosure]');
  await exceptionDisclosure.locator('summary').click();
  await page.locator('[data-audit-exceptions]').getByRole('button', { name: 'Add an exception', exact: true }).click();

  const exceptionCondition = page.locator('[data-audit-exceptions] .audit-rule-condition-row').first();
  await chooseField(page, exceptionCondition, 'Nation ID');
  await exceptionCondition.getByLabel('Operator for Nation ID').selectOption('eq');
  await exceptionCondition.getByLabel('Value for Nation ID').fill('-1');

  await expect(page.locator('[data-audit-summary]')).toContainText('Nation ID');
  const csrfToken = await page.locator('input[name="_token"]').inputValue();
  await expect(page.locator('meta[name="csrf-token"]')).toHaveAttribute('content', csrfToken);

  const testRequestPromise = page.waitForRequest((request) => (
    new URL(request.url()).pathname === '/admin/audits/rules/preview'
    && request.method() === 'POST'
  ));
  await page.getByRole('button', { name: 'Test rule' }).click();
  const testRequest = await testRequestPromise;
  expect(testRequest.headers()['x-csrf-token']).toBe(csrfToken);
  expect(testRequest.postDataJSON()._token).toBe(csrfToken);
  await expect(page.locator('[data-audit-preview-status]')).toContainText('Preview completed');

  await page.getByLabel('Enable scheduled evaluation').check();
  const saveRequestPromise = page.waitForRequest((request) => (
    new URL(request.url()).pathname === '/admin/audits/rules'
    && request.method() === 'POST'
  ));
  await page.getByRole('button', { name: 'Create rule' }).click();

  const impactDialog = page.getByRole('dialog', { name: 'Confirm rule impact' });
  await expect(impactDialog).toBeVisible();
  await expect(impactDialog.getByText('Findings that will open now')).toBeVisible();
  await expect(impactDialog.locator('[data-audit-impact-count]')).not.toHaveText('—');
  await impactDialog.getByRole('button', { name: 'Confirm and save' }).click();
  const saveRequest = await saveRequestPromise;
  expect(new URLSearchParams(saveRequest.postData() ?? '').get('_token')).toBe(csrfToken);

  await expect(page).toHaveURL(/\/admin\/audits\/rules$/);
  await expect(page.getByText('Browser guided readiness rule', { exact: true }).first()).toBeVisible();
});

test('field picker remains scrollable in a short mobile viewport', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 560 });
  await page.goto('/_browser/login/admin?redirect=/admin/audits/rules/create');

  const criteria = page.locator('[data-audit-criteria]');
  await criteria.getByRole('button', { name: 'Add condition', exact: true }).click();
  await criteria.locator('.audit-rule-condition-row').first().locator('[data-audit-field-button]').click();

  const picker = page.locator('dialog[data-audit-field-picker][open]');
  const results = picker.getByRole('listbox', { name: 'Audit fields' });
  await expect(picker).toBeVisible();
  await expect(results).toBeVisible();
  await expect.poll(async () => results.evaluate((element) => element.scrollHeight > element.clientHeight)).toBe(true);

  const bounds = await results.boundingBox();
  expect(bounds).not.toBeNull();
  await page.mouse.move((bounds?.x ?? 0) + 20, (bounds?.y ?? 0) + 20);
  await page.mouse.wheel(0, 800);
  await expect.poll(async () => results.evaluate((element) => element.scrollTop)).toBeGreaterThan(0);
  await expect(picker.getByRole('button', { name: 'Cancel' })).toBeVisible();
});

test('admin rebuilds a failed imported rule in the mobile editor', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/_browser/login/admin?redirect=/admin/audits/rules');

  const failedRule = page.locator('article').filter({ hasText: 'Imported rule needing rebuild' });
  await expect(failedRule).toContainText('Needs rebuild');
  await failedRule.getByRole('link', { name: 'Edit rule' }).click();

  await expect(page.getByRole('heading', { name: 'Edit Imported rule needing rebuild' })).toBeVisible();
  await expect(page.getByText('This imported rule needs to be rebuilt')).toBeVisible();
  await expectNoHorizontalOverflow(page);

  const criteria = page.locator('[data-audit-criteria]');
  await criteria.getByRole('button', { name: 'Add condition', exact: true }).click();
  const condition = criteria.locator('.audit-rule-condition-row').first();
  await chooseField(page, condition, 'Nation ID', true);
  await condition.getByLabel('Operator for Nation ID').selectOption('gt');
  await condition.getByLabel('Value for Nation ID').fill('0');
  await page.getByLabel('Remediation guidance').fill('Contact an administrator if your nation record is unavailable.');
  await page.getByLabel('Enable scheduled evaluation').check();
  await page.getByRole('button', { name: 'Save rule' }).click();

  const impactDialog = page.getByRole('dialog', { name: 'Confirm rule impact' });
  await expect(impactDialog).toBeVisible();
  await impactDialog.getByRole('button', { name: 'Confirm and save' }).click();

  await expect(page).toHaveURL(/\/admin\/audits\/rules$/);
  await expect(page.locator('article').filter({ hasText: 'Imported rule needing rebuild' })).toBeVisible();
});

test('member report puts actionable findings first on mobile in light and night themes', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.addInitScript(() => {
    if (!window.localStorage.getItem('nexus-theme')) {
      window.localStorage.setItem('nexus-theme', 'light');
    }
  });
  await page.goto('/_browser/login/member?redirect=/audit');

  await expect(page.getByRole('heading', { name: 'Active findings' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Aircraft readiness below target' })).toBeVisible();
  await expect(page.getByText('35 aircraft per city')).toBeVisible();
  await expect(page.getByText('Purchase aircraft until you have at least 50 aircraft for each city.')).toBeVisible();
  await expect(page.getByText('Overdue', { exact: true }).first()).toBeVisible();

  const sectionOrder = await page.locator('main h2').allTextContents();
  expect(sectionOrder.indexOf('Active findings')).toBeLessThan(sectionOrder.indexOf('Alliance city build'));

  const finding = page.locator('article').filter({ hasText: 'Aircraft readiness below target' }).first();
  const reasonTop = await finding.getByText('Why this matched').boundingBox();
  const remediationTop = await finding.getByText('How to resolve it').boundingBox();
  const timestampTop = await finding.getByText('First detected').boundingBox();
  expect(reasonTop?.y).toBeLessThan(timestampTop?.y ?? Number.POSITIVE_INFINITY);
  expect(remediationTop?.y).toBeLessThan(timestampTop?.y ?? Number.POSITIVE_INFINITY);

  await finding.getByText('View 1 more condition').click();
  await expect(finding.getByText('This readiness target is evaluated across 12 cities.')).toBeVisible();
  await expectNoHorizontalOverflow(page);

  await page.evaluate(() => window.localStorage.setItem('nexus-theme', 'night'));
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect.poll(async () => page.evaluate(() => ({
    theme: document.documentElement.dataset.theme,
    colorScheme: document.documentElement.style.colorScheme,
  }))).toEqual({ theme: 'night', colorScheme: 'dark' });
  await expect(page.getByRole('heading', { name: 'Active findings' })).toBeVisible();
  await expectNoHorizontalOverflow(page);
});
