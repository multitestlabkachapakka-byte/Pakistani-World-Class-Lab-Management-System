# Progress & verification log

Rule for this file: a module is only called **Verified** when an automated test run on GitHub
Actions proves it. Code that exists but has not been exercised is called **Code only**.

## Checkpoint 4 - browser tests, backup / restore / factory reset (2026-11-22)

CI is green on PHP 8.2 + SQLite, PHP 8.3 + SQLite, PHP 8.2 + MariaDB 10.11: **89 PHPUnit tests** each;
frontend typecheck + **86 vitest tests** + build; importer drift check; **8 browser tests**.

- **End-to-end browser tests** (Playwright, Chromium, real backend; CI job `e2e`): first-time setup,
  sign in / out, staff accounts, role-restricted navigation, a pathologist verifying a test's ranges
  (a technician sees no verify buttons), the whole report flow (register patient, enter results incl.
  calculated values with manual override, DRAFT preview, finalize incomplete with an explicit decision,
  printed report, and the printed report staying frozen after the patient is renamed), receptionist
  limits, and disaster recovery. This is the first time the screens are verified in a real browser.
- **Backup / restore / factory reset** (API + Backup screen, admin only): see SECURITY.md. One
  encrypted file, restore in one transaction with a safety copy first, refuses wrong passphrase /
  damaged / newer files, signs everybody out. API round trip tested on SQLite and MariaDB (foreign-key
  order is worked out from the schema), incl. a finalized report; the browser recovery path
  (backup -> reset -> setup -> restore) is the 8th browser test.
- Found by the tests and fixed: the three routes shared one rate-limit counter per user.

## Checkpoint 3 - range verification, one report engine, settings (2026-11-22)

CI is green on PHP 8.2 + SQLite, PHP 8.3 + SQLite, PHP 8.2 + MariaDB 10.11: **82 PHPUnit tests** each;
frontend typecheck + **81 vitest tests** + build; importer drift check.

- **Reference range management + pathologist verification** (API + Catalog screen): admin and
  pathologist add / edit / remove ranges; only a **pathologist** verifies (one range, or a whole test
  after a confirmation); the review queue shows how many ranges are still unverified. A range needs a
  source; numbers must be consistent (low <= high, critical outside normal); the printed text must
  mention every non-zero limit; any edit voids the verification; exact duplicates are refused.
  Re-seeding never overwrites ranges staff have edited, verified or removed.
- **Data quality:** two invented limits in the seed data were removed (HDL high = 999, vitamin D
  high = 100) and a test now fails if a non-zero limit is missing from its range text (one documented
  exception: HbA1c 5.6 for "< 5.7%" because results have one decimal).
- **One report engine** (`frontend/src/domain/reportHtml.ts`, pure function, works offline): a final
  report is rendered from its **frozen snapshot**; drafts carry a DRAFT banner + watermark; unverified
  reference ranges get a dagger and footnote; critical results get a box; everything is HTML-escaped;
  the logo is accepted only as an embedded image. Print / "Save as PDF" through the browser.
  The old server-side HTML report was removed: it ignored all settings (the `LabSetting` model did not
  exist) and printed live data instead of the snapshot.
- **Settings** (API + screen): lab name, address, phone, e-mail, header / footer notes, watermark,
  logo, up to 4 signatories, switch for the unverified-range notice. Everyone reads, only an admin changes.
- **Create Report screen:** result entry and finalize only for admin / pathologist / technician;
  "no reference range for this patient" and "pending review" markers; critical banner; missing
  parameters listed with an explicit "finalize anyway (incomplete)" step; preview / print button.
- **Staff screen** (admin, "Staff" menu): create accounts, choose the role, activate / deactivate, reset a
  password; the screen is reachable only for admins (route guard; the server enforces it too). This is
  how the lab's pathologist account is created, which is needed before anything can be verified.
- **Calculated results:** indirect bilirubin, globulin, A/G ratio, LDL and VLDL (Friedewald) now have
  formulas in the catalog (`{T.Bil} - {D.Bil}`, `IF({TG} < 400, ...)` ...), evaluated by a small parser
  without `eval`. The entry screen fills them in from the values the technician entered, tags them
  "calculated", and a hand-typed value always wins ("manual", with a recalculate link). A value is
  produced only when every input was entered; Friedewald refuses TG >= 400 mg/dL. Reports freeze the
  short name and formula, and record calculated values as auto-filled. The formulas are standard
  textbook ones but are **pending pathologist review** (formulas have no verification workflow yet).

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
| 6 | Reference range management + pathologist verification | Verified (API tests); Catalog screen builds, not yet tested in a browser |
| 7 | Short forms / aliases | Verified |
| 8 | Result entry + automatic flags | Verified (API) |
| 9 | Clinical calculations | Verified (unit tests + checks of the seed data): 5 calculated parameters; formulas pending pathologist review |
| 10 | Report generation (draft > results > validate > final + snapshot) | Verified (API); UI not yet updated for the new validation messages |
| 11 | PDF preview / print / download | Single offline-capable renderer verified by 15 unit tests; print / Save as PDF via the browser. No PDF *file* generation or sharing yet |
| 12 | Logo / header / footer / watermark | Verified (API) + Settings screen; signatories included |
| 13-14 | Multi-select, bulk download / print / share | Not started |
| 15 | Patient history | API filter by patient exists; no UI / trends |
| 16 | WhatsApp / email | Not started |
| 17 | Doctor management | Table + model only |
| 18 | Sample tracking / workflow | Not started (only draft/final) |
| 19 | Critical results | Flagging verified; critical banner on the entry screen and box on printed reports; acknowledgement workflow not started |
| 20 | Google Drive sync | Skeleton only; needs OAuth credentials; not verified |
| 21 | Fully offline operation | Domain logic ported + tested; local SQLite layer not started |
| 22-23 | Backup / restore / factory reset | Verified (API tests on SQLite + MariaDB, browser recovery test). Manual only: no schedule, no automatic upload to Drive yet |
| 24 | Security + audit trail | Verified (API): Sanctum login, first-run setup, 4 roles, rate limit, audit trail. Gaps: no HTTPS, no offline sign-in yet, not every screen hides role-restricted actions, no password reset by e-mail - see SECURITY.md |
| 25-27 | Android / Windows EXE / iOS | Not started (Capacitor / Tauri) |
| 28 | XAMPP deployment | Backend migrates, seeds and passes tests on MariaDB 10.11 in CI; installer + guide pending |
| 29 | PWA / web | Frontend builds in CI; manifest / service worker not started |
| 30 | Automated tests + GitHub Releases | CI verified (SQLite, MariaDB, frontend, importer drift check, browser tests); release automation not started |

## Next steps (in dependency order)
1. Patients screen: edit / history (#2, #15) and doctor management (#17).
2. Multi-select + bulk print (#13-14); real PDF *file* generation + sharing by WhatsApp / e-mail (#16).
3. Critical result acknowledgement (#19), sample tracking / workflow (#18).
4. Catalog growth through pathologist review: structure the 26 text-only tests, add pediatric ranges
   and critical limits.
5. Scheduled backups + automatic encrypted upload to Google Drive; verify the sync (#20).
6. Local SQLite layer + offline sign-in + sync design; then Capacitor / Tauri build workflows.
7. Release automation (APK, EXE, portable ZIP, iOS workflow, PWA).
