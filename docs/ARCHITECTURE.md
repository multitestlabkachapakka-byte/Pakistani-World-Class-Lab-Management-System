# Architecture

## Decision Summary

Owner locked the stack on 2026-11-20:

- **Web/PWA**: Vue 3 + TypeScript + Vite + Pinia
- **API**: Laravel (PHP 8.2+)
- **Server DB**: MySQL/MariaDB (XAMPP / LAN)
- **Device offline DB**: SQLite
- **Mobile**: Capacitor
- **Desktop Windows**: Tauri
- **Sync transport**: Google Drive (appDataFolder + Changes API)
- **PDF**: Single engine used for preview, download, print and share

## Why this hybrid

- XAMPP + Laravel gives a familiar LAN server for labs that already run Windows PCs with shared printers and MySQL.
- Capacitor + Tauri + local SQLite give true offline capability on phones and standalone Windows machines.
- Google Drive is used only as a sync + backup transport, never as the live database.
- Record-level JSON files + hybrid logical clock + soft deletes prevent silent data loss.

## Data model (core entities)

All tables carry:

- `id` UUID v4
- `created_at`, `updated_at` (UTC)
- `updated_by_device`
- `deleted_at` (soft delete)
- `rev` (revision counter)

Main entities:

- patients
- doctors (referrers)
- test_definitions → parameters → reference_ranges
- test_profiles (panels)
- reports + report_tests + report_results
- report_revisions (amendments)
- signatories
- lab_settings
- devices
- branches
- audit_logs

Finalized reports store a **snapshot** of names, units, ranges and flags so later catalog changes never alter historical reports.

## Numbering

Human-readable report / MR numbers include a device code to avoid collisions offline, e.g.:

- `261120-P-0001` (P = PC / Windows)
- `261120-M-0001` (M = Mobile)

Format is configurable in Settings.

## Sync strategy

1. Every write is committed to the local SQLite (or MySQL on the server) first.
2. Changed records are marked pending.
3. When online, the device pushes its pending JSON records and pulls via Drive Changes API.
4. Merge: last-writer-wins by `updated_at` + device id tie-break.
5. Conflicting finalized reports are preserved as `report_revisions`.
6. Page token is advanced only after a successful local transaction.

## Security

- App lock (PIN + biometric on Android)
- Tokens in secure storage
- No third-party analytics
- Sensitive results (HIV etc.) can be masked by role
- PECA-2016 conscious design
- Factory reset never deletes cloud data without explicit confirmation

## Stages (dependency order)

1. Repo + Laravel + Vue skeletons + migrations
2. Patient + Test catalog + Create Report vertical slice
3. PDF engine + print/share
4. Remaining catalog departments + profiles
5. Google Drive sync + conflict tests
6. Backup/Restore, Dashboard, bulk ops, packaging, CI releases
