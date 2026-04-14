import { test, expect } from '@playwright/test';
import { ADMIN, LEARNER, loginAs, logout } from '../helpers';

/**
 * 01-auth — authentication journeys
 *
 * Covers:
 *   - Login page renders correctly
 *   - Valid admin credentials → dashboard
 *   - Valid learner credentials → dashboard
 *   - Wrong password shows error
 *   - Unauthenticated request is redirected to /login
 *   - Logout returns to /login and invalidates the session
 */

test.describe('Authentication', () => {
  test('login page renders with branding', async ({ page }) => {
    await page.goto('/login');

    await expect(page.locator('h1')).toContainText('Research Services');
    await expect(page.locator('#username')).toBeVisible();
    await expect(page.locator('#password')).toBeVisible();
    await expect(page.locator('button[type="submit"]')).toBeVisible();
  });

  test('admin: valid credentials redirect to dashboard', async ({ page }) => {
    await loginAs(page, ADMIN.username, ADMIN.password);

    // Dashboard heading
    await expect(page.locator('h1')).toContainText('Welcome back');
    await expect(page).toHaveURL(/\/dashboard/);
  });

  test('learner: valid credentials redirect to dashboard', async ({ page }) => {
    await loginAs(page, LEARNER.username, LEARNER.password);

    await expect(page.locator('h1')).toContainText('Welcome back');
    await expect(page).toHaveURL(/\/dashboard/);
  });

  test('wrong password shows error message', async ({ page }) => {
    await page.goto('/login');

    await page.fill('#username', ADMIN.username);
    await page.fill('#password', 'WrongPassword999!');
    await page.click('button[type="submit"]');

    // Stay on login page; error visible
    await expect(page).toHaveURL(/\/login/);
    await expect(
      page.locator('.text-red-700, [class*="red"]').first(),
    ).toBeVisible({ timeout: 8_000 });
  });

  test('unauthenticated request redirects to login', async ({ page }) => {
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login/);
  });

  test('logout returns to login page', async ({ page }) => {
    await loginAs(page, LEARNER.username, LEARNER.password);

    await logout(page);

    await expect(page).toHaveURL(/\/login/);
    await expect(page.locator('h1')).toContainText('Research Services');
  });

  test('session invalidated after logout — /dashboard redirects', async ({ page }) => {
    await loginAs(page, LEARNER.username, LEARNER.password);
    await logout(page);

    // Attempt to go back to dashboard without logging in again
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login/);
  });
});
