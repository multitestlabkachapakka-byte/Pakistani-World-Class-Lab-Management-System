# Progress & verification log

Rule for this file: a module is only called **Verified** when an automated test run on GitHub
Actions proves it. Code that exists but has not been exercised is called **Code only**.

## Checkpoint 2 - sign-in, roles, audit trail, workbook import (2026-11-22)

CI is green on PHP 8.2 + SQLite, PHP 8.3 + SQLite, PHP 8.2 + MariaDB 10.11: **65 PHPUnit tests /
1190 assertions** each; frontend typecheck + **37 vitest tests** + build; importer drift check.

- **Authentication** (see [SECURITY.md](SECURITY.md)): Sanctum login, first-run admin setup
  (localhost only, no default password), four roles, rate-limited login, instant deactivation,
  last-admin protection, per-device tokens. All API routes except health/setup/login now need a token.
- **Audit trail**: logins (incl. failed), patient/report create/update/finalize, staff changes,
  sync runs - ids and field names only, never passwords or result values.
- **Frontend**: login + first-time-setup screen, route guard, token sent with every request, 401
  signs the user out, report PDF opened with the token, header shows user + sign-out.
- **Catalog +47 tests from the uploaded workbook** (now 81 tests / 132 parameters / 145 ranges):
  21 with numeric limits (automatic flags), 26 text-only (workbook text shown, never flags). All
  `VERIFY REQUIRED`, Adult only, source = the workbook. Only unambiguous patterns become numbers;
  every stored limit must literally appear in the workbook text (tested). What was parsed how:
  [CATALOG_IMPORT_REPORT.md](CATALOG_IMPORT_REPORT.md). CI re-runs the importer so the JSON cannot
  drift from `data/sources/*.xlsx`.
- Users table changed in place (UUID ids, username, role): rebuild dev databases with
  `php artisan migrate:fresh --seed`.

## Checkpoint 1 - verified foundation (2026-11-21)

CI (GitHub Actions, workflow `CI`) is green on: PHP 8.2 + SQLite, PHP 8.3 + SQLite,
PHP 8.2 + MariaDB 10.11 (XAMPP target) - 48 PHPUnit tests / 652 assertions each;
frontend typecheck + 25 vitest tests + production build.

### What changed in this checkpoint (clinical safety first)
- **No false "VERIFIED".** All 123 seeded ranges/tests were labelled VERIFIED although nobody had
  verified them. They are now `VERIFY REQUIRED`. The seeder downgrades any `VERIFIED` entry that
  has no `verified_by` + `verified_at`. Only a pathologist action may set `VERIFIED`.
- **Reference range chosen by sex AND age.** Sex-specific beats "both", narrowest age band wins.
  No match => no range (never a range for another population). "Adult" ranges start at 18 years
  (assumption, to be confirmed by the pathologist). The catalog currently has adult ranges only,
  so children get *no* range until pediatric ranges are added and reviewed.
- **Results start empty.** No normal value is ever typed into a result field.
- **Flags:** L/H from the stored limits; critical LL/HH only when critical limits are stored
  (none are seeded yet - none were invented).
- **Finalize validates** (results exist, missing parameters listed unless `allow_incomplete`) and
  freezes patient details + the applied range (id, source, verification status) in the snapshot.
- **Unknown test codes are rejected** (422) instead of silently dropped from the report.
- **Catalog IDs are deterministic (UUIDv5)** - identical on every device, re-seeding is idempotent
  (needed for Drive sync). Dev databases seeded by the previous version must be rebuilt:
  `php artisan migrate:fresh --seed`.
- MR / report numbers: device code reduced to A-Z0-9; retry if two requests race for a number
  (retry path is implemented but not race-tested).
- Catalog search matches aliases case-insensitively (cbc, fbc, blood cp).
- Offline domain logic (flag + range resolver) ported to TypeScript with the same test cases.
- Laravel 12 skeleton, Composer platform pinned to PHP 8.2 (lock installs on 8.2+), storage
  placeholders committed.

## Module status (30 modules from the master prompt)

| # | Module | Status |
|---|--------|--------|
| 1 | Dashboard | Code only (API smoke-tested on empty DB; Vue screen builds) |
| 2 | Patient registration + search | API verified (validation, search by name/MR); UI code only |
| 3 | MR number generation | Verified (sequence, device code); collision retry not race-tested |
| 4 | Test catalog (target 500-1000+) | 81 tests / 132 parameters, all VERIFY REQUIRED (34 original + 47 from the workbook: 21 numeric, 26 text-only). Far from the target; growth goes through pathologist review |
| 5 | Age/sex-specific ranges | Resolver verified; adult ranges only |
| 6 | Reference range management + pathologist verification | Not started |
| 7 | Short forms / aliases | Verified |
| 8 | Result entry + automatic flags | Verified (API) |
| 9 | Clinical calculations | Not started (schema has formula column) |
| 10 | Report generation (draft > results > validate > final + snapshot) | Verified (API); UI not yet updated for the new validation messages |
| 11 | PDF preview / print / download | Code only (HTML report service); not verified, no PDF engine integrated yet |
| 12 | Logo / header / footer / watermark | Not started |
| 13-14 | Multi-select, bulk download / print / share | Not started |
| 15 | Patient history | API filter by patient exists; no UI / trends |
| 16 | WhatsApp / email | Not started |
| 17 | Doctor management | Table + model only |
| 18 | Sample tracking / workflow | Not started (only draft/final) |
| 19 | Critical results | Flagging verified; acknowledgement workflow not started |
| 20 | Google Drive sync | Skeleton only; needs OAuth credentials; not verified |
| 21 | Fully offline operation | Domain logic ported + tested; local SQLite layer not started |
| 22-23 | Backup / restore / factory reset | Not started |
| 24 | Security + audit trail | Verified (API): Sanctum login, first-run setup, 4 roles, rate limit, audit trail. Gaps: no HTTPS, no offline sign-in yet, UI does not hide role-restricted buttons, no password reset by e-mail - see SECURITY.md |
| 25-27 | Android / Windows EXE / iOS | Not started (Capacitor / Tauri) |
| 28 | XAMPP deployment | Backend migrates, seeds and passes tests on MariaDB 10.11 in CI; installer + guide pending |
| 29 | PWA / web | Frontend builds in CI; manifest / service worker not started |
| 30 | Automated tests + GitHub Releases | CI verified (SQLite, MariaDB, frontend, importer drift check); release automation not started |

## Next steps (in dependency order)
1. UI for the new rules: hide role-restricted actions; show "no reference range", "pending
   pathologist review", missing parameters on finalize and critical results.
2. Reference range management + pathologist verification workflow (the only way anything becomes
   VERIFIED), with audit rows.
3. Catalog growth through review: structure the 26 text-only tests, add pediatric ranges and
   critical limits.
4. PDF engine integration and verification (unverified-range notice in the footer).
5. Local SQLite layer + offline sign-in + sync design; then Capacitor / Tauri build workflows.
6. Release automation (APK, EXE, portable ZIP, iOS workflow, PWA).
