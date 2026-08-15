import { expect, test, type Page } from '@playwright/test';

test.setTimeout(60_000);

const expectNoHorizontalOverflow = async (page: Page): Promise<void> => {
  const overflow = await page.evaluate(() => ({
    document: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    body: document.body.scrollWidth - document.documentElement.clientWidth,
  }));

  expect(overflow.document).toBeLessThanOrEqual(1);
  expect(overflow.body).toBeLessThanOrEqual(1);
};

test('command desk is exception-first and preserves the admin theme', async ({ page }) => {
  await page.addInitScript(() => window.localStorage.setItem('nexus-theme', 'night'));
  await page.goto('/_browser/login/admin?redirect=/admin/milcom');

  await expect(page.getByRole('heading', { name: 'Milcom dashboard' })).toBeVisible();
  await expect(page.locator('[data-milcom-value="urgent_counters"]')).toHaveText('2');
  await expect(page.getByRole('link', { name: 'Browser Counter Aggressor, open nation on Politics & War in a new tab' })).toBeVisible();
  await expect(page.getByText('Discord room failed', { exact: true })).toBeVisible();
  await expect.poll(async () => page.evaluate(() => document.documentElement.dataset.theme)).toBe('night');
  await expectNoHorizontalOverflow(page);

  const urgentCounter = page.locator('article').filter({ hasText: 'Counter Browser Counter Aggressor' });
  await urgentCounter.getByRole('link', { name: 'Review' }).click();
  await expect(page.getByRole('heading', { name: 'Fast Counters' })).toBeVisible();
  await expect(page.locator('#counter-preflight-title')).toContainText('Browser Counter Aggressor');
});

test('officer navigates targets by keyboard and batch approves and dispatches a wave', async ({ page }) => {
  await page.goto('/_browser/login/admin?redirect=/admin/milcom/plans');
  await page.getByRole('link', { name: 'Browser Coalition Dawn' }).click();

  await expect(page.getByRole('heading', { name: 'Browser Coalition Dawn' })).toBeVisible();
  await expect(page.locator('[data-milcom-field="target_name"]')).toHaveAttribute('target', '_blank');
  await expect(page.locator('[data-milcom-field="leader_name"]')).toHaveAttribute('rel', 'noopener noreferrer');
  const nationLinkStyle = await page.locator('[data-milcom-field="target_name"]').evaluate((link) => {
    const style = window.getComputedStyle(link);

    return { color: style.color, decoration: style.textDecorationLine };
  });
  expect(nationLinkStyle.decoration).toContain('underline');
  expect(nationLinkStyle.color).not.toBe('rgba(0, 0, 0, 0)');
  await expect(page.locator('dl[aria-label="Target military"] [data-milcom-military="soldiers"]')).toHaveText('80,000');
  await expect(page.locator('dl[aria-label="Assigned nation military"] [data-milcom-military="aircraft"]').first()).toHaveText('1,200');
  const initialTargets = page.locator('[data-milcom-select-objective]');
  await expect(initialTargets).toHaveCount(2);
  await initialTargets.nth(1).click();
  await expect(page.locator('#selected-target-title')).toContainText('Browser Standard Target');
  await expect(page).toHaveURL(/objective=\d+/);
  await page.locator('[data-milcom-workbench]').evaluate((workbench) => workbench.scrollIntoView({ block: 'start' }));
  const inspectorLayout = await page.locator('[data-milcom-workbench]').evaluate((workbench) => {
    const targetList = workbench.querySelector<HTMLElement>('[data-milcom-objective-list]');
    const inspectorBody = workbench.querySelector<HTMLElement>('[data-milcom-inspector-scroll]');
    const style = window.getComputedStyle(workbench);

    return {
      position: style.position,
      top: workbench.getBoundingClientRect().top,
      targetOverflowY: targetList ? window.getComputedStyle(targetList).overflowY : '',
      inspectorOverflowY: inspectorBody ? window.getComputedStyle(inspectorBody).overflowY : '',
      height: workbench.getBoundingClientRect().height,
      viewportHeight: window.innerHeight,
    };
  });
  expect(inspectorLayout.position).toBe('sticky');
  expect(inspectorLayout.top).toBeGreaterThanOrEqual(64);
  expect(inspectorLayout.targetOverflowY).toBe('auto');
  expect(inspectorLayout.inspectorOverflowY).toBe('auto');
  expect(inspectorLayout.height).toBeLessThan(inspectorLayout.viewportHeight);

  const targetList = page.locator('[data-milcom-objective-list]');
  const documentScrollBeforeListScroll = await page.evaluate(() => window.scrollY);
  await targetList.evaluate((list) => {
    const spacer = document.createElement('div');
    spacer.dataset.browserScrollSpacer = 'true';
    spacer.style.height = '1600px';
    list.append(spacer);
    list.scrollTop = list.scrollHeight;
  });
  expect(await targetList.evaluate((list) => list.scrollTop)).toBeGreaterThan(0);
  expect(await page.evaluate(() => window.scrollY)).toBe(documentScrollBeforeListScroll);
  await targetList.evaluate((list) => {
    list.querySelector('[data-browser-scroll-spacer]')?.remove();
    list.scrollTop = 0;
  });

  await page.getByRole('button', { name: 'Edit target' }).click();
  await expect(page.locator('#staffing-controls-title')).toBeInViewport();
  await expect(page.locator('.milcom-plan-inspector__actions')).toBeInViewport();
  expect(await page.locator('[data-milcom-inspector-scroll]').evaluate((inspector) => inspector.scrollTop)).toBeGreaterThan(0);
  await initialTargets.first().press('Enter');
  await expect.poll(() => page.locator('[data-milcom-inspector-scroll]').evaluate((inspector) => inspector.scrollTop)).toBe(0);
  await initialTargets.nth(1).press('Enter');
  await expect(page.locator('#selected-target-title')).toContainText('Browser Standard Target');
  const selectedTargetUrl = page.url();
  let progressPolls = 0;
  await page.route(/\/api\/v1\/milcom\/operations\/\d+\/recommendations$/, async (route) => {
    await route.fulfill({
      status: 202,
      contentType: 'application/json',
      body: JSON.stringify({
        data: { recommendation_run: { id: 999, status: 'queued', progress_percent: 0 } },
        meta: { generation_version: 1 },
        links: { progress: '/api/v1/milcom/recommendation-runs/999' },
        message: 'Recommendation generation queued.',
      }),
    });
  });
  await page.route('**/api/v1/milcom/recommendation-runs/999', async (route) => {
    progressPolls += 1;
    const completed = progressPolls > 6;
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          recommendation_run: {
            id: 999,
            status: completed ? 'succeeded' : 'queued',
            progress_percent: completed ? 100 : 0,
          },
        },
        meta: {},
        links: {},
      }),
    });
  });
  const regenerateButton = page.locator('form[data-milcom-command="recommend"] button[type="submit"]');
  const planRefresh = page.waitForNavigation({ waitUntil: 'domcontentloaded' });
  await regenerateButton.click();
  await expect(page.locator('[data-milcom-recommendation-progress]')).toBeVisible();
  await expect(regenerateButton).toBeDisabled();
  await expect(page.locator('[data-milcom-progress-label]')).toHaveText('Waiting for the background worker');
  await planRefresh;
  await expect(page).toHaveURL(selectedTargetUrl);
  await expect(page.locator('[data-milcom-status]')).toContainText('Teams are ready.');
  await expect(page.locator('[data-milcom-result-message]')).toHaveText('Teams are ready. Targets, warnings, and assignments are up to date.');
  await expect(regenerateButton).toBeEnabled();
  await expect(page.locator('#selected-target-title')).toContainText('Browser Standard Target');

  const targets = page.locator('[data-milcom-select-objective]');
  await expect(targets).toHaveCount(2);
  await targets.first().focus();
  await page.keyboard.press('j');
  await expect(targets.nth(1)).toBeFocused();
  await expect(targets.nth(1)).toHaveAttribute('aria-selected', 'true');
  await expect(page.locator('#selected-target-title')).toContainText('Browser Standard Target');
  await expect(page.locator('[data-milcom-field="target_name"]')).toHaveAttribute('href', /politicsandwar\.com\/nation\/id=\d+/);

  await page.getByLabel('Select page').check();
  await expect(page.locator('[data-milcom-objective-checkbox]:checked')).toHaveCount(2);
  await expect(page.locator('[data-milcom-selected-count]')).toHaveText('2');
  await page.getByLabel('Select page').uncheck();

  await page.getByRole('button', { name: 'Approve all eligible' }).click();
  const approveAllResponse = page.waitForResponse((response) =>
    response.url().includes('/objectives/approve-eligible') && response.request().method() === 'POST'
  );
  const approveAllNavigation = page.waitForNavigation({ waitUntil: 'domcontentloaded' });
  await page.getByRole('button', { name: 'Approve', exact: true }).click();
  await expect((await approveAllResponse).status()).toBe(200);
  await approveAllNavigation;
  await expect(page.locator('[data-milcom-result]')).toBeVisible();
  await expect(page.locator('[data-milcom-result-message]')).toHaveText('Approved 2 targets. They are ready to send to Discord.');

  await page.getByRole('button', { name: 'Finalize wave' }).click();
  const confirmationDialog = page.locator('#nexus-confirmation-dialog');
  await expect(confirmationDialog).toBeVisible();
  await expect(confirmationDialog.locator('#nexus-confirmation-message')).toContainText('Finalize this wave and open the live dashboard?');
  const finalizationNavigation = page.waitForNavigation({ waitUntil: 'domcontentloaded' });
  await confirmationDialog.getByRole('button', { name: 'Finalize wave' }).click();
  await finalizationNavigation;
  await expect(page.getByRole('heading', { name: 'This operation is live' })).toBeVisible();
  await expect(page.locator('.nexus-status').filter({ hasText: 'Active' }).first()).toBeVisible();
  await expect(page.locator('[data-milcom-objective-row]')).toHaveCount(2);
  await expect(page.locator('[data-milcom-objective-row]').first()).toContainText('Assigned:');
  await expect(page.getByRole('link', { name: 'Browser Member Nation' }).first()).toHaveAttribute('target', '_blank');
  await expect(page.getByRole('button', { name: 'Create remaining rooms' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Send targets in-game' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Export target list' })).toHaveAttribute('href', /export\.csv$/);
  await expect(page.getByRole('button', { name: 'New wave' })).toBeVisible();
  await expectNoHorizontalOverflow(page);

  const planPath = new URL(page.url()).pathname;
  await page.getByRole('tab', { name: 'Stats' }).click();
  await expect(page.getByRole('heading', { name: 'Operation stats' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Side comparison' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Current forces' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Battle results' })).toBeVisible();
  await expect(page.getByText('Military data:', { exact: false })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Current wars' })).toBeVisible();
  await expect(page.getByText('No active wars are linked to this wave')).toBeVisible();
  await expect(page.locator('[data-milcom-objective-row]')).toHaveCount(0);
  await expectNoHorizontalOverflow(page);
  await page.getByRole('tab', { name: 'Plan' }).click();
  await expect(page.locator('[data-milcom-objective-row]')).toHaveCount(2);

  await page.getByRole('button', { name: 'Create Discord room' }).click();
  await expect(page.locator('[data-milcom-status]')).toContainText('The Discord room is being created.');

  await page.goto(`${planPath}?filter=approved`);
  await expect(page.locator('[data-milcom-objective-row]')).toHaveCount(1);
  for (const checkbox of await page.locator('[data-milcom-objective-checkbox]').all()) {
    await checkbox.check();
  }
  await page.locator('[data-milcom-batch-command="dispatch"]').click();
  await expect(page.locator('[data-milcom-status]')).toContainText('Queued a room for 1 target.');

  await page.goto(`${planPath}?filter=dispatched`);
  await expect(page.locator('[data-milcom-objective-row]')).toHaveCount(2);
  await expect(page.locator('[data-milcom-objective-row] .nexus-status').first()).toHaveText('Sent to Discord');
});

test('plan approval shows warning reasons and accepts an officer reason', async ({ page }) => {
  await page.goto('/_browser/login/admin?redirect=/admin/milcom/plans');
  await page.getByRole('link', { name: 'Browser Coalition Dawn' }).click();

  const objectiveId = await page.locator('[data-milcom-select-objective]').first().getAttribute('data-objective-id');
  let submittedReason = '';
  await page.route(`**/api/v1/milcom/objectives/${objectiveId}/approve`, async (route) => {
    const body = route.request().postDataJSON() as { override_reason?: string };

    if (!body.override_reason) {
      await route.fulfill({
        status: 409,
        contentType: 'application/json',
        body: JSON.stringify({
          message: 'The final checks failed.',
          blockers: [{ code: 'warning_override_required', message: 'This target has warnings.' }],
          warnings: [{
            code: 'missing_discord_link',
            message: 'This nation has no linked Discord account.',
            context: { nation_id: 200001 },
          }],
          meta: {},
          links: {},
        }),
      });
      return;
    }

    submittedReason = body.override_reason;
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          objective: { id: Number(objectiveId), status: 'approved', operation: { generation_version: 1 } },
          dispatch: null,
          warnings: [],
        },
        meta: { generation_version: 1 },
        links: {},
        message: 'Target approved.',
      }),
    });
  });

  await page.getByRole('button', { name: 'Approve target' }).click();
  await expect(page.locator('[data-milcom-feedback-title]')).toHaveText('Review this target');
  await expect(page.locator('[data-milcom-list="warnings"]')).toContainText('Browser Member Nation');
  await expect(page.getByRole('link', { name: 'Browser Member Nation' }).last()).toHaveAttribute('target', '_blank');

  const reason = page.getByLabel('Why approve despite these warnings?');
  await expect(reason).toBeVisible();
  await expect(reason).toHaveAttribute('required', '');
  await reason.fill('The officer confirmed delivery through the alliance Discord role.');
  await page.getByRole('button', { name: 'Approve target' }).click();
  await expect.poll(() => submittedReason).toBe('The officer confirmed delivery through the alliance Discord role.');
});

test('fast counter dispatch and failed-room retry remain single-action workflows', async ({ page }) => {
  await page.goto('/_browser/login/admin?redirect=/admin/milcom/counters');

  await expect(page.locator('[data-milcom-value="overdue_declarations"]')).toHaveText('1');
  await page.getByLabel('War filter').selectOption('overdue');
  await page.getByRole('button', { name: 'Filter' }).click();
  await expect(page).toHaveURL(/filter=overdue/);
  await expect(page.locator('[data-milcom-select-incident]')).toHaveCount(1);
  await page.locator('[data-milcom-select-incident]').click();
  await expect(page.locator('[data-milcom-declaration-overdue]')).toBeVisible();
  await expect(page.locator('[data-milcom-declaration-overdue]')).toContainText('This team remains assigned');

  await page.goto('/admin/milcom/counters');

  const readyIncident = page.locator('[data-milcom-select-incident][aria-label="View counter against Browser Counter Aggressor"]');
  await readyIncident.click();
  await expect(page.locator('#counter-preflight-title')).toContainText('Browser Counter Aggressor');
  await expect(page.locator('[data-milcom-field="aggressor_name"]')).toHaveAttribute('target', '_blank');
  await expect(page.locator('[data-milcom-field="defender_name"]')).toHaveAttribute('href', /politicsandwar\.com\/nation\/id=\d+/);
  await expect(page.locator('dl[aria-label="Counter target military"] [data-milcom-military="tanks"]')).toHaveText('5,000');
  await expect(page.locator('dl[aria-label="Assigned nation military"] [data-milcom-military="ships"]').first()).toHaveText('120');
  await expect(page.locator('[data-milcom-list="team"] article')).toHaveCount(3);
  await expect(page.locator('[data-milcom-list="team"]')).not.toContainText('Browser Member Nation');
  await page.getByRole('button', { name: 'Approve and create room' }).click();
  await expect(page.locator('[data-milcom-status]')).toContainText('Target approved. The Discord room is being created.');
  await expect(page.locator('[data-milcom-dispatch-state]')).toBeVisible();

  const failedIncident = page.locator('[data-milcom-select-incident][aria-label="View counter against Browser Failed Delivery Target"]');
  await failedIncident.click();
  const retry = page.getByRole('button', { name: 'Retry Discord room' });
  await expect(retry).toBeVisible();
  await retry.click();
  await expect(page.locator('[data-milcom-status]')).toContainText('Discord room retry started.');
  await expect(page.locator('[data-milcom-dispatch-state]')).toBeVisible();
  await expect(page.locator('[data-milcom-field="dispatch_status"]')).toContainText('Queued');
});

test('background counter refresh keeps the selected incident URL stable', async ({ page }) => {
  await page.goto('/_browser/login/admin?redirect=/admin/milcom/counters');
  const selected = page.locator('[data-milcom-select-incident][aria-label="View counter against Browser Counter Aggressor"]');
  const incidentId = await selected.getAttribute('data-incident-id');
  let detailCalls = 0;
  let progressPolls = 0;

  await page.route(`**/api/v1/milcom/incidents/${incidentId}`, async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    detailCalls += 1;

    if (detailCalls === 1) {
      body.data.incident.objective.recommendation = {
        ...body.data.incident.objective.recommendation,
        run_id: 999,
        status: 'queued',
        progress_percent: 0,
        trigger: 'counter_auto_refresh',
      };
    }

    await route.fulfill({ response, json: body });
  });
  await page.route('**/api/v1/milcom/recommendation-runs/999', async (route) => {
    progressPolls += 1;
    const complete = progressPolls > 1;
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          recommendation_run: {
            id: 999,
            status: complete ? 'succeeded' : 'running',
            progress_percent: complete ? 100 : 50,
            trigger: 'counter_auto_refresh',
          },
        },
        meta: {},
        links: {},
      }),
    });
  });

  await selected.click();
  await expect(page).toHaveURL(new RegExp(`incident=${incidentId}`));
  const selectedUrl = page.url();
  await expect(page.locator('[data-milcom-progress-label]')).toContainText('Refreshing counter team');
  await expect.poll(() => detailCalls).toBeGreaterThan(1);
  await expect(page).toHaveURL(selectedUrl);
  await expect(page.locator('#counter-preflight-title')).toContainText('Browser Counter Aggressor');
});

test('plan creation commits normalized scope and becomes monitoring-only on narrow screens', async ({ page }) => {
  await page.goto('/_browser/login/admin?redirect=/admin/milcom/plans/create');
  await page.getByLabel('Plan name').fill('Browser New Wave');
  await page.getByRole('button', { name: 'Create plan and choose targets' }).click();

  await expect(page.getByRole('heading', { name: 'Browser New Wave' })).toBeVisible();
  await page.getByLabel('Add an alliance').nth(0).fill('Browser Test Alliance');
  await page.locator('[data-alliance-result-id="9001"]').click();
  await page.getByLabel('Add an alliance').nth(1).fill('Browser Test Opposition');
  await page.locator('[data-alliance-result-id="9002"]').click();
  await expect(page.getByRole('link', { name: /Browser Test Alliance, open alliance/ })).toHaveAttribute('target', '_blank');
  await expect(page.locator('[data-alliance-picker][data-alliance-side="friendly"] img')).toHaveAttribute('src', /^data:image/);

  await page.getByText('Enter alliance IDs manually').click();
  await expect(page.getByLabel('Friendly alliance IDs')).toHaveValue('9001');
  await expect(page.getByLabel('Enemy alliance IDs')).toHaveValue('9002');
  await page.getByRole('button', { name: 'Save and build targets' }).click();
  await expect(page.locator('[data-milcom-objective-row]').first()).toBeVisible();

  await page.setViewportSize({ width: 390, height: 844 });
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.getByText('Mobile view', { exact: true })).toBeVisible();
  await expect(page.locator('[data-milcom-batch-actions]')).toBeHidden();
  await expect(page.locator('[data-milcom-inspector]')).toBeHidden();
  await page.locator('[data-milcom-select-objective]').first().click();
  await expect(page.locator('[data-milcom-inspector]')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Back to targets' })).toBeVisible();
  await expect.poll(() => page.locator('[data-milcom-inspector]').evaluate((inspector) => window.getComputedStyle(inspector).position)).toBe('fixed');
  await page.getByRole('button', { name: 'Back to targets' }).click();
  await expect(page.locator('[data-milcom-inspector]')).toBeHidden();
  await expect(page.locator('[data-milcom-select-objective]').first()).toBeFocused();
  await expectNoHorizontalOverflow(page);
});
