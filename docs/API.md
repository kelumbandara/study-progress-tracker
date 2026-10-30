# Phone API (v1)

The JSON API used by the Expo / React Native app. It runs on the same server and database as the website and calls the **same services**, so the rules are identical: Mastered needs an independent attempt of 80% or more, question and topic minutes never add to the daily totals, a missing day is not a zero day, and every attempt is kept.

Base URL: `https://YOUR-DOMAIN/api/v1` · Format: JSON only · Authentication: bearer token.

## Conventions

| Topic | Rule |
|---|---|
| **Auth** | `Authorization: Bearer <token>` on every call except `POST /auth/login`. A website session cookie never works here. |
| **Content type** | Send `Content-Type: application/json` and `Accept: application/json`. Responses are always JSON, including errors and unknown URLs. |
| **Dates** | Always `YYYY-MM-DD` strings in the **server's time zone** (`Asia/Colombo`). Never timestamps for dates, so a day cannot shift. |
| **"Today"** | Use `today` from `/bootstrap` (or any response that has it). Do not use the phone's clock: it may be wrong or in another time zone. |
| **Durations** | Whole **minutes** (integers). Hours/minutes are a display matter for the app. |
| **Marks** | Numbers. A whole number may arrive as `8` rather than `8.0`; treat them as numbers. |
| **Ids** | Integers. |
| **Pagination** | Lists accept `per_page` (1 to 60) and `page`; they return `data` and `meta: { page, last_page, total }`. |
| **Idempotent registration** | `POST /papers` and `POST /papers/{id}/questions` return **201** with `created: true` when new, or **200** with `created: false` when it already existed. A retry after a dropped connection is harmless. Attempts and theory entries are **not** idempotent (each POST adds one). |
| **Rate limits** | Sign-in: 5 wrong attempts per minute per e-mail and address (then HTTP 422 with a wait message); 20 sign-in requests per minute per address. Everything else: 240 requests per minute per token (HTTP 429 when exceeded). |
| **Token life** | 365 days (`SANCTUM_TOKEN_EXPIRATION` minutes). Signing in again on the same device name replaces its old token. **Changing the password revokes all tokens**, so every phone must sign in again. |

### Errors

| HTTP | Body | Meaning / what the app should do |
|---|---|---|
| 401 | `{"message":"Unauthenticated."}` | No, wrong, expired or revoked token. Delete the stored token and show the sign-in screen. |
| 404 | `{"message":"..."}` | Unknown id or URL. |
| 422 | `{"message":"...","errors":{"field":["text"]}}` | Validation or rule refusal (for example Mastered without evidence, marks above the maximum). Show `errors` next to the fields; show the text of a rule refusal as is. |
| 429 | `{"message":"Too Many Attempts."}` | Slow down. |
| 5xx | `{"message":"Server Error"}` | Server problem; retry later. |

## Endpoints

### Session

| Call | Body | Returns |
|---|---|---|
| `POST /auth/login` | `email`, `password`, `device_name` (e.g. "Pixel 7") | `token`, `token_type: "Bearer"`, `expires_at`, `user {name,email}` |
| `POST /auth/logout` | none | Revokes this device's token only |
| `GET /me` | none | `user {name,email}` |
| `GET /bootstrap` | none | `today`, `timezone`, `study_period {start,end,exam_date}`, `paper_years {from,to}`, `subjects[]`, `options {categories, topic_statuses, attempt_results, mistake_categories}`, `rules {mastery_pct, min_attempts_for_advice, max_minutes_per_day}` |

Call `/bootstrap` once when the app opens; the option lists (statuses, results, mistake categories) are the labels to show.

### Dashboard

`GET /dashboard` → `today`, `today_logged`, `period` (days total/elapsed/remaining, percent), `categories[4]` (planned/actual/remaining/percent/status/required per week/warnings; `configured:false` and `status:"not_configured"` when no planned hours are set), `totals`, `subjects[]` (syllabus coverage, attempts, marks earned and available, weighted average, first-attempt vs retry, independent vs assisted, latest timed and full paper, weak topics, revision backlog, trend, `enough_data`), `consistency`, `recommendations[] {level,text}`, `weekly_hours`, `score_trend`.

### Daily log

| Call | Notes |
|---|---|
| `GET /daily-logs/{date}` | `log {date, logged, minutes{school,tuition,theory,papers}, total_minutes, is_rest_day, rating, notes}` where `logged:false` and `minutes:null` mean **missing** (not zero); also `warnings[]`, `cumulative_minutes`, `days_so_far`, `missing_days`, `today` |
| `PUT /daily-logs/{date}` | Body `{"minutes":{"school":330,"tuition":120,"theory":75,"papers":120},"is_rest_day":false,"rating":4,"notes":"..."}`. **All four minutes are required** (so nothing is zeroed by accident). 0 to 1440 each, total at most 1440, date not in the future. Creates the day or edits it (with an audit trail). Returns the same payload as GET |
| `GET /daily-logs` | Recent logs, newest first (paginated) |

### Syllabus

| Call | Notes |
|---|---|
| `GET /syllabus?subject_id=` | `stats`, `units[] {id,name,reference,stats,topics[] {id,name,reference,status,status_label,confidence,theory_completed_on,theory_minutes,practised,subtopics[]}}` |
| `PUT /syllabus/topics/{id}` | `{"status":"theory_completed","confidence":3,"note":"..."}`. Statuses: `not_started`, `in_progress`, `theory_completed`, `questions_practised`, `revision_required`, `mastered`. **`mastered` returns 422** unless an independent attempt of at least 80% exists on a linked question |
| `GET /topics?subject_id=` | Flat topic list for pickers (`id`, `name`, `full_name`, `unit`, `status`) |

### Papers, questions and attempts

| Call | Notes |
|---|---|
| `GET /papers?subject_id=&year=` | Registered papers with question and attempt counts |
| `POST /papers` | `subject_id`, `exam_year`, `paper_name` (+ optional `max_marks`, `duration_minutes`, links). See idempotent registration above |
| `GET /papers/{id}/questions` | Questions with `number`, `max_marks`, `attempts_count`, `topic_ids` |
| `POST /papers/{id}/questions` | `question_no`, `max_marks`, optional `topic_ids[]`, `reference`, `notes` |
| `GET /papers/{id}/paper-attempts` | Earlier full-paper sittings (to link a question to one) |
| `POST /attempts` | `question_id`, `attempt_date`, `marks_earned` (not above the question maximum), `result` (`correct`, `partial`, `wrong`, `not_completed`), `independent` (bool), optional `worked_minutes`, `checked_with_scheme`, `mistake_category`, `notes`, `paper_attempt_id`. **Always adds a new attempt** (the next number). Returns `attempt`, `improvement` (score points, minutes change, best; null until a second attempt), `topics[]` (their status after this attempt), `warnings[]` |
| `PUT /attempts/{id}` | Corrects marks, time, result, assistance, notes or date. The question and attempt number cannot be changed. Kept in the audit trail. May send a topic back to Revision Required if it removes the evidence behind Mastered |
| `GET /questions/{id}/attempts` | Question details, all attempts oldest first, and `improvement` |
| `GET /attempts?subject_id=` | Attempt history (paginated) |
| `GET /paper-attempts` · `POST /paper-attempts` · `PUT /paper-attempts/{id}` | Complete-paper results. `total.source` is `"questions"` when question attempts are linked (the total is then their sum, never added to the typed total) or `"manual"` |

### Theory, mistakes, reports

| Call | Notes |
|---|---|
| `POST /theory` | `study_date`, `topic_id`, `minutes`, optional `marked_complete`, `confidence`, `notes`. Marking complete never marks the topic mastered. Minutes are part of the day's Study Theory total, not extra; `warnings[]` appears if the day's detail exceeds its total |
| `PUT /theory/{id}` · `GET /theory` | Correct an entry (date, minutes, confidence, notes); list entries |
| `GET /mistakes` | Questions needing review: `reasons[]`, `repeated`, `latest_attempt`, `last_review` |
| `POST /mistakes` | `question_id`, `reviewed_on`, and the booleans `theory_reviewed`, `solution_understood`, `reattempted`, `mastered_independently` (**422** unless the latest attempt is independent and at least 80%) |
| `GET /reports` | `type` (`weekly`/`monthly`), `anchor` (a date in the wanted week or month) **or** `from`+`to` (at most 400 days), optional `subject_id`. Same content as the website report. Excel/CSV export and printing stay on the website |

### Phone use (minutes per app)

| Call | Notes |
|---|---|
| `PUT /phone-usage/{date}` | Body `{"apps":[{"package":"com.whatsapp","label":"WhatsApp","minutes":45}, ...]}`. The phone sends the **complete** day, which replaces what the server held for that date (repeating is harmless). `minutes` 0 to 1440; `package` letters, digits, dots and underscores; at most 300 apps; dates from the last 31 days up to today. Apps with 0 minutes are dropped. Returns `apps_stored`, `total_minutes`, `synced_at`. Only names and minutes are ever sent: no messages, links or screen content |
| `GET /phone-usage?days=7` | Summary ending today (1 to 90 days): `days[]` (`total_minutes` is **null** for a day the phone never reported, which is not the same as 0), `top_apps[]`, `categories`, `avg_per_logged_day`, `last_sync`, `today` |
| `DELETE /phone-usage` | Erases everything the phone ever sent (`deleted_rows`) |

## Trying it with curl

```bash
# sign in
curl -s https://YOUR-DOMAIN/api/v1/auth/login -H "Content-Type: application/json" \
  -d '{"email":"you@example.com","password":"...","device_name":"curl"}'

# log today's time (replace TOKEN and the date)
curl -s -X PUT https://YOUR-DOMAIN/api/v1/daily-logs/2026-11-09 \
  -H "Authorization: Bearer TOKEN" -H "Content-Type: application/json" \
  -d '{"minutes":{"school":330,"tuition":120,"theory":75,"papers":120}}'
```

## Notes for the app

* Keep the token in secure storage (not plain storage). On any 401, clear it and show sign-in.
* Never add up hours on the phone; show the numbers the API returns (the server holds the rules).
* Queue nothing silently: if a save fails, tell the student. (A later phase may add an offline queue for the daily log.)
* **Development on a home network** can use plain `http://<computer-ip>:<port>` (Expo Go allows it). **Release builds require HTTPS**, which your server already has.
* The API is additive-only inside `v1`: new fields may appear, existing ones will not change meaning. A breaking change would be a new `/api/v2`.

## Verified how

40 PHP tests (`tests/Feature/Api/`) cover sign-in, token life and revocation, every endpoint's rules and validation, date formats, the audit trail and hostile input. `tests/e2e/api.js` runs a phone-style client (plain `fetch` + bearer token) against the production container behind an HTTPS proxy: 13 of 13 steps passed, 65 requests, median 32 ms, including parallel-request checks. See the [test report](TEST_REPORT.md).
