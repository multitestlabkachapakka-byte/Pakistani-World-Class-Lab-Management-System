# Security model

Verified by automated tests unless marked otherwise.

## Sign-in
- Staff sign in with a **username + password**; the server returns a per-device Sanctum token
  (valid 30 days, `LIMS_TOKEN_DAYS`). Every API route except `/api/health`, `/api/setup*` and
  `/api/auth/login` needs that token.
- Password rule: at least 8 characters with letters and numbers.
- Login is rate-limited (5 attempts per minute per username + IP).
- A deactivated account stops working immediately, even with a token that is still valid.
- Changing a password (or an admin resetting it) signs the account out on all other devices.
- There is **no default account or password** anywhere in the code, seeds or database.

## First-time setup
`POST /api/setup` (the "First-time setup" screen) creates the first administrator. It works only
while no user exists, and only from the server computer (localhost). To set up from another computer
on the LAN, set `LIMS_ALLOW_REMOTE_SETUP=true` in `backend/.env`, create the admin, then set it back
to `false`. Do the setup immediately after installing.

## Roles
| Action | Admin | Pathologist | Technician | Receptionist |
|---|:-:|:-:|:-:|:-:|
| Dashboard, catalog, patient and report lists | yes | yes | yes | yes |
| Register / edit patients, create reports (book tests) | yes | yes | yes | yes |
| Enter results, finalize reports | yes | yes | yes | no |
| Delete (soft-delete) patients | yes | no | no | no |
| Add / edit / remove reference ranges | yes | yes | no | no |
| **Verify** reference ranges (clinical sign-off) | no | yes | no | no |
| Read laboratory settings (letterhead) | yes | yes | yes | yes |
| Change laboratory settings | yes | no | no | no |
| Google Drive sync status | yes | yes | no | no |
| Run sync | yes | no | no | no |
| Manage staff accounts | yes | no | no | no |
| Backup, restore, factory reset (asks for the admin password again) | yes | no | no | no |

The last active administrator cannot be demoted or deactivated.
Only a pathologist can verify a reference range (an administrator cannot). Any edit to a range voids
its verification, and re-seeding the catalog never overwrites a range that staff have edited, verified
or removed.

## Backups
- A backup is one file: AES-256-GCM over gzip(JSON), key from a passphrase you choose (at least 10
  characters, PBKDF2-SHA256, 200 000 rounds). Without the passphrase it cannot be read, and any change
  to the file is detected. **A lost passphrase cannot be recovered.**
- Included: all data (patients, reports, staff accounts with their password hashes, settings, catalog
  and verification, audit trail). Not included: login tokens, sessions, caches.
- Restore replaces ALL data in one transaction (all or nothing), signs everybody out, and first saves an
  encrypted safety copy of the current data under `backend/storage/app/backups/` (file mode 0600).
  Restore and factory reset need the administrator's password again and an exact typed phrase.
- Backups from an older version restore (new columns take their defaults); backups from a newer
  version are refused.
- Backup, restore and reset are rate limited (separately) and appear in the audit trail.

## Audit trail
Recorded in `audit_logs` with user, device, IP and time: first-time setup, logins (including failed
ones), logout, password changes, staff changes, patient created/updated/deleted, report
created / results saved / finalized, reference range created / edited / removed / verified / withdrawn,
whole-test verification, settings changed (field names only), backups created / restored, factory reset,
sync runs. Only ids, numbers and field names are stored -
**never passwords, tokens or result values**. There is no API to edit or delete audit rows.

## Known gaps (not done yet)
- No HTTPS: for LAN or internet use put the app behind HTTPS (tokens travel in a header).
- No offline sign-in for the Android / Windows apps yet (they do not exist yet).
- Not every screen hides role-restricted actions yet (the server always refuses with 403). The catalog,
  report entry and settings screens already respect the role.
- Audit rows are not tamper-proof against someone with direct database access.
- Backups are made by hand (no schedule yet) and are not uploaded anywhere automatically.
- The GitHub repository is public: never commit patient data, keys or tokens.
