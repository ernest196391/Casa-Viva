/* eslint-disable @typescript-eslint/no-require-imports */
const { test, expect } = require('@playwright/test');

const baseURL = process.env.ORDER_CENTER_BASE_URL || 'http://localhost:8889';

test.describe('CV-LAUNCH-POLISH-001', () => {
  test.use({ viewport: { width: 390, height: 844 } });

  test('portada expone SEO técnico y repara una imagen de categoría rota', async ({ page }) => {
    await page.goto(baseURL + '/', { waitUntil: 'networkidle' });

    await expect(page.locator('h1.cvd-launch-home-h1')).toHaveCount(1);
    await expect(page.locator('h1.cvd-launch-home-h1')).toContainText('Casa Viva');

    const description = page.locator('meta[name="description"][data-cvd-launch-meta="description"]');
    await expect(description).toHaveCount(1);
    await expect(description).toHaveAttribute('content', /productos para el hogar/i);

    const canonical = page.locator('link[rel="canonical"][data-cvd-launch-meta="canonical"]');
    await expect(canonical).toHaveCount(1);
    await expect(canonical).toHaveAttribute('href', /\/$/);

    await page.evaluate(() => {
      const anchor = document.createElement('a');
      anchor.href = '/categoria-producto/habitacion/';
      anchor.textContent = 'Habitación';

      const image = document.createElement('img');
      image.id = 'cvd-launch-broken-category';
      image.alt = '';
      anchor.prepend(image);
      document.body.appendChild(anchor);
    });

    await page.waitForTimeout(50);
    await page.evaluate(() => {
      document.getElementById('cvd-launch-broken-category').src = '/__cvd_missing_category__.jpg';
    });

    const image = page.locator('#cvd-launch-broken-category');
    await expect(image).toHaveClass(/cvd-category-image-fallback/);
    await expect(image).toHaveAttribute('src', /category-fallback\.svg$/);
    await expect(image).toHaveAttribute('alt', /Habitación/i);

    const box = await image.boundingBox();
    expect(box).not.toBeNull();
    expect(box.width).toBeLessThanOrEqual(390);
  });
});
