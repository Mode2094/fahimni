import { test, expect, devices } from '@playwright/test';

/**
 * Real-browser regression for a teacher's CUSTOM DOMAIN.
 *
 * A custom domain serves the teacher's public site from the host ROOT — there
 * is no `/t/{slug}` prefix and the platform chrome must never appear. These
 * guarantees are exactly the ones that broke (a blank `/courses`, a 404 on
 * `/register/student`, the platform login identity) and cannot be proven by
 * unit tests, so they run against the live domain(s).
 *
 *   E2E_CUSTOM_DOMAINS="maryammath.com,www.other-teacher.com" \
 *   npx playwright test custom-domain
 *
 * (A single `E2E_CUSTOM_DOMAIN` is still accepted.) Runs only when a domain is
 * configured, because it needs the deployed edge (DNS + TLS + the
 * Cloudflare-for-SaaS hostname) — CI has none of those. Every configured
 * domain is checked desktop AND mobile, so two isolated tenants are covered
 * in one run.
 */

const DOMAINS = (
  process.env.E2E_CUSTOM_DOMAINS ||
  process.env.E2E_CUSTOM_DOMAIN ||
  ''
)
  .split(',')
  .map((d) => d.trim())
  .filter(Boolean);

/** Optional per-domain display name, e.g. E2E_TENANT_NAME_maryammath.com=… */
function tenantNameFor(domain: string): string {
  return (
    process.env[`E2E_TENANT_NAME_${domain.replace(/\./g, '_')}`] ||
    process.env.E2E_TENANT_NAME ||
    ''
  );
}

for (const domain of DOMAINS) {
  const origin = `https://${domain}`;
  const name = tenantNameFor(domain);

  const PROFILES = {
    desktop: { viewport: { width: 1280, height: 800 } },
    mobile: {
      viewport: { width: 390, height: 844 },
      deviceScaleFactor: 3,
      isMobile: true,
      hasTouch: true,
      userAgent: devices['iPhone 13'].userAgent,
    },
  } as const;

  for (const device of ['desktop', 'mobile'] as const) {
    test.describe(`${domain} (${device})`, () => {
      test.use(PROFILES[device]);

      test('home serves the teacher landing (not the platform home)', async ({ page }) => {
        const res = await page.goto(`${origin}/`);
        expect(res?.status()).toBe(200);
        await expect(page.getByTestId('storefront-header')).toBeVisible();
        if (name) await expect(page.locator('body')).toContainText(name);
      });

      test('/courses renders the catalogue instead of a blank auth gate', async ({ page }) => {
        const res = await page.goto(`${origin}/courses`);
        expect(res?.status()).toBe(200);
        await expect(page.getByRole('heading', { name: /جميع الكورسات|All courses/ })).toBeVisible();
        // The real defect: the page mounted but rendered nothing (innerText 0).
        expect((await page.locator('body').innerText()).trim().length).toBeGreaterThan(20);
      });

      test('/register/student does not 404 — it serves the tenant sign-up', async ({ page }) => {
        const res = await page.goto(`${origin}/register/student`);
        expect(res?.status()).toBe(200);
        await expect(page.getByRole('heading', { name: /سجل كطالب|Register as Student/ })).toBeVisible();
      });

      test('the student login keeps the teacher identity', async ({ page }) => {
        const res = await page.goto(`${origin}/student/login`);
        expect(res?.status()).toBe(200);
        await expect(page.locator('#email')).toBeVisible();
        if (name) await expect(page.locator('body')).toContainText(name);
      });

      test('public links never leak the internal slug or /t/{slug}', async ({ page }) => {
        for (const path of ['/', '/courses', '/register/student', '/student/login']) {
          await page.goto(`${origin}${path}`);
          const hrefs = await page
            .locator('a[href]')
            .evaluateAll((els) => els.map((el) => el.getAttribute('href') || ''));
          for (const href of hrefs) {
            // The tenant is resolved from the Host here; `?tenant_slug=` is only
            // the platform's carrier and it (plus any /t/ prefix) must be absent.
            expect(href, `${path} leaked the internal slug: ${href}`).not.toMatch(/tenant_slug=/);
            expect(href, `${path} leaked /t/{slug}: ${href}`).not.toMatch(/^\/t\//);
          }
        }
      });

      test('public pages never overflow horizontally (mobile)', async ({ page }) => {
        for (const path of ['/', '/courses', '/blog', '/register/student']) {
          const res = await page.goto(`${origin}${path}`, { waitUntil: 'domcontentloaded' });
          expect(res?.status()).toBeLessThan(500);
          await page.waitForTimeout(700);
          const overflow = await page.evaluate(
            () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
          );
          expect(overflow, `${path} overflows by ${overflow}px`).toBeLessThanOrEqual(2);
        }
      });

      test('canonical + manifest stay on the teacher domain (no /t/{slug})', async ({ page }) => {
        // The teacher's pages publish a ROOT-RELATIVE canonical that Next
        // resolves against the root `metadataBase`. That base used to be the
        // platform, so every teacher page advertised `fahimni.me` — here it must
        // resolve to the teacher's own domain.
        for (const path of ['/', '/courses']) {
          await page.goto(`${origin}${path}`);
          const canonical = await page.locator('link[rel="canonical"]').getAttribute('href');
          expect(canonical, `missing canonical on ${path}`).toBeTruthy();
          expect(canonical!, `canonical leaked /t/{slug} on ${path}`).not.toContain('/t/');
          expect(
            canonical!.startsWith(`${origin}/`) || canonical === `${origin}`,
            `canonical ${canonical} is not on ${domain}`,
          ).toBe(true);
        }

        await page.goto(`${origin}/`);
        const manifest = await page.evaluate(async () => {
          const link = document.querySelector('link[rel="manifest"]');
          if (!link) return null;
          const res = await fetch((link as HTMLLinkElement).href, { credentials: 'same-origin' });
          return res.ok ? await res.json() : null;
        });
        if (manifest) {
          expect(String(manifest.start_url)).not.toContain('/t/');
          expect(String(manifest.scope)).not.toContain('/t/');
        }
      });
    });
  }
}

test.describe('custom domain (configuration)', () => {
  test.skip(DOMAINS.length > 0, 'domains configured — running the journeys above');
  test('informational: set E2E_CUSTOM_DOMAINS to run the live checks', () => {});
});
