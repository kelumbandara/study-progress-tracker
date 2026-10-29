# Deploying to Coolify

This guide puts the tracker on your own server with **Coolify**, behind **HTTPS** on your domain, with a **login**. Allow about 30 minutes.

> **What was tested, and what was not.** The `Dockerfile` in this project was built and run on Docker, in production mode, together with **MariaDB 11.8** and **MySQL 8.4** containers and an **HTTPS reverse proxy** in front of it (Caddy, which sends the same forwarded headers Coolify's proxy does). The full browser suite (46 steps) and a parallel-request probe passed that way. **It was not tested on a real Coolify installation**, so the menu names below come from Coolify's documentation and may differ slightly in your version. If a step does not match your screen, the settings themselves (port 8080, the environment variables, the health-check path) are what matter.

## 0. Before you start

| You need | Notes |
|---|---|
| A server with Coolify installed and working | Coolify's own proxy must already serve HTTPS for other sites |
| Your domain's DNS pointing at the server | e.g. an `A` record for `tracker.example.com` (use your own) |
| The project in a **Git repository** that Coolify can read (GitHub, GitLab, Gitea, ...) | The folder is not a Git repository yet. See step 1 |
| About 1 GB of free RAM | The app plus its database are small |

## 1. Put the project in Git

In the project folder (PowerShell):

```powershell
git init
git add .
git status                       # check: no .env, no vendor, no backups folder listed
git commit -m "A/L Study Progress Tracker"
git branch -M main
git remote add origin https://github.com/YOUR-NAME/study-progress-tracker.git
git push -u origin main
```

Use a **private** repository. `.gitignore` already keeps `.env` (your secrets), `vendor`, backups and test screenshots out. **Never commit `.env`, a database backup or a file with passwords in it** (`.gitignore` already skips `docs/passwords` and `*.sql`).

## 2. Create the database in Coolify

1. **Project → Environment → + New Resource → Database → MariaDB** (MySQL also works; both were tested).
2. Set a database name (e.g. `tracker`), a user and a **strong password** (let Coolify generate them). Keep **"Make it publicly available" OFF**.
3. Start it. Note the **internal host name** Coolify shows (the "Internal URL" host, for example `abc123xyz`), the port (3306), and the name, user and password.
4. Open its **Backups** tab and add a **scheduled backup**, for example daily at 02:00, with a retention you are happy with. Strongly recommended: send it to an **S3-compatible storage** (a different place from the server) so a lost server does not lose the records.

## 3. Create the application

1. **+ New Resource → Application → Private/Public Repository** → pick your repository and branch `main`.
2. **Build pack: Dockerfile** (the file is in the project root).
3. **Ports Exposes: `8080`**. (The container listens on 8080 inside. Getting this wrong gives "Bad Gateway".)
4. **Domains:** `https://tracker.example.com` (your domain, with `https://`). Coolify obtains the certificate automatically.
5. Health check: the image already declares one (`/up`). If Coolify shows a health-check section, use path `/up`, port `8080`.

## 4. Environment variables

In the application's **Environment Variables** add these. **Untick "Available at Buildtime"** for secrets (they are needed only when the app runs).

| Variable | Value | Why |
|---|---|---|
| `APP_NAME` | `A/L Study Progress Tracker` | |
| `APP_ENV` | `production` | Turns on production behaviour |
| `APP_DEBUG` | `false` | **Must be false** on a public site |
| `APP_KEY` | a new key (see below) | Encrypts sessions and cookies |
| `APP_URL` | `https://tracker.example.com` | Your real address, with `https://` |
| `APP_TIMEZONE` | `Asia/Colombo` | |
| `LOG_CHANNEL` | `stderr` | Errors appear in Coolify's **Logs** tab |
| `LOG_LEVEL` | `warning` | |
| `DB_CONNECTION` | `mysql` | Used for both MariaDB and MySQL |
| `DB_HOST` | the database's internal host name from step 2 | |
| `DB_PORT` | `3306` | |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | from step 2 | |
| `DB_COLLATION` | `utf8mb4_unicode_ci` | Works on MariaDB and MySQL |
| `SESSION_DRIVER` | `database` | |
| `SESSION_SECURE_COOKIE` | `true` | Cookies only travel over HTTPS |
| `SESSION_LIFETIME` | `120` | Minutes of inactivity before a browser session ends (the "Keep me signed in" option lasts about 13 months) |
| `CACHE_STORE` | `database` | |
| `QUEUE_CONNECTION` | `sync` | The app has no background jobs |
| `TRUSTED_PROXIES` | `*` | Coolify's proxy sits in front of the container |
| `SANCTUM_TOKEN_EXPIRATION` | *(optional)* `525600` | Minutes a phone stays signed in (default 365 days) |

**Generate `APP_KEY`:** on any computer with PHP, in the project folder run `php artisan key:generate --show` and paste the whole result (it starts with `base64:`). Keep it secret and keep a copy: if you lose it, existing sessions stop working (data is not lost).

## 5. Deploy and create your account

1. Press **Deploy**. On every start the container applies any new database changes automatically and makes sure the starting data exists (this is safe to repeat and never overwrites your records).
2. When it shows **Running (healthy)**, open the application's **Terminal** in Coolify and run:

   ```
   php artisan tracker:create-user you@example.com --name="Student"
   ```

   It asks for a password (at least 12 characters, with letters and numbers; typed characters are hidden).
3. In the same Terminal load the starting library once (safe to repeat; it adds nothing that already exists):

   ```
   php artisan tracker:load-library
   ```

   This loads the NIE syllabus (189 topics), the list of past papers (Combined Mathematics, Physics, IT; Paper I and II; 2016-2025) and the 2024 questions with their marks. Marks and durations are filled in only where they were checked against the official papers; other years stay blank for you to fill in.
4. Open `https://tracker.example.com`, sign in, and set your planned hours in **Settings**. The language can be switched between English and සිංහල at the top right (and on the sign-in page).

Forgot the password later? In the Terminal: `php artisan tracker:set-password you@example.com`.

## 6. Check it is safe

- Open the address in a private window: you must land on the **Sign in** page, not the dashboard.
- The address bar shows the padlock (HTTPS).
- `https://tracker.example.com/up` shows only a small health message, no data.
- Try `https://tracker.example.com/settings` while signed out: it must redirect to Sign in.

## 6b. The phone app

The Android app talks to `https://tracker.example.com/api/v1` (your domain). Nothing extra is needed on the server: the API is part of this application and uses the same login (e-mail and password), exchanged once for a token per phone. Changing the password on the website, or `tracker:set-password` in the Terminal, signs every phone out. Reference: [API.md](API.md).

## 7. Updating the app

1. In Coolify take a manual database backup first (Database → Backups → Backup now).
2. Commit and push your changes to Git. Press **Deploy** (or enable auto-deploy on push).
3. New database changes are applied automatically at start-up. Records are never deleted by an update.

## 8. Backups and restoring

- Scheduled backups of the **database** are done by Coolify (step 2). Test a restore once, so you know it works before you need it.
- To restore: download the backup file from the database's Backups tab, open the **database's** Terminal in Coolify, and import it with the database client, for example `mariadb -u USER -p DATABASE < backup.sql` (the exact commands are in Coolify's backup documentation).
- Reports can also be exported to Excel/CSV from the **Reports** page for a human-readable copy.
- The application container itself holds no data. It can be redeployed at any time.

## 9. Troubleshooting

| Symptom | Likely cause and fix |
|---|---|
| **Bad Gateway / 502** | *Ports Exposes* is not `8080`, or the container is still starting. Check **Logs**. |
| Redirects to `http://...` or endless redirects | `APP_URL` is not `https://your-domain`, or `TRUSTED_PROXIES` is missing. |
| Sign-in "does nothing" or logs you straight out | `SESSION_SECURE_COOKIE=true` but the site is opened over plain HTTP, or `APP_URL` has the wrong domain. Use HTTPS. |
| "419 Page Expired" when signing in | The page was open too long, or cookies are blocked. Reload and try again. |
| Container restarts, Logs show a database error | `DB_HOST`/`DB_PASSWORD` do not match the database resource. Check them in Coolify. |
| "Unknown collation" in the logs | Set `DB_COLLATION=utf8mb4_unicode_ci`. |
| "No application encryption key" | `APP_KEY` is missing (or it was set only as a build variable). |
| Pages work but charts and searchable dropdowns are missing | They load from a public CDN; check the browser's connection. |
| 500 error page | Set nothing public; read **Logs** (`APP_DEBUG` stays `false`). |

## 10. Security notes for a public server

- Everything except the sign-in page and `/up` requires the account. Sign-in is limited to 5 wrong attempts per minute per address and e-mail.
- Use a long, unique password. Anyone with it can read and change the records. Sessions last until you sign out ("Keep me signed in") unless you change the password, which signs out every other device.
- Cookies are `HttpOnly`, `Secure` and `SameSite=Lax`; forms carry CSRF tokens; HTTPS is enforced with HSTS; pages cannot be framed by other sites.
- There is **no second factor**. For stronger protection, put the site behind Coolify's basic-auth or an access gateway (for example Cloudflare Access) as an extra layer.
- Student data is stored only in your own database. The only outside requests the browser makes are to the CDNs for charts and dropdown search (cdnjs).
- Keep the server, Coolify and Docker images updated. Re-build the image now and then (**Deploy → Force rebuild**) to pick up PHP and Nginx security fixes.
