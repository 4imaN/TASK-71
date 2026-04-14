import { Page, expect } from '@playwright/test';

/** Known E2E credentials — must match database/seeders/E2eSeeder.php */
export const ADMIN = {
  username: 'e2e_admin',
  password: 'AdminE2e1!',
  displayName: 'E2E Admin',
};

export const LEARNER = {
  username: 'e2e_learner',
  password: 'LearnerE2e1!',
  displayName: 'E2E Learner',
};

/**
 * Log in via the Login form and wait for the dashboard to load.
 *
 * Selectors rely on id="username" and id="password" in the Livewire login view.
 * The form submits via wire:submit="authenticate" which redirects to /dashboard
 * on success.
 */
export async function loginAs(
  page: Page,
  username: string,
  password: string,
): Promise<void> {
  await page.goto('/login');
  await expect(page.locator('h1')).toContainText('Research Services');

  await page.fill('#username', username);
  await page.fill('#password', password);
  await page.click('button[type="submit"]');

  // Wait for redirect to dashboard — confirms session is established
  await page.waitForURL('**/dashboard', { timeout: 20_000 });
}

/**
 * Log out via the nav sign-out form (POST /logout).
 * Waits for redirect back to /login.
 */
export async function logout(page: Page): Promise<void> {
  // The sign-out button is inside a <form method="POST" action="/logout">
  await page.click('button:has-text("Sign out")');
  await page.waitForURL('**/login', { timeout: 10_000 });
}

/**
 * Wait for Livewire to finish processing (network idle + no wire:loading
 * spinners visible).  Use after triggering wire:click actions that may
 * trigger server round-trips.
 */
export async function waitForLivewire(page: Page): Promise<void> {
  await page.waitForLoadState('networkidle', { timeout: 15_000 });
}
