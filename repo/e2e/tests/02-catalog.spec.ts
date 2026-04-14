import { test, expect } from '@playwright/test';
import { LEARNER, loginAs, waitForLivewire } from '../helpers';

/**
 * 02-catalog — service catalog browsing journeys
 *
 * Covers:
 *   - Catalog page loads and shows services
 *   - Search input filters results
 *   - Category sidebar filter works
 *   - Service detail page loads from catalog
 *   - Service detail shows title, description, and upcoming slots
 */

test.describe('Service Catalog', () => {
  test.beforeEach(async ({ page }) => {
    await loginAs(page, LEARNER.username, LEARNER.password);
  });

  test('catalog page loads with services list', async ({ page }) => {
    await page.goto('/catalog');
    await waitForLivewire(page);

    // The E2eSeeder creates "Data Consultation (E2E)"
    await expect(page.locator('text=Data Consultation (E2E)')).toBeVisible({ timeout: 10_000 });
  });

  test('catalog page shows category sidebar', async ({ page }) => {
    await page.goto('/catalog');
    await waitForLivewire(page);

    // Sidebar shows "All categories" button
    await expect(page.getByRole('button', { name: 'All categories' })).toBeVisible();
    // E2eSeeder category
    await expect(page.getByRole('button', { name: 'Research Support' })).toBeVisible();
  });

  test('search filters service results', async ({ page }) => {
    await page.goto('/catalog');
    await waitForLivewire(page);

    // Fill search — the browse component has a text input with wire:model.live.debounce
    const searchInput = page.locator('input[placeholder*="Search"]').first();
    await searchInput.fill('Data Consultation');
    await waitForLivewire(page);

    await expect(page.locator('text=Data Consultation (E2E)')).toBeVisible({ timeout: 8_000 });
  });

  test('search with no match shows empty state', async ({ page }) => {
    await page.goto('/catalog');
    await waitForLivewire(page);

    const searchInput = page.locator('input[placeholder*="Search"]').first();
    await searchInput.fill('xyzzy_no_match_e2e_test');
    await waitForLivewire(page);

    // The browse view shows "No services match your criteria" when empty
    await expect(page.locator('text=No services match your criteria')).toBeVisible({ timeout: 8_000 });
  });

  test('category filter shows matching services', async ({ page }) => {
    await page.goto('/catalog');
    await waitForLivewire(page);

    // Click the E2E category
    await page.getByRole('button', { name: 'Research Support' }).click();
    await waitForLivewire(page);

    await expect(page.locator('text=Data Consultation (E2E)')).toBeVisible({ timeout: 8_000 });
  });

  test('service detail page loads from catalog link', async ({ page }) => {
    await page.goto('/catalog');
    await waitForLivewire(page);

    // Click the service title link
    await page.locator('text=Data Consultation (E2E)').first().click();
    await page.waitForURL(/\/catalog\/e2e-data-consultation/, { timeout: 15_000 });

    await expect(page.locator('h1')).toContainText('Data Consultation (E2E)');
  });

  test('service detail shows upcoming time slots', async ({ page }) => {
    await page.goto('/catalog/e2e-data-consultation');
    await waitForLivewire(page);

    await expect(page.locator('text=Upcoming slots')).toBeVisible({ timeout: 10_000 });
    // E2eSeeder creates 2 slots
    await expect(page.getByRole('button', { name: 'Book' }).first()).toBeVisible({ timeout: 8_000 });
  });
});
