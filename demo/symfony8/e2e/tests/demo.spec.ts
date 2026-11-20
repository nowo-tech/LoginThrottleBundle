import { test, expect } from '@playwright/test';

/** REQ-DEMO-013 — the demo's three firewalls inside the demo platform shell: overview, throttle status after failed sign-ins. */
test.describe('Login Throttle demo', () => {
  test('the overview lists the three firewalls inside the demo platform shell', async ({ page }) => {
    await page.goto('/');
    await page.getByLabel('Email').fill('demo@example.com');
    await page.getByLabel('Password').fill('demo123');
    await page.locator('main button[type="submit"]').click();
    const response = await page.goto('/home');
    expect(response?.ok()).toBeTruthy();
    await expect(page.getByTestId('demo-platform')).toBeVisible();
    await expect(page.getByTestId('demo-nav-demo_home')).toHaveAttribute('aria-current', 'page');
    await expect(page.getByRole('heading', { name: 'Multiple firewalls configuration' })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Configuration comparison' }).locator('tbody tr')).toHaveCount(3);
  });

  test('a failed sign-in shows the error flash and the throttle status', async ({ page }) => {
    await page.goto('/');
    // An unknown address: the failed attempts must not throttle the demo user the other tests sign in with.
    await page.locator('main input[type="email"], main input[name$="[email]"], main input[name="email"]').first().fill('nobody@example.com');
    await page.locator('main input[type="password"]').first().fill('wrong-password');
    await page.locator('main button[type="submit"]').click();
    await expect(page.getByTestId('demo-platform-flashes')).toContainText('Authentication error');
    await expect(page.getByRole('heading', { name: 'Throttle status' })).toBeVisible();
  });
});
