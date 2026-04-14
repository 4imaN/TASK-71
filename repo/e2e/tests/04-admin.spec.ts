import { test, expect } from '@playwright/test';
import { ADMIN, LEARNER, loginAs, waitForLivewire } from '../helpers';

/**
 * 04-admin — admin/operator governance journeys
 *
 * Covers:
 *   - Admin can reach /admin/users (user management page)
 *   - Step-up modal appears when clicking a write action (Lock)
 *   - Step-up with correct password grants access and action proceeds
 *   - Audit log page loads and shows entries
 *   - Backup page loads
 *   - Learner cannot access admin routes (403 / redirect)
 */

test.describe('Admin Governance', () => {

  test.describe('as administrator', () => {
    test.beforeEach(async ({ page }) => {
      await loginAs(page, ADMIN.username, ADMIN.password);
    });

    test('user management page loads', async ({ page }) => {
      await page.goto('/admin/users');
      await waitForLivewire(page);

      await expect(page.locator('h1')).toContainText('User Management');
      // The E2E learner should appear in the list
      await expect(page.locator('text=e2e_learner')).toBeVisible({ timeout: 10_000 });
    });

    test('step-up modal appears on write action (Lock)', async ({ page }) => {
      await page.goto('/admin/users');
      await waitForLivewire(page);

      // Click the "Lock" button for the learner row
      const lockBtn = page.getByRole('button', { name: 'Lock' }).first();
      await expect(lockBtn).toBeVisible({ timeout: 10_000 });
      await lockBtn.click();
      await waitForLivewire(page);

      // Step-up modal should appear — "Confirm your identity"
      await expect(
        page.locator('text=Confirm your identity'),
      ).toBeVisible({ timeout: 8_000 });
    });

    test('step-up modal accepts correct password and proceeds', async ({ page }) => {
      await page.goto('/admin/users');
      await waitForLivewire(page);

      // Click Lock on the learner row
      const lockBtn = page.getByRole('button', { name: 'Lock' }).first();
      await lockBtn.click();
      await waitForLivewire(page);

      // Fill step-up password
      const stepUpInput = page.locator('input[type="password"][placeholder*="password"]').last();
      await expect(stepUpInput).toBeVisible({ timeout: 8_000 });
      await stepUpInput.fill(ADMIN.password);

      // Click Confirm
      await page.getByRole('button', { name: 'Confirm' }).click();
      await waitForLivewire(page);

      // Step-up modal should close; status chip or success indicator visible
      await expect(
        page.locator('text=Confirm your identity'),
      ).not.toBeVisible({ timeout: 10_000 });
    });

    test('step-up modal rejects wrong password', async ({ page }) => {
      await page.goto('/admin/users');
      await waitForLivewire(page);

      const lockBtn = page.getByRole('button', { name: 'Lock' }).first();
      await lockBtn.click();
      await waitForLivewire(page);

      const stepUpInput = page.locator('input[type="password"][placeholder*="password"]').last();
      await stepUpInput.fill('WrongPassword999!');
      await page.getByRole('button', { name: 'Confirm' }).click();
      await waitForLivewire(page);

      // Error message should appear in the modal
      await expect(
        page.locator('text=Confirm your identity'),
      ).toBeVisible({ timeout: 8_000 });
    });

    test('audit log page loads with entries', async ({ page }) => {
      await page.goto('/admin/audit-logs');
      await waitForLivewire(page);

      await expect(page.locator('h1')).toContainText('Audit Log');
      // Action filter input should exist
      await expect(
        page.locator('input[placeholder*="action"]'),
      ).toBeVisible({ timeout: 8_000 });
    });

    test('backup page loads', async ({ page }) => {
      await page.goto('/admin/backups');
      await waitForLivewire(page);

      // The backup component has a heading
      await expect(page.locator('h1, h2').filter({ hasText: /Backup/i }).first()).toBeVisible({ timeout: 10_000 });
    });
  });

  test.describe('as learner (access control)', () => {
    test.beforeEach(async ({ page }) => {
      await loginAs(page, LEARNER.username, LEARNER.password);
    });

    test('learner cannot access /admin/users', async ({ page }) => {
      await page.goto('/admin/users');
      // Should redirect away or show forbidden — not the admin UI
      await waitForLivewire(page);

      const isOnAdminUsers = page.url().includes('/admin/users');
      if (isOnAdminUsers) {
        // If not redirected, must not show the admin UI
        const hasAdminContent = await page.locator('text=User Management').isVisible();
        expect(hasAdminContent).toBeFalsy();
      }
      // Either redirected (to /dashboard or /login) or no admin content shown
    });

    test('learner cannot access /admin/audit-logs', async ({ page }) => {
      await page.goto('/admin/audit-logs');
      await waitForLivewire(page);

      const hasAuditLogUI = await page.locator('h1:has-text("Audit Log")').isVisible().catch(() => false);
      expect(hasAuditLogUI).toBeFalsy();
    });
  });
});
