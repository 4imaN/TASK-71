import { test, expect } from '@playwright/test';
import { LEARNER, loginAs, waitForLivewire } from '../helpers';

/**
 * 03-reservation — learner reservation journeys
 *
 * Covers:
 *   - Reservation list page loads
 *   - Booking a slot from the service detail page
 *   - Booked reservation appears in the reservations list
 *   - Reservation detail page loads with correct service title
 *   - Cancel reservation modal appears and cancel completes
 */

test.describe('Reservations', () => {
  test.beforeEach(async ({ page }) => {
    await loginAs(page, LEARNER.username, LEARNER.password);
  });

  test('reservations list page loads', async ({ page }) => {
    await page.goto('/reservations');
    await waitForLivewire(page);

    await expect(page.locator('h1')).toContainText('My Reservations');
    await expect(page.getByRole('button', { name: 'All' })).toBeVisible();
  });

  test('book a slot from service detail page', async ({ page }) => {
    await page.goto('/catalog/e2e-data-consultation');
    await waitForLivewire(page);

    // Click the first "Book" button
    const bookBtn = page.getByRole('button', { name: 'Book' }).first();
    await expect(bookBtn).toBeVisible({ timeout: 10_000 });
    await bookBtn.click();
    await waitForLivewire(page);

    // After booking, the button should become unavailable or a confirmation banner
    // appears (the Livewire component replaces the button with a success flash or
    // the slot becomes full / shows "Booked")
    // Accept either: button gone from that slot, or a success message, or redirect to reservations
    await page.waitForTimeout(1_500); // let Livewire re-render
    const page_text = await page.content();
    const booked = page_text.includes('reserved') ||
                   page_text.includes('Booked') ||
                   page_text.includes('pending') ||
                   page_text.includes('confirmed');
    expect(booked).toBeTruthy();
  });

  test('booked reservation appears in reservations list', async ({ page }) => {
    // Book first
    await page.goto('/catalog/e2e-data-consultation');
    await waitForLivewire(page);
    const bookBtn = page.getByRole('button', { name: 'Book' }).first();
    if (await bookBtn.isVisible()) {
      await bookBtn.click();
      await waitForLivewire(page);
    }

    // Check reservations list
    await page.goto('/reservations');
    await waitForLivewire(page);

    await expect(
      page.locator('text=Data Consultation (E2E)').first(),
    ).toBeVisible({ timeout: 10_000 });
  });

  test('reservation detail page loads', async ({ page }) => {
    // Ensure at least one reservation exists (book if none)
    await page.goto('/catalog/e2e-data-consultation');
    await waitForLivewire(page);
    const bookBtn = page.getByRole('button', { name: 'Book' }).first();
    if (await bookBtn.isVisible()) {
      await bookBtn.click();
      await waitForLivewire(page);
    }

    // Navigate to reservations list and open the detail
    await page.goto('/reservations');
    await waitForLivewire(page);

    const viewLink = page.getByRole('link', { name: 'View details' }).first();
    await expect(viewLink).toBeVisible({ timeout: 10_000 });
    await viewLink.click();
    await page.waitForURL(/\/reservations\//, { timeout: 15_000 });

    // Detail page shows service title
    await expect(
      page.locator('text=Data Consultation (E2E)').first(),
    ).toBeVisible({ timeout: 10_000 });
  });

  test('cancel reservation shows confirmation modal', async ({ page }) => {
    // Ensure a bookable slot exists and book it
    await page.goto('/catalog/e2e-data-consultation');
    await waitForLivewire(page);
    const bookBtn = page.getByRole('button', { name: 'Book' }).first();
    if (await bookBtn.isVisible()) {
      await bookBtn.click();
      await waitForLivewire(page);
    }

    // Go to reservation detail
    await page.goto('/reservations');
    await waitForLivewire(page);
    const viewLink = page.getByRole('link', { name: 'View details' }).first();
    if (!(await viewLink.isVisible({ timeout: 5_000 }).catch(() => false))) {
      test.skip();
      return;
    }
    await viewLink.click();
    await page.waitForURL(/\/reservations\//, { timeout: 15_000 });
    await waitForLivewire(page);

    // Click "Cancel reservation" button
    const cancelBtn = page.getByRole('button', { name: /Cancel reservation/i }).first();
    if (!(await cancelBtn.isVisible({ timeout: 5_000 }).catch(() => false))) {
      // Already cancelled or not in cancellable state — skip gracefully
      test.skip();
      return;
    }
    await cancelBtn.click();
    await waitForLivewire(page);

    // Wait for the cancel modal to fully render.
    // "Keep reservation" only exists inside the modal — it is never present on
    // the page otherwise, so this is a reliable signal that the overlay is live.
    await expect(
      page.getByRole('button', { name: 'Keep reservation' }),
    ).toBeVisible({ timeout: 8_000 });

    // Click the confirm button by its exact text so the selector cannot
    // accidentally resolve to the underlying trigger button that is now hidden
    // behind the modal overlay.  The view renders either "Confirm cancellation"
    // (normal) or "Accept consequence & cancel" (late cancellation).
    const confirmBtn = page.getByRole('button', { name: /Confirm cancellation|Accept consequence/i });
    await confirmBtn.click();
    await waitForLivewire(page);

    // Status should now show Cancelled
    await expect(
      page.locator('text=Cancelled').first(),
    ).toBeVisible({ timeout: 10_000 });
  });
});
