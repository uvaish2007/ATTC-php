# Team Setup — ATTS IQAC Portal

How a teammate gets this project running on their own machine, and how the
database is shared. Nothing here contains a password — real credentials live
only in your own `php-app/.env`, which is never committed.

---

## 1. Requirements

| Need | Notes |
|------|-------|
| **MySQL 8** | Running locally (default `localhost:3306`). |
| **PHP 8.0+** | Either the bundled portable runtime (`.php-runtime/`, Windows) started by `start-server.bat`, or install **XAMPP** and serve `php-app/` from `htdocs`. |

PHP needs the **PDO MySQL** extension (`pdo_mysql`) — XAMPP has it on by default.

---

## 2. Configuration — `php-app/.env`

You do **not** edit any PHP. All connection settings come from one file.

```bash
cd php-app
cp .env.example .env
```

Then open `.env` and set your own MySQL details:

```ini
DB_HOST=localhost
DB_PORT=3306
DB_NAME=atts_main
DB_USER=root
DB_PASS=your_mysql_password_here
BASE_URL=/php-app        # or "" if you serve php-app/ as the web root
APP_DEBUG=true           # false in production
```

`php-app/inc/config.php` reads this file and applies sensible defaults if a key
is missing, so the app boots even before you customise it. **`.env` is git-ignored
— never commit it.**

---

## 3. The database

Everything lives in one MySQL database named **`atts_main`**. Pick ONE option.

### Option A — import the shared dump (fastest, same data as the lead)

The project lead shares **`php-app/sql/database.sql`** with you **privately**
(direct file / private drive — it is *not* in this public repo, because it holds
real records and password hashes). Then, from the project root:

```bash
mysql -u root -p < php-app/sql/database.sql
```

That single file creates the `atts_main` database, all tables, and all data.

### Option B — build a fresh, empty-ish database from the tracked SQL

If you just want the schema and starter data (no real records), run these in
order:

```bash
mysql -u root -p < php-app/sql/schema.sql              # tables
mysql -u root -p atts_main < php-app/sql/seed.sql              # starter users, departments, metrics
mysql -u root -p atts_main < php-app/sql/announcements.sql
mysql -u root -p atts_main < php-app/sql/target_workflow.sql   # target review + coordinator columns
mysql -u root -p atts_main < php-app/sql/app_settings.sql
mysql -u root -p atts_main < php-app/sql/report_template.sql
mysql -u root -p atts_main < php-app/sql/target_unlock.sql
mysql -u root -p atts_main < php-app/sql/meeting_report_cse.sql  # optional CSE sample report
```

---

## 4. Run

- **Windows (portable PHP):** double-click `start-server.bat`, then open
  <http://localhost:8000/php-app/login.php>
- **XAMPP:** copy `php-app/` into `htdocs/`, start Apache, open
  <http://localhost/php-app/>

> The portable PHP runtime (`.php-runtime/`, ~90 MB) is **not** in the repo.
> On Windows, download PHP 8.4 NTS x64 from <https://windows.php.net/download>
> and unzip it into a folder named `.php-runtime` next to `start-server.bat`.
> On XAMPP you don't need it.

---

## 5. Log in

Starter accounts (from `seed.sql`) — change these before any real use:

| Role | Email | Password |
|------|-------|----------|
| Admin | `mohameduvaish132@gmail.com` | `uvaish123` |
| Director | `director@atts.edu` | `director123` |
| HoD (CSBS) | `hod@atts.edu` | `hod12345` |
| Coordinator | `coordinator@atts.edu` | `coord1234` |
| Faculty | `faculty@atts.edu` | `faculty123` |

---

## Sharing the database later

To regenerate the shareable dump after you've changed data:

```bash
mysqldump -u root -p --databases atts_main --default-character-set=utf8mb4 --add-drop-database > php-app/sql/database.sql
```

Send that file to teammates privately. **Do not commit it** — this repo is
public, and the dump contains real user data and password hashes. (If you'd
rather share through the repo, make the repository private first.)
