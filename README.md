# A/L Study Progress Tracker

A simple web app that helps a Sri Lankan G.C.E. A/L student (Combined Mathematics, Physics, IT) and a parent see, with real numbers, whether preparation for the **August 2027** exam is on track.

The student spends **no time in the app while studying**. Once at the end of the day they enter four durations (School, Tuition, Study Theory, Write Exam Papers) and, when they have done past-paper questions, the marks. The app does the rest: planned vs actual hours, syllabus coverage, which topics are weak, whether marks are improving, and what to do next.

* Built with Laravel 12, MySQL/MariaDB, Blade + AdminLTE (Bootstrap), Chart.js, maatwebsite/excel.
* **One shared account** protects everything behind a **login** (no separate student/parent roles). Safe to host on a server with HTTPS: see [Deploying to Coolify](docs/DEPLOY_COOLIFY.md) and [Security](#6-security-read-this).
* English only. Mobile-friendly.
* **Phone API** (`/api/v1`, bearer tokens) for the Android app (React Native + Expo, in progress): see [docs/API.md](docs/API.md).
* Documents: [User guide](docs/USER_GUIDE.md) · [Deploying to Coolify](docs/DEPLOY_COOLIFY.md) · [Phone API](docs/API.md) · [Test report](docs/TEST_REPORT.md) · [Syllabus sources](docs/SYLLABUS_SOURCES.md)

---

## 1. What you need

| Item | Version used / tested |
|---|---|
| Windows + **XAMPP** (Apache not required, MySQL/MariaDB is) | PHP 8.2.12, MariaDB 10.4.32 |
| **Composer** | 2.8 |
| A modern browser (internet needed for charts and searchable dropdowns, which load from a CDN) | Microsoft Edge |
| Node.js (optional, only for the browser test suite) | 22 |

PHP extensions: `mbstring`, `openssl`, `pdo_mysql`, `fileinfo`, `intl`, `zip`, `gd`, `xml` (all on by default in XAMPP).

## 2. Install (first time)

Open PowerShell in the project folder (for example `C:\xampp\htdocs\study-progress-tracker`).

```powershell
# 1. Start MySQL (XAMPP Control Panel -> MySQL -> Start)

# 2. Create the empty database
C:\xampp\mysql\bin\mysql.exe -uroot -e "CREATE DATABASE `study-progress-tracker` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 3. Install PHP packages and create your settings file
composer install
copy .env.example .env
php artisan key:generate

# 4. Create the tables and the starting data (1 student, 3 subjects, study period 28 Oct 2026 - 31 Jul 2027)
php artisan migrate --seed

# 5. Create the sign-in account (there is no registration page). It asks for a password:
#    at least 12 characters, with letters and numbers
php artisan tracker:create-user you@example.com --name="Student"

# 6. Run the app
php artisan serve --port=8080
```

Open **http://127.0.0.1:8080** and sign in. Leave the PowerShell window open while using the app (close it to stop the app). "Keep me signed in" is ticked by default, so each browser only needs to sign in once. Forgot the password? `php artisan tracker:set-password you@example.com`. You can also change it from the menu (*Change password*).

**Putting it on a server?** Follow [docs/DEPLOY_COOLIFY.md](docs/DEPLOY_COOLIFY.md). A `Dockerfile` is included.

`.env` is already set for XAMPP's defaults (database `study-progress-tracker`, user `root`, **no password**, time zone `Asia/Colombo`). Change `DB_PASSWORD` there if your MySQL has one.

### First-time setup inside the app (about 10 minutes)

1. **Settings**: check the study dates and the time zone; enter the **total planned hours for the whole period** for School, Tuition, Study Theory and Write Exam Papers (decimals are fine, e.g. 125.5). Until you do, the dashboard honestly says *Not Configured*. Set each subject's priority and weak-score threshold.
2. **Syllabus**: press **Load NIE syllabus** (see [Syllabus sources](docs/SYLLABUS_SOURCES.md) for what it contains and its limits), or import your own file (template: `database/data/templates/syllabus_template.csv`).
3. **Past Papers**: register the papers you have (Subject, Year, Paper, marks, official duration) by hand, or import (`papers_template.csv`, then `questions_template.csv`). The app does **not** ship exam papers or invent marks. You can also register a paper or question on the spot while recording an attempt.
4. Try **Settings -> Sample data -> Load sample data** to see a populated dashboard, then **Remove all sample data** before you start for real.

## 3. Daily use

See the [User guide](docs/USER_GUIDE.md). In one line: *Enter Daily Log* every evening (about 2 minutes), *Record Question* after doing past-paper questions, *Reports* once a week with a parent.

## 4. Backups and restore

Your records live only in the MySQL database. **Back it up regularly**, for example every Sunday.

```powershell
powershell -ExecutionPolicy Bypass -File scripts\backup-database.ps1
```

This writes `backups\study-progress-tracker_YYYY-MM-DD_HHmm.sql` and keeps the newest 30 (use `-Keep 60` for more). It only reads the database. **Copy the `backups` folder to a USB drive or a cloud folder** so a failed computer does not lose everything. (The script's output was verified by restoring it into a scratch database and comparing table and row counts.)

To restore (this replaces the current data):

```powershell
C:\xampp\mysql\bin\mysql.exe -uroot -e "DROP DATABASE IF EXISTS `study-progress-tracker`; CREATE DATABASE `study-progress-tracker` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
cmd /c "C:\xampp\mysql\bin\mysql.exe -uroot study-progress-tracker < backups\study-progress-tracker_2026-10-28_1000.sql"
```

(If your MySQL has a password, add `-p` after `-uroot`.) A single table can also be exported from phpMyAdmin (http://localhost/phpmyadmin). Reports can be exported to Excel/CSV from the **Reports** page for a human-readable copy.

## 5. Updating the app later

```powershell
composer install
php artisan migrate        # only adds new tables/columns; never deletes your data
php artisan view:clear
```

Take a backup first.

## 6. Security (read this)

* **Every page and action needs the account**, except the sign-in page and the `/up` health check (which shows no data). A test walks every route the app has and fails if one becomes reachable without signing in.
* There is **one shared account**, no registration page and no public password reset: accounts are created and reset on the server (`tracker:create-user`, `tracker:set-password`). Sign-in allows 5 wrong attempts a minute per address and e-mail. Changing the password signs out every other device. Use a long, unique password: anyone who has it can read and change the records. There is **no second factor**.
* **On a public server use HTTPS only.** The app then sends secure, HttpOnly cookies, HSTS and anti-framing headers (see [docs/DEPLOY_COOLIFY.md](docs/DEPLOY_COOLIFY.md)). `APP_DEBUG` **must be `false`** there. The local `.env` has `true` for development.
* The app protects against the usual web attacks (CSRF tokens on every form, escaped output, validated input, parameterised queries, validated uploads; tested, see the [Test report](docs/TEST_REPORT.md)).
* Laravel is on **12.70**; `composer audit` reports no known advisories (checked 29 Oct 2026). Re-run `composer audit` now and then.
* On your own computer `php artisan serve` listens only on `127.0.0.1`, so other devices cannot reach it.
* Uploaded import files are only read and are not kept on disk. Every change records which account made it.

## 7. Troubleshooting

| Problem | What to do |
|---|---|
| Browser says it cannot connect | `php artisan serve` is not running, or you closed its window. Start it again. |
| "Connection refused" / SQLSTATE[HY000] [2002] | MySQL is not running. XAMPP Control Panel -> MySQL -> Start. |
| "Access denied for user" | `DB_USERNAME`/`DB_PASSWORD` in `.env` do not match your MySQL. |
| "Unknown collation: utf8mb4_0900_ai_ci" | `DB_COLLATION=utf8mb4_unicode_ci` is missing from `.env` (XAMPP's MariaDB needs it). |
| Port 8080 already in use | `php artisan serve --port=8090` and open that address instead. |
| Charts or searchable dropdowns do not appear | These load from the internet (CDN). Check the connection. The rest of the app still works. |
| Changed `.env` but nothing happens | `php artisan config:clear` |
| White page / error after an update | `php artisan view:clear` and `php artisan cache:clear` |

The error log is `storage\logs\laravel.log`.

## 8. Tests

```powershell
# one-time: a separate, empty database for tests (NEVER point tests at your real database)
C:\xampp\mysql\bin\mysql.exe -uroot -e "CREATE DATABASE IF NOT EXISTS `study-progress-tracker_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

php artisan test            # 164 automated tests, about 20 seconds
```

`phpunit.xml` already points tests at `study-progress-tracker_test`; the tests wipe and rebuild that database each run. An optional real-browser suite (Edge, 46 steps, including sign-in and logout) is in `tests/e2e/` (see its README); it needs an account in the test database. Results are in the [Test report](docs/TEST_REPORT.md).

## 9. Project map

| Where | What |
|---|---|
| `app/Services/` | All the rules and calculations: `TargetProgressService` (planned vs actual), `StudyPeriodService` (dates), `AnalyticsService` (scores, trends), `RecommendationService`, `ReportService`, `MistakeService`, `TopicService` (mastery rule), `AttemptService`, `DailyLogService`, `ImportService`, `SampleData` |
| `app/Http/Controllers/` | Thin controllers, one per page |
| `resources/views/` | Blade pages (AdminLTE) |
| `database/migrations/`, `database/seeders/` | Schema and starting data (`SampleDataSeeder` is optional demo data) |
| `database/data/` | NIE syllabus file and empty import templates |
| `config/tracker.php` | Thresholds: mastery percentage (80), minimum review percentage, "unrealistic rate" factor (1.5) |
| `scripts/backup-database.ps1` | Backup script for the Windows/XAMPP setup |
| `Dockerfile`, `docker/`, `.dockerignore` | Production container for Coolify (see [docs/DEPLOY_COOLIFY.md](docs/DEPLOY_COOLIFY.md)) |
| `app/Http/Controllers/AuthController.php`, `app/Console/Commands/` | Sign-in, password change; `tracker:create-user`, `tracker:set-password` |
| `app/Http/Middleware/SecurityHeaders.php` | Security headers (anti-framing, HSTS in production, ...) |
| `routes/api.php`, `app/Http/Controllers/Api/V1/`, `app/Support/ApiPresenter.php` | The phone API and its explicit response shapes ([docs/API.md](docs/API.md)) |
| `app/Services/PaperRegistry.php` | Duplicate-proof registration of papers and questions (web and API) |
| `tests/` | PHP tests (`Unit`, `Feature`) and browser tests (`e2e`) |

## 10. Known limitations

The complete, honest list is in the [Test report](docs/TEST_REPORT.md#7-known-gaps-and-limitations). The main ones: one shared account only (no separate student/parent roles, no second factor); nothing can be **deleted** (records can be **corrected** with a change history, but not removed); paper PDFs are links only (no file upload); "planned study sessions" are not implemented; official past-paper marks and the full official syllabus detail are not bundled (see [Syllabus sources](docs/SYLLABUS_SOURCES.md)); only tested in Microsoft Edge.
