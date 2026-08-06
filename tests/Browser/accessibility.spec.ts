import { expect, test, type Page } from '@playwright/test';

type AccessibilityReport = {
  duplicateIds: string[];
  invalidDescriptions: string[];
  invalidLabelTargets: string[];
  unnamedControls: string[];
};

const inspectAccessibleControls = async (page: Page): Promise<AccessibilityReport> => page.evaluate(() => {
  const isVisible = (element: Element): boolean => {
    const htmlElement = element as HTMLElement;
    const style = getComputedStyle(htmlElement);

    return style.display !== 'none'
      && style.visibility !== 'hidden'
      && htmlElement.getClientRects().length > 0;
  };
  const describe = (element: Element): string => {
    const htmlElement = element as HTMLElement;
    const name = element.getAttribute('name');
    const id = element.id;

    return `${element.tagName.toLowerCase()}${id ? `#${id}` : ''}${name ? `[name="${name}"]` : ''}`;
  };
  const referencedText = (ids: string): string => ids
    .split(/\s+/)
    .map((id) => document.getElementById(id)?.textContent?.trim() ?? '')
    .filter(Boolean)
    .join(' ');
  const accessibleName = (element: Element): string => {
    const ariaLabel = element.getAttribute('aria-label')?.trim();
    if (ariaLabel) {
      return ariaLabel;
    }

    const labelledBy = element.getAttribute('aria-labelledby');
    if (labelledBy && referencedText(labelledBy)) {
      return referencedText(labelledBy);
    }

    if (element instanceof HTMLInputElement || element instanceof HTMLSelectElement || element instanceof HTMLTextAreaElement) {
      const labelText = Array.from(element.labels ?? [])
        .map((label) => label.textContent?.trim() ?? '')
        .filter(Boolean)
        .join(' ');
      if (labelText) {
        return labelText;
      }

      if (element instanceof HTMLInputElement && ['button', 'reset', 'submit'].includes(element.type)) {
        return element.value.trim();
      }
    }

    return element.textContent?.trim() || element.getAttribute('title')?.trim() || '';
  };

  const ids = Array.from(document.querySelectorAll<HTMLElement>('[id]')).map((element) => element.id);
  const duplicateIds = [...new Set(ids.filter((id, index) => ids.indexOf(id) !== index))];
  const invalidDescriptions = Array.from(document.querySelectorAll<HTMLElement>('[aria-describedby]'))
    .filter(isVisible)
    .flatMap((element) => (element.getAttribute('aria-describedby') ?? '').split(/\s+/)
      .filter((id) => id && !document.getElementById(id))
      .map((id) => `${describe(element)} -> #${id}`));
  const invalidLabelTargets = Array.from(document.querySelectorAll<HTMLLabelElement>('label[for]'))
    .filter(isVisible)
    .filter((label) => !document.getElementById(label.htmlFor))
    .map((label) => `label[for="${label.htmlFor}"]`);
  const unnamedControls = Array.from(document.querySelectorAll<HTMLElement>([
    'input:not([type="hidden"])',
    'select',
    'textarea',
    '[role="checkbox"]',
    '[role="combobox"]',
    '[role="radio"]',
    '[role="slider"]',
    '[role="spinbutton"]',
    '[role="switch"]',
  ].join(',')))
    .filter(isVisible)
    .filter((element) => !accessibleName(element))
    .map(describe);

  return { duplicateIds, invalidDescriptions, invalidLabelTargets, unnamedControls };
});

const expectAccessibleControls = async (page: Page, path: string): Promise<void> => {
  const report = await inspectAccessibleControls(page);
  const diagnostic = JSON.stringify(report);

  expect(report.duplicateIds, `${path} has duplicate IDs: ${diagnostic}`).toEqual([]);
  expect(report.invalidDescriptions, `${path} has broken aria-describedby references: ${diagnostic}`).toEqual([]);
  expect(report.invalidLabelTargets, `${path} has labels pointing to missing controls: ${diagnostic}`).toEqual([]);
  expect(report.unnamedControls, `${path} has unnamed controls: ${diagnostic}`).toEqual([]);
};

test('representative public, member, and admin forms expose stable accessible names', async ({ page }) => {
  for (const path of ['/login', '/register']) {
    await page.goto(path);
    await expectAccessibleControls(page, path);
  }

  await page.goto('/_browser/login/member?redirect=/grants/city');
  await expectAccessibleControls(page, '/grants/city');

  for (const path of ['/defense/simulators', '/user/alerts']) {
    await page.goto(path);
    await expectAccessibleControls(page, path);
  }

  await page.goto('/_browser/login/admin?redirect=/admin/offshores');
  await expectAccessibleControls(page, '/admin/offshores');
});

test('semantic primary, secondary, and muted text tokens meet AA in both themes', async ({ page }) => {
  await page.goto('/login');

  for (const theme of ['light', 'night']) {
    const ratios = await page.evaluate((selectedTheme) => {
      document.documentElement.dataset.theme = selectedTheme;

      const canvas = document.createElement('canvas');
      canvas.width = 1;
      canvas.height = 1;
      const context = canvas.getContext('2d', { willReadFrequently: true });
      if (!context) {
        throw new Error('Canvas context unavailable.');
      }

      const parseColor = (color: string): [number, number, number, number] => {
        context.clearRect(0, 0, 1, 1);
        context.fillStyle = color;
        context.fillRect(0, 0, 1, 1);
        const [red, green, blue, alpha] = context.getImageData(0, 0, 1, 1).data;

        return [red, green, blue, alpha / 255];
      };
      const composite = (
        foreground: [number, number, number, number],
        background: [number, number, number, number],
      ): [number, number, number] => {
        const alpha = foreground[3];

        return [0, 1, 2].map((index) => (
          foreground[index] * alpha + background[index] * (1 - alpha)
        )) as [number, number, number];
      };
      const luminance = (color: [number, number, number]): number => color
        .map((channel) => channel / 255)
        .map((channel) => channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4)
        .reduce((total, channel, index) => total + channel * [0.2126, 0.7152, 0.0722][index], 0);
      const contrast = (foreground: [number, number, number], background: [number, number, number]): number => {
        const lighter = Math.max(luminance(foreground), luminance(background));
        const darker = Math.min(luminance(foreground), luminance(background));

        return (lighter + 0.05) / (darker + 0.05);
      };

      const host = document.createElement('div');
      document.body.appendChild(host);
      const results: Record<string, number> = {};

      for (const surface of ['--color-base-100', '--color-base-200', '--color-base-300']) {
        for (const token of ['--nexus-text-primary', '--nexus-text-secondary', '--nexus-text-muted']) {
          host.style.backgroundColor = `var(${surface})`;
          host.style.color = `var(${token})`;
          const style = getComputedStyle(host);
          const background = parseColor(style.backgroundColor);
          const foreground = parseColor(style.color);
          results[`${surface}/${token}`] = contrast(
            composite(foreground, background),
            [background[0], background[1], background[2]],
          );
        }
      }

      host.remove();

      return results;
    }, theme);

    for (const [combination, ratio] of Object.entries(ratios)) {
      expect(ratio, `${theme} ${combination} contrast`).toBeGreaterThanOrEqual(4.5);
    }
  }
});

test.describe('touch targets', () => {
  test.use({ hasTouch: true, viewport: { width: 390, height: 844 } });

  test('shared icon actions expose at least a 44px target', async ({ page }) => {
    for (const path of ['/', '/login']) {
      await page.goto(path);
      const undersized = await page.locator('.nexus-icon-button, .account-control__trigger').evaluateAll((elements) => elements
        .filter((element) => {
          const bounds = element.getBoundingClientRect();
          const style = getComputedStyle(element);

          return style.display !== 'none'
            && style.visibility !== 'hidden'
            && (bounds.width < 43.5 || bounds.height < 43.5);
        })
        .map((element) => ({
          label: element.getAttribute('aria-label'),
          height: element.getBoundingClientRect().height,
          width: element.getBoundingClientRect().width,
        })));

      expect(undersized, `${path} contains undersized icon targets`).toEqual([]);
    }
  });
});
