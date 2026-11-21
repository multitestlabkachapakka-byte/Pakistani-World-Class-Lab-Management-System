# Pakistani World-Class Lab Management System (LIMS)

**Production-grade Diagnostic Laboratory Information Management System**  
Inspired by the operational needs of major Pakistani networks (Chughtai Lab, SKMCH, AKUH) — without copying any proprietary software, UI or unverified ranges.

Repository: https://github.com/multitestlabkachapakka-byte/Pakistani-World-Class-Lab-Management-System

---

## Architecture (FINAL — locked by owner)

| Layer | Technology |
|-------|------------|
| Frontend (Web / PWA) | Vue 3 + TypeScript + Vite + Pinia + responsive medical UI |
| Backend / API | Laravel 12 (PHP 8.2+) REST APIs, Sanctum auth, queues |
| Primary DB (XAMPP / LAN server) | MySQL / MariaDB |
| Offline DB (Android / iOS / Windows) | SQLite (local-first) |
| Mobile | Capacitor (Android + iOS) |
| Windows Desktop | Tauri (EXE installer + portable ZIP) |
| Sync | Google Drive API (record-level, local-first, conflict-safe) |
| PDF | Single consistent rendering engine (preview = print = share) |
| CI/CD | GitHub Actions → Android APK, Windows EXE/ZIP, web build |

**Key principles**
- Local-first / offline-first on every device
- Never invent reference ranges — every range carries source + verification_status
- Multi-branch ready from day one
- Soft-delete + full audit trail
- One source of truth for reports (snapshot on finalization)

---

## Current Status

| Module | Status |
|--------|--------|
| Repository foundation | Done |
| Project structure | Done |
| Database schema (UUID + soft-delete + snapshots) | Done |
| Core models (Patient, Report, Catalog, etc.) | Done |
| Catalog seed Phase A (Hematology + Chemistry + Endocrine/Vitamins) | Done |
| Patient + Report + Catalog API endpoints | Done |
| Vue Patients screen (search + register) | Done |
| Vue Create Report screen (patient → tests → results → finalize) | Done |
| Catalog Phase B (Serology, Coagulation, Urine, Tumor markers) | Done |
| PDF engine (HTML report ready for print / DomPDF) | Done (Phase 1) |
| Dashboard (live counts + recent reports) | Done |
| Google Drive sync (skeleton + status API + Roman Urdu setup guide) | Done Phase 1 |
| Capacitor / Tauri packaging | Later stages |

---

## Quick Start (Roman Urdu)

### 1. Git clone
```bash
git clone https://github.com/multitestlabkachapakka-byte/Pakistani-World-Class-Lab-Management-System.git
cd Pakistani-World-Class-Lab-Management-System
```

### 2. Backend (Laravel)
```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
# MySQL details .env mein set karein
php artisan migrate
php artisan serve
```

### 3. Frontend (Vue)
```bash
cd frontend
npm install
npm run dev
```

### 4. XAMPP / local server
- Apache + MySQL start karein
- Database create karein
- `.env` update karein

Detailed guides will be in `docs/INSTALLATION_ROMAN_URDU.md`

---

## Security Note

**Never commit secrets, keystores, OAuth client secrets or patient data.**  
Any personal access token shared in chat must be **revoked immediately** after use. Create a new fine-grained token with only `repo` scope if further pushes are needed.

---

## License & Disclaimer

Educational / internal laboratory use.  
Reference ranges must be verified by the laboratory pathologist against the actual analyzer, reagents and local population before clinical use.  
Not a substitute for professional medical advice or official laboratory reports.

---

**Maintainer**: multitestlabkachapakka-byte  
**Started**: November 2026
