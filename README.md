# Hotel Group Management Platform

Laravel API backend for a multi-hotel group management platform. Built as a
modular monolith (`app/Domain/{Context}`) with Repository + Service layers,
policy-based authorization, and a hotel-scoped RBAC foundation.

See `md/hotel_platform_phase0_approved_baseline.md` for the approved
architecture and requirements baseline. **Phase 1 (Foundation / RBAC)** is
implemented; later phases (rooms, reservations, payments, verification,
access, services, checkout, loyalty, reviews) are not yet built.

## Phase 1 scope

- Laravel API foundation under `/api/v1`, standard JSON response envelope,
  Sanctum token authentication.
- Identity & Access domain: Users, Roles, Permissions, `user_hotel_access`.
- Hotel Group / Hotels domain.
- RBAC with four seeded roles — Group Owner, Hotel Manager, Reception, Guest
  — enforced via Policies/Gates, never the UI.
- Hotel scope isolation: a user's accessible hotels are always resolved from
  their own stored `user_hotel_access` records (or an explicit Group Owner
  all-hotels bypass), never from a client-supplied `hotel_id`.
- Audit log foundation (`audit_logs`) for identity/hotel-group/hotel writes.
- English/Arabic localization foundation (`lang/en`, `lang/ar`, `X-Locale`
  header or `?lang=` query param).

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Create the MySQL databases referenced in `.env` (defaults:
`hotel_platform` for the app, `hotel_platform_testing` for `phpunit.xml`),
then:

```bash
php artisan migrate --seed
```

Seeding always runs `RolePermissionSeeder` (the four RBAC roles and their
permissions). Outside of `production`, it also runs `Phase1DemoSeeder` —
see below.

## Phase 1 demo data

`Phase1DemoSeeder` (called from `DatabaseSeeder` whenever `APP_ENV` is not
`production`) creates a realistic, deterministic dataset so you can
exercise every Phase 1 endpoint manually — e.g. with the Postman
collection under `postman/` — without hand-crafting records first.

Run it on its own at any time with:

```bash
php artisan db:seed --class=Phase1DemoSeeder
```

It is **idempotent**: every record is matched by a stable key (slug for
the Hotel Group/Hotels, email for Users) via `firstOrCreate`/
`updateOrCreate`, and hotel access is granted with `syncWithoutDetaching`.
Re-running it any number of times never creates duplicates and never
deletes or detaches existing data — safe to run again after manual
testing has changed things.

**⚠️ Development only.** All accounts below use the reserved `.test`
email TLD and a single shared password — never use these credentials
outside local/dev/staging, and the seeder itself refuses to run at all
when `APP_ENV=production`.

Demo password for every account: **`g`**

| Email | Role | Hotel access |
|---|---|---|
| `owner@hotel.test` | Group Owner | All hotels (explicit role-based bypass, no pivot rows needed) |
| `manager@hotel.test` | Hotel Manager | Cairo Hotel, Hurghada Hotel |
| `reception.cairo@hotel.test` | Reception | Cairo Hotel only |
| `reception.hurghada@hotel.test` | Reception | Hurghada Hotel only |
| `guest@hotel.test` | Guest | None — Guest carries no staff permissions in Phase 1 |

Demo Hotel Group ("Demo Hotel Group", slug `demo-hotel-group`) owns three
hotels: Cairo Hotel, Hurghada Hotel, and Luxor Hotel (`demo-*-hotel`
slugs — Luxor has no staff assigned, useful for testing cross-hotel
denial). Use this to confirm scope isolation manually: the Manager and
Cairo Reception should see Cairo; Hurghada Reception should see Hurghada;
neither should ever see Luxor or each other's unassigned hotel; the
Guest should get `403` from every staff endpoint.

## Testing

```bash
php artisan test
```

Runs against the `hotel_platform_testing` MySQL database configured in
`phpunit.xml` (matches the production driver rather than SQLite, so
MySQL-specific constraint/migration behavior is exercised too).

## Code style

```bash
./vendor/bin/pint
```
# backend_hotel_system
