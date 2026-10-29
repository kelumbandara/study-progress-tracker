# User guide: A/L Study Progress Tracker

For the **student** and the **parent**. There is **one shared account**: whoever has the e-mail and password sees and can change everything, so share it only with the people who should. (Setup steps are in the [README](../README.md); putting it on a server is in [DEPLOY_COOLIFY.md](DEPLOY_COOLIFY.md).)

**Signing in.** Open the app's address and sign in with the e-mail and password. Leave **Keep me signed in** ticked and each phone or computer only asks once (about 13 months). To sign out (for example on a shared computer) use your name at the top right → **Log Out**. To change the password use **Change password** in the left menu; this signs out every other device. Forgotten it? Whoever looks after the server can reset it with `php artisan tracker:set-password`.

## 1. The idea in one minute

* The student studies **without a phone or timer**. At the end of the day they type in four totals. That takes about two minutes.
* After doing past-paper questions they record the marks. Every attempt is kept, so improvement can be measured (4/10 → 8/10 → 10/10).
* The app shows **planned vs actual hours**, **which topics are done and which are really mastered**, **which questions keep going wrong**, and **whether the plan is still realistic** for 31 July 2027.
* The parent looks at the **Dashboard** and the weekly **Report**, and does not need to watch every study session.

## 2. The evening routine (student, about 2 minutes)

1. Open the app and press **Enter Daily Log** (or *Daily Log* in the left menu; on a phone, tap the ☰ button first).
2. Enter the time for each category as hours and minutes. Use `0` where nothing was done. You can see the day total as you type.

   | Category | What counts |
   |---|---|
   | School | Time at school |
   | Tuition | Time at tuition class |
   | Study Theory | Your own studying of lessons and notes |
   | Write Exam Papers | Your own writing of past-paper questions and papers |

   School and tuition are tracked separately and are **not** counted as independent study.
3. Tick **Rest day** if it was one, optionally rate the day 1 to 5, add a note, and press **Save daily log**.

If you forget a day, open Daily Log, pick that date and enter it later. A day with **no log is "Missing"**. It is *not* counted as zero, so the app never pretends you studied nothing (or something). If you really did nothing, save the day with 0 h 0 min so it counts as a confirmed zero day.

You can change a day's entry any time. Every change is kept in the *Change history*.

## 3. After past-paper questions

**Record Question** → choose Subject, Year, Paper and Question → enter the marks, the minutes it took (a duration only, no start or end time), the result (Correct / Partially Correct / Wrong / Not Completed), whether it was **independent or assisted**, and, if you like, the kind of mistake.

* **Paper or question not in the list?** Open "Paper not listed? Register it here" or "Question not listed?" on the same page. A question can cover several syllabus topics (use the search box). There are no sub-question numbers: one row per main question.
* To **try the same question again**, open its history and press **Try again**. The first attempt is never overwritten; you will see the improvement in marks and in time.
* **Record Full Paper** is for a whole paper's total. If you also record its questions one by one and link them, the total is taken from the questions (never added twice).

**The minutes you type for a question are part of your "Write Exam Papers" hours, not extra hours.** The app never adds them on top. If the detailed minutes for a day add up to more than that day's total, you get a warning (nothing is blocked).

## 4. Recording theory

**Record Theory** → pick the topic (type to search), minutes, and tick **Theory for this topic is now complete** when it is. One entry per topic; studying three topics today means three entries.

Marking theory complete does **not** make a topic "Mastered". See the next section.

## 5. How progress is judged

| Term | Meaning |
|---|---|
| **Theory completed** | You studied the lesson. This is *coverage*. |
| **Questions practised** | You have attempted at least one linked question. |
| **Mastered** | You scored **80% or more, working independently**, on a question linked to that topic. Reading a solution or completing theory is not enough. The app refuses to set *Mastered* without this evidence. |
| **Revision required** | A low score on a linked question flags the topic automatically. |
| **Weak topic** | Latest results on its questions average below the subject's "weak score" (default 50%, changeable in Settings). |

If you later correct an attempt so that it no longer shows mastery, the topic goes back to *Revision required*.

**Scores always show how many attempts are behind them** (n=…), and **first attempts are shown separately from retries**, so a topic you only got right on the third try is not mistaken for one you knew at first sight. Repeat attempts of the same *paper* are not counted as new exam results.

## 6. Review Mistakes

A question appears here when its latest attempt was wrong, scored low, needed help, was slow (if you set a limit), or belongs to a topic you rated as difficult. Questions that keep scoring low are marked **Repeated difficulty**. Tick what you did (theory reviewed, solution understood, attempted again). A question leaves the list only after a good **independent** attempt.

## 7. The Dashboard (parent: start here)

* **Period**: days elapsed and remaining until 31 July 2027, and overall progress against a straight-line plan.
* **Four time cards**: planned, actual, remaining, and the hours needed per week from now. Status words are *On track*, *Behind plan*, *Complete*, *Over plan* or *Not Configured* (never colour alone). If the student falls behind, the card says by how much and nothing is silently adjusted.
* **Recommendations**: plain-English guidance based only on recorded data. With too little data it says **"Not enough data yet."** It never predicts a grade.
* **Subject cards**: priority, syllabus coverage and mastery, questions attempted, marks, first vs retry scores, latest timed paper, revision backlog, trend, weak topics.
* **Consistency**: days logged, **missing logs**, days with independent study, averages.
* **Charts**: planned vs actual hours, hours per week, score trend per subject.

## 8. Weekly and monthly reports (parent and student together)

**Reports** → choose Weekly or Monthly (or a custom date range), optionally one subject. It shows hours vs plan, topics completed, questions and marks, timed papers, strongest and weakest topics, mistakes reviewed, outstanding revision, paper results by year, and recommended priorities. The monthly report adds coverage, score trends, solving-time change, missed targets and a **workload forecast to the end date**. Use **Excel**, **CSV** or **Print** (Print hides the menus).

A good routine: every Sunday, open the weekly report together for ten minutes and agree three priorities for next week.

## 9. Settings (parent)

* **Study period**: start date, end date, optional exam date (add it when officially announced), time zone. Changing dates never deletes anything.
* **Total planned hours** for each of the four categories for the **whole period**. The app works out the daily and weekly amounts itself. Changing a number is allowed; the original and every revision are kept in the history, and a reason can be noted.
* **Subjects**: priority (affects advice and wording only, not hours), weak-score threshold, optional "slow" limit in minutes per mark.
* **Sample data**: load made-up data to try the app, and remove it again (it asks you to confirm).

## 10. Correcting mistakes

Typed a wrong mark? Open the question's history (or **Attempt History**) and press **Correct**. Theory entries have **Correct** too. You can change marks, time, result, date, notes and so on. You cannot change which question it was or its attempt number. Every correction keeps the old and new values in a visible *Change history*. Nothing can be deleted in this version.

## 11. Importing the syllabus, papers and questions

Use the CSV or Excel templates in `database/data/templates/`. Required columns: syllabus → `subject, unit, topic`; papers → `subject, year, paper`; questions → `subject, year, paper, question_no, max_marks` (put several topics in `topics` separated by `|`). Rows already present are skipped, bad rows are listed with their row number, and good rows are still saved.

## 12. Questions people ask

**I forgot to log yesterday. Is the week ruined?** No. Enter it now. Missing days are simply shown as missing.

**Why is "Total actual" bigger than the progress shown beside it?** Total actual adds all four categories; the planned total and the percentage only include categories that have planned hours.

**Why can't I mark a topic as Mastered?** You need an independent attempt of 80% or more on a question linked to the topic. Record one, or link the question to the topic on the Past Papers page.

**Can I use it on a phone?** Yes. The screen adapts to a phone. If the app is on a server with HTTPS, open its address in the phone's browser and sign in. If it only runs on your own computer, the phone cannot reach it.

**Where is my data, and how do I keep it safe?** In the database. On your own computer, run the backup script weekly and copy the `backups` folder to a USB drive (README, section 4). On a server, use Coolify's scheduled database backups ([DEPLOY_COOLIFY.md](DEPLOY_COOLIFY.md), sections 2 and 8).

**Who changed this record?** Each correction in a "Change history" is stored with the account that made it, and when.

**Something looks wrong.** Note the page and what you did and tell whoever looks after the computer; the log is `storage\logs\laravel.log`.

## 13. Words used

*Independent*: done without help. *Assisted*: someone or something helped. *Allocation*: part of a daily total spent on a topic or question (never added again). *Linear plan*: planned hours spread evenly over all days of the period. *Competency level*: the NIE syllabus's numbered learning step.
