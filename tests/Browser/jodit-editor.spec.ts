import { expect, test } from '@playwright/test';

test('Jodit preserves the custom-page preview and draft workflow', async ({ page }) => {
  await page.goto('/_browser/login/admin?redirect=/admin/customization');
  await page.getByRole('link', { name: 'Edit', exact: true }).click();

  const editor = page.locator('.jodit-wysiwyg');

  await expect(page.locator('.jodit-container')).toBeVisible();
  await expect(editor).toBeVisible();
  await expect(page.locator('.jodit-toolbar-button_bold')).toHaveCount(1);
  await expect(page.locator('.jodit-toolbar-button_source')).toHaveCount(0);

  for (const control of ['find', 'eraser', 'outdent', 'indent', 'hr', 'symbols', 'fullsize', 'inlineCode', 'codeBlock', 'pageBreak', 'mediaEmbed']) {
    await expect(page.locator(`.jodit-toolbar-button_${control} .jodit-icon`)).toHaveCount(1);
  }

  const editorHeight = await page.locator('.jodit-container').evaluate((element) => element.getBoundingClientRect().height);
  expect(editorHeight).toBeGreaterThanOrEqual(600);

  await editor.fill('Jodit preview smoke');
  await page.getByRole('button', { name: 'Preview', exact: true }).click();

  await expect(page.locator('#customization-preview-pane')).toContainText('Jodit preview smoke');
  await expect(page.locator('#customization-preview-status')).toHaveText('Preview generated');

  await page.getByRole('button', { name: 'Save Draft', exact: true }).click();
  await expect(page.locator('#customization-preview-status')).toHaveText('Draft saved');
});
