# Test report: A/L Study Progress Tracker

Date: 29 October 2026 · App: **Laravel 12.70** · Local: PHP 8.2.12 + MariaDB 10.4.32 (XAMPP) · Production container: PHP 8.3 + **MariaDB 11.8** and **MySQL 8.4** · Browser: Microsoft Edge (Chromium)

## 1. Summary

| Check | Result |
|---|---|
| Automated PHP tests (`php artisan test`) | **164 passed**, 1,327 assertions, about 21 s (40 of them cover the phone API) |
| Phone-API client test (`tests/e2e/api.js`, plain `fetch` + bearer token) against the production container behind HTTPS | **13 of 13 steps**; 65 requests, median 32 ms, slowest 258 ms; parallel attempts, paper registrations and daily-log saves leave no duplicates |
| Real-browser tests (`tests/e2e/run.js`, Edge via Playwright) against the **production container behind an HTTPS proxy** | **46 of 46 steps passed on MariaDB 11.8, and again on MySQL 8.4**; 0 console errors; 0 failed network requests |
| Parallel double-submit probe (`tests/e2e/conc.js`) | After one fix (section 6, #12): no server errors on either database; no duplicate or mis-numbered rows |
| Sign-in and access control | Every route is behind the login except the sign-in page and `/up`; checked automatically for all 47 routes and in the browser |
| Dependency security | `composer audit`: **no advisories** (after the Laravel 12 upgrade) |
| Load test, about 1,700 question attempts | Dashboard about 0.8 s, monthly report about 0.5 s (warm), measured before the login/Docker work |
| Phone width (375 px) | Sign-in and all 9 main pages fit with no sideways scroll |
| Database structure | Foreign keys and indexes on every data table; durations stored as integer minutes |
| Backup | Windows backup script output restored into a scratch database with matching counts. Server backups are Coolify's (not tested here) |
| Known gaps | 17 documented (section 7). None blocks the core workflows |

All automated tests ran against **separate** databases (`study-progress-tracker_test`, or throw-away containers), never your real one.

## 2. What the tests cover

| Test file | Tests | Covers |
|---|---:|---|
| `tests/Unit/TargetProgressTest.php` | 9 | Date maths; Not Configured vs zero; known sample values; behind plan; overrun not capped; end-date-passed warning; revised target flag |
| `tests/Feature/SettingsTest.php` | 9 | Pages open for the signed-in account; seed defaults; decimal hours stored as minutes; history kept; validation; period change keeps logs and is audited; actuals from daily logs only |
| `tests/Feature/TrackingTest.php` | 25 | Daily log save/edit/audit; confirmed zero vs missing day; **no double counting**; allocation warnings; repeat attempts kept; **mastery refused without independent ≥ 80% evidence**; mistake queue rules; inline paper/question registration; CSV imports; NIE syllabus load; every page renders |
| `tests/Feature/AnalyticsTest.php` | 20 | Hand-computed scores, trends, weak topics, paper results (first attempt is the result), consistency, recommendations, reports (filters, prorating, exports, formula-injection protection) |
| `tests/Feature/HardeningTest.php` | 18 | XSS escaping; hostile query strings; unknown ids → 404; mass assignment; upload type/size; **.xlsx**, BOM/CRLF and **semicolon CSV** imports; **lost-race handling for papers and questions**; midnight in Colombo; decimal limits; overrun; ended period |
| `tests/Feature/CorrectionTest.php` | 12 | Edit-in-place with audit; no-change save writes nothing; crafted requests cannot change identity; mastery withdrawn when edited evidence no longer supports it; theory and paper-attempt corrections |
| `tests/Feature/SampleDataTest.php` | 9 | Sample data flagged; removal deletes only sample records and leaves no orphans; confirmation required; `window.confirm` regression guard |
| `tests/Feature/AuthTest.php` | 22 | **Every route requires sign-in** (walks all routes and methods); JSON helpers and exports return 401; health check open and empty; return-to-wanted-page; same vague message for wrong password and unknown user; **rate limit after 5 failures**; input validation; remember-me cookie; session id regenerated (no fixation); logout only by POST; password change rules and other-device sign-out; **no registration or public reset pages** (including AdminLTE's dark-mode route, which was public and is now off); `tracker:create-user` and `tracker:set-password`; security headers; HSTS only over HTTPS in production; forwarded HTTPS honoured; cookie flags; CSRF middleware on every state-changing route; audit rows record the account |
| `tests/Feature/Api/ApiAuthTest.php` | 16 | Token login (hashed in the database, 365-day expiry); same vague message for wrong password and unknown e-mail; rate limit then recovery; input validation; one token per device; logout revokes only that device; **password change (website or server command) revokes every phone**; expired and garbage tokens refused; **every `/api/v1` route refused without a token**; a website session cannot call the API; no cookies set; JSON errors even without an Accept header; security headers; Sanctum's browser-only route not exposed |
| `tests/Feature/Api/ApiDataTest.php` | 24 | Bootstrap and server "today" around Colombo midnight; missing vs zero day; create/edit day with the **token's account in the audit trail**; validation of every field; dashboard figures equal the website's; syllabus tree; **mastery refused without evidence**; paper/question registration is idempotent; attempts added never overwritten, improvement reported; corrections edit in place and cannot change identity; paper total from linked questions not both; theory; mistake queue and review; reports (weekly, monthly, custom range); **every date field is a plain Y-m-d string**; hostile input; no leaked secrets |
| **Total** | **164** | |

**Browser suite (46 steps), run through HTTPS in production mode:** guest redirects, wrong password, **CSRF 419**, sign-in with `HttpOnly` + `Secure` + remember-me cookies, security headers (each exactly once, HSTS present), empty-state dashboard, Settings, Load NIE syllabus (189 rows), papers, Daily Log, Record Question (dropdowns, inline question registration, 3 attempts, improvement, over-maximum rejected), **mastery refused then accepted**, Record Theory, corrections, Review Mistakes, Reports and Excel/CSV downloads, dashboard charts, **sample data load/cancel/confirm**, all sidebar pages, **log out and locked out again**, mobile sign-in, nine pages at 375 px, mobile menu.

## 3. Mapping to the spec's testing list (section 18, Phase 5)

| Spec item | Evidence |
|---|---|
| Form validation and database persistence | Validation tests in every feature file; browser steps for each form |
| Repeated question attempts | `repeat attempts are preserved separately`; browser retry step (3 rows kept) |
| Date-range calculations | `TargetProgressTest`, weekly/monthly range tests, Colombo-midnight test |
| Changing planned hours | Settings tests (history, original vs revised, overrun) |
| Missing logs and zero-hour entries | `zero day is confirmed and missing day is not zero`; consistency tests |
| Prevention of double-counting question and topic durations | `question and topic minutes never add to daily totals`; paper total/time tests |
| Authorization | **Login required for everything** (AuthTest). **Student-vs-parent separation is not implemented**: there is one shared account (section 7, gap 1) |
| Excel/CSV imports and exports | CSV and .xlsx import tests; export tests; browser download check |

## 4. Concurrency (double-clicks and two open tabs)

The probe sends identical requests in parallel to the **production container** (nginx + PHP-FPM, real parallelism). Results after the fix, identical on **MariaDB 11.8 and MySQL 8.4**, each on a fresh database:

| Probe | HTTP results | Database afterwards |
|---|---|---|
| 12 simultaneous saves of the same daily-log date | 12 × success | **1** row |
| 12 simultaneous attempts on one question | 12 × success | attempt numbers **1 to 12**, no duplicates |
| 8 simultaneous full-paper attempts | 8 × success | numbered **1 to 8** |
| 10 simultaneous registrations of one paper | 1 created + 9 "already exists" | **1** row |
| 10 simultaneous registrations of one question | 1 created + 9 "already exists" | **1** row |

## 5. Performance

Test data (measured on the earlier local setup): 900 questions, 1,739 attempts, 250 daily logs, 189 topics.

| Page | Before tuning | After tuning |
|---|---|---|
| Dashboard | about 1.1 s | about 0.8 s |
| Monthly report | about 1.0 s | about 0.5 s |
| Other pages | below 0.3 s | unchanged |

Performance was **not** re-measured on the container, and datasets much larger than the above were not tested.

## 6. Defects found by testing (all fixed)

| # | Phase | Defect | Found by |
|---|---|---|---|
| 1 | 2 | "Behind plan" and "end date passed" warnings were silently dropped | Unit test |
| 2 | 2 | SQLite test database hid MySQL behaviours → tests moved to MySQL; mastery SQL rewritten portably | Test run |
| 3 | 4 | Dashboard returned HTTP 500 (Blade `@json` expression) | Test run |
| 4 | 4 | Weekly/monthly report returned 500 (missing relation) | Test run |
| 5 | 5 | "Register missing question" posted to the wrong URL (405); PHP tests could not see it | Browser test |
| 6 | 5 | A file named `evil.php` containing CSV text passed upload validation (harmless, now rejected) | Hardening test |
| 7 | 5 | Mistake queue recomputed several times per page | Load test |
| 8 | 5 | Paper results by year were calculated but never shown | Spec review |
| 9 | 5 | Flash message showed "4/10.00" | Browser test |
| 10 | 6 | **Sample-data removal confirmation never appeared** (a bare `confirm()` was shadowed by the form's own field named `confirm`); fixed with `window.confirm` + regression test | Browser test |
| 11 | 5 | Duplicate-key error possible for two identical daily-log saves; paper-attempt numbering not locked | Code review (fixed before probing) |
| 12 | 6 | **Parallel registrations of the same new paper/question returned HTTP 500** (4 of 10 and 7 of 10 failed) on the production container; the single-threaded dev server had hidden it. Now reported as "already exists"; collision tests added | Parallel probe on the container |
| 13 | 6 | AdminLTE exposed a **public** `POST /adminlte/darkmode/toggle` route (no sign-in needed); switched off | Route-walking auth test |
| 14 | 6 | The container's nginx **and** the app both sent security headers, with conflicting `X-Frame-Options` values (DENY vs SAMEORIGIN); the app is now the single source | Browser/HTTP check on the container |
| 15 | 6 | A fresh container had empty tables (no student, subjects or study period) because migrations do not seed; start-up now runs the idempotent seeder | Container trial |
| 16 | 6 | Five Laravel 11 security advisories (one critical); fixed by upgrading to Laravel 12.70 | `composer audit` |
| 17 | 7 | Installing Sanctum added a **public** `GET /sanctum/csrf-cookie` route (meant for browser single-page apps); switched off in config | Route-walking auth test |
| 18 | 7 | Sanctum would also accept a browser session cookie on API calls; set to bearer-token-only (`guard => []`) and tested | Design review + test |

Housekeeping: PowerShell 5.1 wrote a BOM into some files (breaks PHP and `.env`); all repaired, and a repository-wide BOM check was clean.

## 7. Known gaps and limitations

None is hidden by a placeholder or fake data.

1. **One shared account only.** No separate student/parent roles (spec §3), no second factor. Anyone with the password can read and change everything. Audit entries now record *which account* and *when*, but with one account that does not distinguish student from parent.
2. **Nothing can be deleted.** Records can be *corrected* with a visible change history, but wrongly registered papers, questions, units, topics and theory entries cannot be removed, and syllabus unit/topic **names** cannot be renamed. Sample-data removal is the only delete (with a confirmation).
3. **Paper PDFs are links only.** No file upload (spec §7/§16 allow links *or* uploads).
4. **Planned study sessions are not implemented** (spec §11G); the dashboard shows "Not configured".
5. **No notifications** other than the "today's log not entered" prompt (optional in the spec).
6. **Imports skip existing rows; they never update them.** The Excel import reads the first sheet only.
7. **Audit visibility.** Changes to daily logs, attempts, theory entries and planned hours are shown in the app. Study-period and subject-settings changes are recorded in `audit_logs` but have no screen.
8. **Recommendation thresholds are my choices**: 5 attempts minimum, 10 independent hours, 1.5× "unrealistic rate", 80% mastery, 50% default weak score. All are changeable (`config/tracker.php`, Settings).
9. **Syllabus detail is limited** to unit/topic structure with shortened names (see [SYLLABUS_SOURCES](SYLLABUS_SOURCES.md)). No past-paper questions or marks are included.
10. **Not covered by testing:** a **real Coolify installation** (the container was tested on Docker with a Caddy HTTPS proxy, not on Coolify itself); browsers other than Edge; a real phone; Apache hosting; opening the exported Excel file in Excel and the print preview; screen-reader use; datasets far larger than about 1,700 attempts; performance inside the container.
11. **Internet dependence:** Chart.js and Select2 load from a public CDN (cdnjs). Without internet, charts and dropdown search do not work (the rest does).
12. **No strict Content-Security-Policy.** Pages use inline scripts and CDN libraries. Other security headers are set.
13. **Sign-in throttling is per e-mail + address inside the app.** Behind a proxy all visitors share the address the proxy forwards; `TRUSTED_PROXIES=*` makes the app use the real client address. A determined attacker rotating addresses is not stopped (a long password is the real protection).
14. **Local setup defaults to `APP_DEBUG=true`.** Fine on your own computer; the deployment guide sets it to `false`.
15. **Other race windows remain** for rarely-used actions: two simultaneous identical *new syllabus topics*, or two simultaneous "Load sample data" clicks, could create duplicates or an error. They do not affect daily use.
16. **Sessions and cache live in the database**, which is simple and fine for one user; a very busy site would want Redis.
17. **Sample data is fictional**, labelled SAMPLE and removable.

## 8. Acceptance criteria (spec section 19)

| # | Criterion | Status | Evidence |
|---|---|---|---|
| 1 | Parent can configure start/end dates and planned hours | Met | Settings tests; browser step. *No separate parent role (gap 1)* |
| 2 | Student can enter daily durations with no start/end times | Met | Daily Log form; tests |
| 3 | Daily entries persist and can be edited safely | Met | Save/edit/audit tests; concurrency probe |
| 4 | Dashboard compares actual hours with total planned hours | Met | Dashboard; unit and feature tests |
| 5 | Syllabus by subject, unit, topic, subtopic | Met | `parent_id` subtopics; subtopic form test |
| 6 | Theory completion recorded with duration | Met | Theory tests; browser step |
| 7 | Past papers registered by year, subject, paper | Met | Papers page, import, inline registration |
| 8 | Questions registered without sub-question numbers | Met | Schema and forms |
| 9 | A question links to one or multiple topics | Met | Multi-topic test; browser step |
| 10 | Each attempt records marks, time, result, assistance | Met | Attempt form and tests |
| 11 | Multiple attempts preserved independently | Met | Test and browser step |
| 12 | Complete-paper results without double-counting | Met | Paper total and analytics tests |
| 13 | Weak topics and repeated mistakes identifiable | Met | Weak-topic, queue and repeated-difficulty tests |
| 14 | Dashboard shows meaningful progress toward 31 July 2027 | Met | Period card, expected-vs-actual, forecast |
| 15 | Weekly and monthly reports generated and exported | Met | Report tests; Excel/CSV downloads (print not visually verified) |
| 16 | Missing records not silently treated as zero | Met | Consistency and daily-log tests |
| 17 | Calculations tested with known sample values | Met | Hand-computed assertions |
| 18 | Responsive, secure, usable on desktop or mobile browser | Met with limits | 375 px checks; login, CSRF, headers, HTTPS tested; Edge only (gap 10) |
| 19 | All core functions use persistent data; nothing simulated | Met | Figures come from database queries; sample data optional and labelled |
| 20 | Parent can review progress without supervising every session | Met | Dashboard and weekly report. *Single shared account (gap 1)* |

## 9. How to repeat these tests

```powershell
php artisan test                         # 164 PHP tests
# browser + parallel suites, locally or against the production container:
#   see tests\e2e\README.md
```
