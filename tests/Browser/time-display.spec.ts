import { expect, test } from '@playwright/test';

const beigeAlertsPath = '/_browser/login/admin?redirect=/admin/defense/beige-alerts';

test.use({ timezoneId: 'America/Chicago' });

test('time displays expose exact values and a server-anchored P&W countdown', async ({ page }) => {
  await page.goto(beigeAlertsPath);

  const display = page.locator('[data-nexus-time-display]').first();
  const relativeTime = display.locator('[data-time-relative]');
  const countdown = page.locator('[data-nexus-time-countdown]');

  await expect(display).toHaveAttribute('data-time-state', 'current');
  await expect(display).toHaveAttribute('data-time-enhanced', 'true');
  await expect(relativeTime).toHaveAttribute('aria-label', /Exact time/);
  await relativeTime.focus();
  await expect(relativeTime).toBeFocused();
  await expect.poll(() => display.locator('[data-time-tooltip]').evaluate((element) => (
    getComputedStyle(element, '::before').opacity
  ))).toBe('1');

  await expect(countdown).toHaveAttribute('data-time-countdown-mode', 'pw-turn');
  await expect(countdown).toHaveAttribute('data-time-state', 'current');
  await expect(countdown.locator('[data-time-countdown-target]')).toContainText(/\d/);
  await expect(countdown.locator('[role="timer"]')).toHaveAttribute('aria-label', /Target/);

  await page.evaluate(() => {
    const fixture = document.createElement('span');
    fixture.dataset.testid = 'dst-countdown';
    fixture.dataset.nexusTimeCountdown = '';
    fixture.dataset.timeTarget = '2026-03-08T03:30:00-05:00';
    fixture.dataset.serverReference = '2026-03-08T01:30:00-06:00';
    fixture.dataset.timeLabel = 'DST boundary';
    fixture.dataset.timeExpiredText = 'Target reached';
    fixture.dataset.clockSkewThreshold = '60';
    fixture.innerHTML = '<span data-time-countdown-value role="timer"></span><time data-time-countdown-target></time><span data-time-clock-warning hidden></span><span data-time-status></span>';
    document.body.append(fixture);

    const ambiguousFixture = document.createElement('span');
    ambiguousFixture.dataset.testid = 'ambiguous-countdown';
    ambiguousFixture.dataset.nexusTimeCountdown = '';
    ambiguousFixture.dataset.timeTarget = '2026-03-08T02:30:00';
    ambiguousFixture.dataset.serverReference = '2026-03-08T01:30:00-06:00';
    document.body.append(ambiguousFixture);

    document.dispatchEvent(new Event('nexus:time-refresh'));
  });

  const dstCountdown = page.getByTestId('dst-countdown');
  await expect(dstCountdown.locator('[role="timer"]')).toHaveText('1h 00m 00s');
  await expect(dstCountdown.locator('[data-time-countdown-target]')).toContainText('3:30:00 AM');
  await expect(dstCountdown.locator('[data-time-countdown-target]')).toContainText('CDT');
  await expect(page.getByTestId('ambiguous-countdown')).toHaveAttribute('data-time-state', 'invalid');

  await page.goto('/admin/audit-logs');
  await expect(page.getByRole('heading', { name: 'Audit Logs' })).toBeVisible();

  const auditTimestamp = page.locator('tbody [data-nexus-time-display]').first();
  await expect(auditTimestamp).toHaveAttribute('data-time-state', 'current');
  await expect(auditTimestamp.locator('[data-time-exact]')).toBeVisible();
});

test('countdown detects clock skew and continues from server time', async ({ page }) => {
  await page.addInitScript(() => {
    const browserNow = Date.now.bind(Date);
    Date.now = () => browserNow() + 10 * 60 * 1000;
  });

  await page.goto(beigeAlertsPath);

  const countdown = page.locator('[data-nexus-time-countdown]');

  await expect(countdown).toHaveAttribute('data-clock-skewed', 'true');
  await expect(countdown).toHaveAttribute('data-time-state', 'current');
  await expect(countdown.locator('[data-time-clock-warning]')).toBeVisible();
  await expect(countdown.locator('[data-time-clock-warning]')).toContainText('server time is being used');
});

test('countdown pauses while hidden, recalculates on resume, and can become stale', async ({ page }) => {
  await page.goto(beigeAlertsPath);

  const countdown = page.locator('[data-nexus-time-countdown]');
  await expect(countdown).toHaveAttribute('data-time-state', 'current');

  const beforePause = await countdown.evaluate((element) => {
    Object.defineProperty(document, 'visibilityState', {
      configurable: true,
      value: 'hidden',
    });
    document.dispatchEvent(new Event('visibilitychange'));

    return Number((element as HTMLElement).dataset.timeRemainingSeconds);
  });

  await page.waitForTimeout(1_500);
  await expect(countdown).toHaveAttribute('data-time-remaining-seconds', String(beforePause));

  await countdown.evaluate(() => {
    Object.defineProperty(document, 'visibilityState', {
      configurable: true,
      value: 'visible',
    });
    document.dispatchEvent(new Event('visibilitychange'));
  });

  await expect.poll(async () => Number(await countdown.getAttribute('data-time-remaining-seconds')))
    .toBeLessThan(beforePause);

  await countdown.evaluate((element) => {
    (element as HTMLElement).dataset.timeStaleAfter = '0';
    document.dispatchEvent(new Event('nexus:time-refresh'));
  });

  await expect(countdown).toHaveAttribute('data-time-state', 'stale');
  await expect(countdown.locator('[role="timer"]')).toHaveText('Refresh for a current countdown');
});

test('time and countdown remain understandable without JavaScript', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();

  await page.goto(beigeAlertsPath);

  const display = page.locator('[data-nexus-time-display]').first();
  const countdown = page.locator('[data-nexus-time-countdown]');

  await expect(display).toHaveAttribute('data-time-state', 'server');
  await expect(display.locator('[data-time-relative]')).toContainText(/now|ago|from now/i);
  await expect(display.locator('[data-time-relative]')).toHaveAttribute('aria-label', /Exact time/);
  await expect(countdown).toHaveAttribute('data-time-state', 'server');
  await expect(countdown.locator('[role="timer"]')).toContainText(/\d+[hms]/);
  await expect(countdown.locator('[data-time-countdown-target]')).toContainText(/\d/);

  await context.close();
});
