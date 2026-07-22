# ATTS IQAC Portal — PHP + MySQL

A PHP (server-rendered HTML) + MySQL conversion of the React/Node/MongoDB app.
The original React project is untouched in `frontend/` and `backend/`; this is a
separate, self-contained rewrite.

> **Status: all pages built.** Login, session auth, role-based sidebars,
> dashboards (with charts, department breakdown and filters), Approvals,
> Reports + downloads, Users, Departments, Targets, Upload, Faculty, Profile,
> Settings and the Announcement Centre are all in place. No page falls back to
> the "coming soon" placeholder any more.

### Pages

| Page | Who sees it | What it does |
|---|---|---|
| `dashboard.php` | everyone | Role-aware overview: counters, sortable department table, metric column chart, status doughnut, target progress, recent activity |
| `announcements.php` | everyone | Announcement Centre — read, search, filter, bookmark, read receipts; Director/Admin also publish, pin, schedule, archive and see read analytics |
| `approvals.php` | Admin, HoD | Approve or reject submitted records |
| `reports.php` + `export.php` | everyone | Filtered record lists, downloadable as Excel / Word / CSV, plus Print-to-PDF |
| `upload.php` | all but Director | Submit academic records |
| `users.php` | Admin | Accounts: add, edit, delete, reset password |
| `departments.php` | Admin | The canonical department list |
| `targets.php` | Admin, HoD | Set and track targets |
| `faculty.php` | HoD | Their department's staff and how much each has submitted |
| `profile.php` | everyone | Own details and password |
| `settings.php` | Admin | Metrics, own account, and system information |
| `download.php` | everyone | Serves announcement attachments, after checking access |

---

## 1. Requirements

| Need   | Notes |
|--------|-------|
| **MySQL 8** | ✅ Already running on your machine (verified: `localhost:3306`, db `atts_main`). |
| **PHP 8.0+** | Not yet installed on this machine. Easiest: install **XAMPP** (bundles PHP + Apache) from https://www.apachefriends.org. You can keep using your existing MySQL — you don't have to use XAMPP's. |

The app needs PHP's **PDO MySQL** extension (`pdo_mysql`), which XAMPP enables by
default.

---

## 2. Database

**Good news — it's already set up.** The `atts_main` database, all 14 tables, and
the seed data were created and verified against your MySQL during the build.

To rebuild it from scratch at any time, run the SQL files (in order) with
your favourite tool — MySQL Workbench, phpMyAdmin, or the CLI:

```sql
-- 1) creates the database + all tables (drops existing ones first)
SOURCE sql/schema.sql;
-- 2) inserts users, departments, metrics, and sample records
SOURCE sql/seed.sql;
-- 3) adds the Announcement Centre tables (already applied to atts_main)
SOURCE sql/announcements.sql;
```

`announcements.sql` is additive — it only uses `CREATE TABLE IF NOT EXISTS`, so
it is safe to run on a database that already holds records. Until it is run, the
Announcements page shows a short setup note instead of crashing.

Or from a terminal that has the `mysql` client:

```bash
mysql -u root -p < sql/schema.sql
mysql -u root -p < sql/seed.sql
```

`schema.sql` is safe to re-run (it drops and recreates the tables). Run
`seed.sql` **once** per rebuild (re-running it hits the unique-email constraint).

---

## 3. Configure

All credentials live in a single editable **`.env`** file (not in the PHP code,
and not committed to git). It's already filled in with your details:

```ini
# php-app/.env
DB_HOST=localhost
DB_PORT=3306
DB_NAME=atts_main
DB_USER=root
DB_PASS=uvaish@123
BASE_URL=/php-app        # see "Run" below
APP_DEBUG=true           # set to false in production
```

To point at a different database, **edit `.env` only** — nothing in PHP needs to
change. A safe-to-share template is in `.env.example` (copy it to `.env` on a new
machine). `inc/config.php` reads `.env` and applies sensible defaults if a key is
missing.

### Security

- **`.env` is never web-accessible or committed** — the root `.htaccess` denies
  HTTP access to `.env`, `.sql`, dotfiles, and docs, and `.gitignore` excludes
  `.env` and uploads.
- Directory listing is disabled; the PHP version is not advertised; baseline
  security headers (`X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`)
  are sent on every response.
- Sessions use **HttpOnly + SameSite=Lax** cookies (and Secure over HTTPS).
- Every query uses **PDO prepared statements**; every form carries a **CSRF token**;
  all output is HTML-escaped.
- Set `APP_DEBUG=false` in `.env` before deploying so error details are hidden.

> On Nginx (instead of Apache) the `.htaccess` is ignored — block `.env`/`.sql`
> in the server config, or keep the app behind a document root that excludes them.

---

## 4. Run

**Option A — XAMPP (Apache):**
1. Copy the `php-app` folder into `C:\xampp\htdocs\`.
2. Start Apache from the XAMPP control panel.
3. Keep `BASE_URL` as `'/php-app'`.
4. Visit **http://localhost/php-app/**

**Option B — PHP's built-in server (no Apache):**
1. In `inc/config.php`, set `define('BASE_URL', '');` (served at the root).
2. From inside the `php-app` folder:
   ```bash
   php -S localhost:8000
   ```
3. Visit **http://localhost:8000/**

---

## 5. Log in

| Role | Email | Password |
|------|-------|----------|
| Admin | `mohameduvaish132@gmail.com` | `uvaish123` |
| Director | `director@atts.edu` | `director123` |
| HoD (CSBS) | `hod@atts.edu` | `hod12345` |
| Coordinator (CSBS) | `coordinator@atts.edu` | `coord1234` |
| Faculty (CSBS) | `faculty@atts.edu` | `faculty123` |

Each role gets its own sidebar and dashboard scope (Admin/Director see the whole
institution; HoD/Coordinator/Faculty are scoped to their department; Faculty see
only their own submissions).

---

## 6. How the conversion maps over

| React / Node / Mongo | PHP / MySQL |
|---|---|
| JWT in localStorage | **PHP session** (`inc/auth.php`) |
| Express routes/controllers | one `.php` page per screen |
| Mongoose models | SQL tables + data-access functions in `models/` |
| MongoDB collections | MySQL tables (`sql/schema.sql`) |
| Axios services | direct PDO queries (prepared statements) |
| React Router | plain page links + server redirects |
| Tailwind classes | `assets/css/app.css` (same navy/orange design) |
| React state/rerender | server-rendered HTML per request |

### Layout
```
php-app/
  index.php  login.php  logout.php  dashboard.php  departments.php   ← pages
  coming-soon.php  denied.php
  inc/     config.php db.php auth.php helpers.php nav.php icons.php header.php footer.php
  models/  Dashboard.php  Department.php
  sql/     schema.sql  seed.sql
  assets/css/app.css
  uploads/  (proof files)
```

Security: `inc/`, `models/`, and `sql/` carry `.htaccess` deny rules; all queries
use PDO prepared statements; forms carry CSRF tokens; output is escaped.

---

## 7. Verified

Against your live MySQL 8.0.42 during the build:
- ✅ `atts_main` created, all **14 tables** built from `schema.sql`
- ✅ Seed loaded (5 users, 3 departments, 9 metrics, 4 journals)
- ✅ Foreign-key joins resolve
- ✅ Department-breakdown grouped query works
- ✅ Full CRUD on departments (create / read / update / unique-constraint / delete)
- ✅ bcrypt password hashes stored (PHP `password_verify` compatible)

The app now runs on a bundled portable PHP 8.4 runtime (`.php-runtime/`, started
by `start-server.bat`). Every page was loaded and exercised end-to-end for all
five roles against the live MySQL — all render without PHP errors, and the
role-based access rules (403s for out-of-scope pages) hold.

**Security fixes applied during that pass:**
- **Approvals** — an HoD could approve/reject records from *other* departments
  by posting a forged record id. `record_review()` now enforces the reviewer's
  department in the SQL `WHERE`, so out-of-scope ids match no row.
- **Targets** — an HoD could create/edit/delete targets for other departments.
  Writes are now pinned to the HoD's own department.
- **Upload** — the insert accepted *any* posted field as a column
  (mass-assignment). It now whitelists real, non-protected columns of the target
  table, so `status`, `approved_by`, `created_by`, etc. can't be set from a form,
  and raw SQL errors are no longer echoed to the user.
