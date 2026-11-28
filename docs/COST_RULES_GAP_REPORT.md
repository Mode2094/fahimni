# COST-RULES GAP REPORT — المنصة / Fahimni

**Type:** read-only gap report. **No** selling price, plan limit or provider cost is
changed by this document or by the release it accompanies.
**Related:** `docs/FINAL_RELEASE_CHECKLIST.md` (open item O3), `app/Media/MediaQuotaService.php`,
`database/seeders/CostRulesSeeder.php`, `app/Models/ProviderCostRule.php`.

---

## 1. Why this is a report and not a fix

`CostRulesSeeder` is **not** safe to run against production as a hot-fix:

- it **updates** any existing row for the same `(provider, resource)` (`fill(...)->save()`),
  so it would **overwrite** rates an admin tuned from the BI panel with the
  seeder's defaults;
- its Bunny defaults are the **EU/US** published rates (storage `$0.01/GB`,
  delivery `$0.01/GB`, `delivery_factor=2`), which do **not** reflect MENA
  delivery economics;
- `docker/migrate.sh` only seeds when the `users` table is **empty**
  (`php artisan tinker --execute='echo \App\Models\User::count();'`), so on the
  live database the seeder is **never** reached anyway — running it would require
  a deliberate `db:seed --class=CostRulesSeeder`.

⇒ Decision: **do not run `CostRulesSeeder` (or `DatabaseSeeder`) in production.**
The single source of truth stays the `provider_cost_rules` table.

---

## 2. Read-only inspection of production (owner-run)

The agent has no Docker/root on production (`uid 1001`), so the live values must be
read by the owner. This is **read-only** and touches nothing:

```bash
sudo bash -c 'cd /opt/fahimni/production/current && docker compose \
  --env-file /etc/fahimni/production.env exec -T postgres \
  sh -c '"'"'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c \
  "select provider, resource, unit, cost, currency, is_active \
   from provider_cost_rules order by provider, resource;"'"'"''
```

A count-only probe (fewer moving parts):

```bash
sudo bash -c 'cd /opt/fahimni/production/current && docker compose \
  --env-file /etc/fahimni/production.env exec -T postgres \
  sh -c '"'"'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc \
  "select count(*) from provider_cost_rules;"'"'"''
```

**Expected (from the seeder's intent):** 10 rows — `bunny`×4, `cloudflare`×4,
`email`×1, `sms`×1. **Production status: PENDING OWNER OUTPUT** (cannot be read by
the agent). Record the actual rows here before making any pricing decision.

---

## 3. Gap inventory

| # | Gap | Where | Impact | Suggested action (separate proposal — not this release) |
| --- | --- | --- | --- | --- |
| C1 | **MENA vs EU/US Bunny rates.** Seeder defaults use the EU/US published prices and `delivery_factor=2`. | `CostRulesSeeder` | Cost/profitability numbers for MENA traffic are approximate, may under/over-state delivery cost. | Confirm MENA pricing with Bunny; if different, update **only** `provider_cost_rules` rows via the admin BI panel (no deploy). |
| C2 | **Live pricing is an estimate.** Bunny has no separate per-hour live price; the seeder approximates `$0.03/hour`. | `CostRulesSeeder` (`bunny/live`) | Live-session cost is an estimate, not a billed figure. | Replace with the real invoiced rate from Bunny once observed. |
| C3 | **`delivery_factor` semantics.** Metadata carries `delivery_factor=2` for storage/delivery. | `CostRulesSeeder` | Doubles the modelled delivery cost; correct only if 2 deliveries per stored GB is typical. | Re-derive from real usage if it diverges. |
| C4 | **`video_watch_minutes_limit` missing from `FeatureCatalog`.** `MediaQuotaService::assertWatchMinutes` falls back to `delivery_minutes_limit`. | `app/Support/FeatureCatalog.php`, `app/Media/MediaQuotaService.php` | A plan cannot express watch-minutes separately from delivery minutes; the quota is enforced against the wrong knob. | Add the feature key + a plan value in a pricing proposal; **no schema change required**. |
| C5 | **`expected_emails_month=100`** baked into storage metadata. | `CostRulesSeeder` | Email cost modelling assumes 100 emails/month per GB-storage line. | Re-tune per plan if email volume differs. |
| C6 | **Unverified production values.** The live table may already differ from the seeder (admin edits). | production DB | Any decision made without §2 output could be based on stale assumptions. | Run §2; paste the rows into this section. |

---

## 4. What this release does about it

Nothing that changes money. This release only:

- **documents** the gaps above (this file),
- keeps `CostRulesSeeder` **registered** in `DatabaseSeeder` so a genuinely fresh
  database is seeded, made **event-independent** (survives `WithoutModelEvents`)
  and covered by tests — an internal correctness fix only,
- **never** runs the seeder, `plans:sync --force`, or any price write on production.

No selling price, plan entitlement, provider cost row or subscription is modified.
