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
| Google Drive sync status | yes | yes | no | no |
| Run sync | yes | no | no | no |
| Manage staff accounts | yes | no | no | no |

The last active administrator cannot be demoted or deactivated.
Reference-range verification by the pathologist is not built yet; until it is, nothing in the
catalog can become `VERIFIED`.

## Audit trail
Recorded in `audit_logs` with user, device, IP and time: first-time setup, logins (including failed
ones), logout, password changes, staff changes, patient created/updated/deleted, report
created / results saved / finalized, sync runs. Only ids, numbers and field names are stored -
**never passwords, tokens or result values**. There is no API to edit or delete audit rows.

## Known gaps (not done yet)
- No HTTPS: for LAN or internet use put the app behind HTTPS (tokens travel in a header).
- No offline sign-in for the Android / Windows apps yet (they do not exist yet).
- The screens do not hide role-restricted buttons yet (the server refuses with 403).
- Audit rows are not tamper-proof against someone with direct database access.
- Backups are not encrypted (backup module not built).
- The GitHub repository is public: never commit patient data, keys or tokens.
