import { Buffer } from 'node:buffer';
import { expect, test, type Page } from '@playwright/test';

const installChartStub = async (page: Page): Promise<void> => {
  await page.addInitScript(() => {
    class ChartStub {
      static defaults: Record<string, unknown> = {};
      static instances: Record<number, ChartStub> = {};
      static plugins: Array<Record<string, (...args: unknown[]) => void>> = [];
      static nextId = 1;

      static register(...plugins: Array<Record<string, (...args: unknown[]) => void>>): void {
        ChartStub.plugins.push(...plugins);
      }

      id: number;
      canvas: HTMLCanvasElement;
      config: Record<string, unknown>;
      data: Record<string, unknown>;

      constructor(target: HTMLCanvasElement | CanvasRenderingContext2D, config: Record<string, unknown>) {
        this.id = ChartStub.nextId++;
        this.canvas = target instanceof HTMLCanvasElement ? target : target.canvas;
        this.config = config;
        this.data = (config.data as Record<string, unknown>) ?? {};

        ChartStub.plugins.forEach((plugin) => plugin.beforeInit?.(this));
        ChartStub.instances[this.id] = this;
        ChartStub.plugins.forEach((plugin) => plugin.afterInit?.(this));
        ChartStub.plugins.forEach((plugin) => plugin.afterUpdate?.(this));
      }

      update(): void {
        ChartStub.plugins.forEach((plugin) => plugin.afterUpdate?.(this));
      }

      destroy(): void {
        ChartStub.plugins.forEach((plugin) => plugin.afterDestroy?.(this));
        delete ChartStub.instances[this.id];
      }
    }

    Object.defineProperty(window, 'Chart', {
      configurable: true,
      writable: true,
      value: ChartStub,
    });
  });

  await page.route('**/chart.umd.min.js', (route) => route.abort('blockedbyclient'));
};

test('every rendered chart receives a readable summary and keyboard-accessible table', async ({ page }) => {
  await installChartStub(page);
  await page.goto('/_browser/login/admin?redirect=/admin/members');

  const equivalent = page.locator('[data-chart-equivalent]').first();
  await expect(equivalent).toBeVisible();
  await expect(equivalent.locator('.nexus-chart-equivalent__summary')).toContainText(/series.*point/i);

  const toggle = equivalent.locator('summary');
  await toggle.focus();
  await expect(toggle).toBeFocused();
  await toggle.press('Enter');
  await expect(equivalent.locator('table')).toBeVisible();
  await expect(equivalent.locator('thead')).toContainText('Label');
  await expect(equivalent.getByRole('button', { name: /Download .* data as CSV/i })).toBeVisible();
});

test('full chart CSV escapes formula labels while the inline table stays readable and bounded', async ({ page }) => {
  await installChartStub(page);
  await page.goto('/_browser/login/admin?redirect=/admin/members');

  await page.evaluate(() => {
    const host = document.createElement('div');
    const canvas = document.createElement('canvas');
    canvas.id = 'formula-test-chart';
    host.append(canvas);
    document.querySelector('main')?.append(host);

    const labels = Array.from({ length: 51 }, (_, index) => index === 0 ? '=CMD()' : `Row ${index + 1}`);
    const chart = new window.Chart(canvas, {
      type: 'line',
      data: {
        labels,
        datasets: [
          { label: 'Income', data: labels.map((_, index) => index + 1) },
          { label: 'Expenses', data: labels.map((_, index) => index / 2) },
        ],
      },
      options: { animation: false },
    });

    (window as typeof window & { syntheticAccessibilityChart?: unknown }).syntheticAccessibilityChart = chart;
  });

  const equivalent = page.locator('#formula-test-chart + [data-chart-equivalent]');
  await expect(equivalent).toBeVisible();
  await expect(equivalent.locator('.nexus-chart-equivalent__summary')).toContainText('2 series across 51 points');
  await equivalent.locator('summary').click();
  await expect(equivalent.getByRole('rowheader', { name: '=CMD()' })).toBeVisible();
  await expect(equivalent.locator('tbody tr')).toHaveCount(50);
  await expect(equivalent.locator('.nexus-chart-equivalent__note')).toContainText('CSV contains all 51 rows');

  const distinction = await page.evaluate(() => {
    const chart = (window as typeof window & { syntheticAccessibilityChart?: { data: { datasets: Array<Record<string, unknown>> } } })
      .syntheticAccessibilityChart;

    return {
      borderDash: chart?.data.datasets[1].borderDash,
      pointStyle: chart?.data.datasets[1].pointStyle,
    };
  });
  expect(distinction.borderDash).toEqual([7, 3]);
  expect(distinction.pointStyle).toBe('rect');

  const [download] = await Promise.all([
    page.waitForEvent('download'),
    equivalent.getByRole('button', { name: 'Download formula-test-chart data as CSV' }).click(),
  ]);
  const stream = await download.createReadStream();
  const chunks: Buffer[] = [];
  for await (const chunk of stream) {
    chunks.push(Buffer.from(chunk));
  }
  const csv = Buffer.concat(chunks).toString('utf8');

  expect(csv).toContain('"\'=CMD()"');
  expect(csv).toContain('"Row 51"');
});
