# Progress & verification log

Rule for this file: a module is only called **Verified** when an automated test run on GitHub
Actions proves it. Code that exists but has not been exercised is called **Code only**.

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
| 4 | Test catalog (target 500-1000+) | 34 tests / 82 parameters / 91 ranges, all VERIFY REQUIRED. Uploaded workbook (79 tests) **not imported yet** |
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
| 24 | Security + audit trail | **Not implemented: the API is open (no login).** `audit_logs` table exists, nothing writes to it. Do not expose to a network with real patient data yet |
| 25-27 | Android / Windows EXE / iOS | Not started (Capacitor / Tauri) |
| 28 | XAMPP deployment | Backend migrates, seeds and passes tests on MariaDB 10.11 in CI; installer + guide pending |
| 29 | PWA / web | Frontend builds in CI; manifest / service worker not started |
| 30 | Automated tests + GitHub Releases | CI verified; release automation not started |

## Next steps (in dependency order)
1. Authentication (Sanctum, roles, first-run admin setup) + audit writes.
2. Import the uploaded workbook into the catalog as `VERIFY REQUIRED` (only unambiguous ranges
   structured; the rest kept as text for pathologist review).
3. UI: show "no reference range" / "pending pathologist review" states; handle finalize validation.
4. Reference range management + pathologist verification workflow.
5. PDF engine integration and verification (unverified-range notice in the footer).
6. Local SQLite layer + sync design; then Capacitor / Tauri build workflows.
