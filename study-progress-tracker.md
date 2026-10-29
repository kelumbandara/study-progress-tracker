# AI DEVELOPMENT MASTER PROMPT
## Sri Lankan A/L Study Progress Tracker

### 1. Project Overview

Develop a simple, powerful, responsive web application called **A/L Study Progress Tracker** to help a Sri Lankan G.C.E. Advanced Level student prepare for the examination.

**Student's subjects:**
1. Combined Mathematics
2. Physics
3. Information Technology (IT)

**Current academic position:**
- IT: Good performance; maintain and improve.
- Physics: Moderate ability; needs improvement.
- Combined Mathematics: Currently failing; highest priority for improvement.

**Study arrangement:** School + Tuition + Independent Study.

**Target:** Complete the planned preparation programme by 31 July 2027, before the expected A/L examination in August 2027.

The primary learning strategy is past-paper-based learning. The student attempts questions from the previous 10 years of examination papers, checks the answers, identifies weaknesses, studies the related theory, and attempts questions again to measure improvement.

The application must help the student and parent answer these questions:

- How many hours were planned, and how many hours have actually been completed?
- How much time was spent at school, tuition, studying theory, and writing examination papers?
- Which syllabus topics have been studied and mastered?
- Which past-paper questions have been attempted, and what marks were obtained?
- How efficiently can the student solve examination questions?
- Are examination marks improving over time?
- What remains to be completed before the configured end date?
- Is the current preparation rate sufficient to meet the target?

Build a functional application with a persistent database, authentication, working forms, calculations, reports, and validation. Do not deliver only static screens or a UI mock-up.

---

### 2. Technology Stack

Use the following technology stack:

- Backend: Laravel 11, PHP 8.2 or a compatible supported PHP version.
- Database: MySQL.
- Frontend: Blade templates, Bootstrap 5 and the existing AdminLTE template if available.
- Charts: Chart.js or an equivalent lightweight charting library.
- Authentication: Laravel authentication with role-based authorization.
- Export: Excel/CSV export and printable reports.
- Architecture: MVC, reusable services, form validation, database migrations, seeders, and automated tests.

Before developing, inspect the existing project and its structure. Reuse the existing Laravel template, authentication, layouts, components, and conventions where appropriate. Do not unnecessarily replace working components.

Use a mobile-first responsive layout that works well on desktop, tablet, and mobile browsers.

The student will NOT use the phone while studying. All study-time entries must be possible at the end of the day using a simple daily entry form.

---

### 3. Application Users and Permissions

Implement two roles.

**Student**
- View the dashboard, syllabus, study plan, and reports.
- Record daily study durations.
- Register past-paper attempts and marks.
- Update syllabus completion and topic mastery.
- Review mistakes and retry questions.
- Record daily reflections.

**Parent / Administrator**
- View all student progress.
- Configure the study period and planned hours.
- Manage subjects, syllabus units, topics, examination years, and papers.
- Review progress reports and weak areas.
- Export data.
- Edit or correct records when necessary.

For version one, the application can support one student with one parent/administrator. Design the database so additional students could be supported later without a major redesign.

---

### 4. Settings Page — Core Configuration

Create a dedicated Settings page. All important study targets must be configurable without modifying the source code.

#### 4.1 Study period

Fields:
- Study start date.
- Study end date.
- Target examination date (optional).
- Time zone, defaulting to Asia/Colombo.

Initial configuration:
- Study start date: 28 October 2026.
- Study end date: 31 July 2027.
- Expected examination: August 2027, exact date to be configured when officially announced.

The dates above are defaults, not hard-coded restrictions. The parent must be able to change them.

The application must calculate:
- Total calendar days in the configured period.
- Days elapsed.
- Days remaining.
- Percentage of the period elapsed.
- Remaining workload and required average study hours per remaining day or week.

Handle changes to the study period without deleting historical data.

#### 4.2 Subject settings

Create these initial subjects:

1. Combined Mathematics — priority: High.
2. Physics — priority: Medium.
3. IT — priority: Maintain / Improve.

Allow the parent to configure the priority of each subject.

Priority should influence recommendations and dashboard presentation, but should not automatically determine the number of hours allocated to a subject.

#### 4.3 Total planned hours

The parent must enter the **total planned hours across the entire configured study period** for each of the four categories.

| Category | Planned hours |
|---|---:|
| School | Parent-configurable |
| Tuition | Parent-configurable |
| Study Theory | Parent-configurable |
| Write Exam Papers | Parent-configurable |

Requirements:
- Allow decimal hours, such as 125.5.
- Validate that planned hours are non-negative.
- Allow the parent to update targets.
- Display original targets and revised targets where applicable.
- Calculate actual hours, remaining planned hours, percentage completed, and required future average hours.
- Do not require the parent to enter weekly targets manually.
- Automatically calculate indicative daily and weekly requirements from total planned hours and the configured date range.
- If actual progress falls behind the planned trajectory, display a warning and calculate the recovery workload.
- Do not silently alter the planned hours to make the student appear on track.

School and tuition are tracked separately from independent study, but all four categories must be visible in the overall dashboard.

Important: School and tuition time represents time spent attending those activities. Do not count those hours as independent study hours.

---

### 5. Daily Study Log — End-of-Day Entry

This is one of the most important features.

The student should spend no time entering information during a study session. At the end of each day, the student opens the app and records the actual duration spent in each category.

Create a simple Daily Log page.

Fields:
- Study date.
- School hours.
- Tuition hours.
- Study Theory hours.
- Write Exam Papers hours.
- Optional notes about the day.
- Optional difficulty or motivation rating.

Use duration inputs such as:
- Hours and minutes, or
- A decimal-hour input with a clear explanation.

Recommended interface: four simple duration fields displayed together.

Example:

| Category | Actual time |
|---|---:|
| School | 5 h 30 min |
| Tuition | 2 h 00 min |
| Study Theory | 1 h 15 min |
| Write Exam Papers | 2 h 00 min |

The values above are illustrative only.

Requirements:
- Do not capture exact study start or end times.
- Do not require a running timer.
- Do not require the phone to be present during study.
- Allow the student to enter the log at the end of the day.
- Allow editing of previously entered logs with an audit trail.
- Prevent duplicate daily records from creating double-counted totals.
- Allow a day with zero hours in any category.
- Distinguish a missing daily log from a confirmed zero-hour entry.
- Support rest days and days with no school or tuition.
- Validate duration values.
- Show a daily total and cumulative totals.

The daily log should support a simple reminder or a dashboard indication that today's log has not yet been entered. Notifications can be optional and should not be required for the core application.

#### Important accounting rule

The four daily durations are the authoritative source for total time calculations.

Detailed question-working times and topic-level study durations are subdivisions of these daily durations, not additional hours to be added again.

For example, if the student records two hours under Write Exam Papers and spends 35 minutes on a particular question, those 35 minutes must not be added as another 35 minutes to the total study hours.

Use appropriate validations or warnings when detailed time allocations exceed the daily category total. Allow unallocated time when the student has not tracked every individual question or topic.

---

### 6. Syllabus Management

Create a dedicated Syllabus page.

The syllabus must be organized hierarchically:

Subject → Unit / Lesson → Topic → Subtopic.

Each syllabus item should support:
- Subject.
- Unit or lesson name.
- Topic name.
- Optional subtopic name.
- Display order.
- Official syllabus reference, where available.
- Estimated study hours, optional.
- Completion status.
- Date theory was marked complete.
- Actual theory time allocated.
- Student confidence rating, from 1 to 5.
- Question-practice status.
- Mastery status.

#### 6.1 Syllabus statuses

Use the following statuses:

1. Not Started.
2. In Progress.
3. Theory Completed.
4. Questions Practised.
5. Revision Required.
6. Mastered.

Do not allow a topic to be considered mastered merely because the student marks the theory as completed.

A topic should be considered mastered only when there is evidence of independent question performance or a suitable assessment.

Allow the student to mark theory as completed and record the associated study time.

Provide:
- Subject-wise completion percentages.
- Unit-wise completion percentages.
- Completed versus remaining topics.
- Topics with no practice questions.
- Topics needing revision.
- Topic confidence ratings.

Allow the parent to import the syllabus from CSV/Excel or enter it manually.

Do not invent official Sri Lankan A/L syllabus topics. If syllabus data is not provided, create the import mechanism and a clearly labelled sample dataset. Do not represent sample topics as the official syllabus.

---

### 7. Past-Paper Library

Create a Past Papers module covering the previous 10 examination years.

The examination year range should be configurable. Use the most recent 10 available examination years by default, with 2026–2017 as the initial range where appropriate. Do not assume every year's paper is available.

Each paper record must contain:
- Subject.
- Examination year.
- Paper identifier, such as Paper I or Paper II.
- Examination type, if applicable.
- Maximum paper marks.
- Official examination duration, where known.
- Paper PDF link or file reference, where legally available.
- Marking scheme / answer reference, where available.
- Availability status.

Allow the parent to register papers manually or import paper metadata from CSV/Excel.

The app must not assume that paper structures, total marks, or durations are identical across subjects or years.

#### 7.1 Question register

Each question must be recorded at the main-question level.

Fields:
- Subject.
- Examination year.
- Paper.
- Question number.
- Maximum marks.
- Syllabus topic or topics covered.
- Optional question reference or PDF page number.
- Optional notes.

Do NOT require a sub-question field.

Use a unique question identifier linked to the paper, so the same question can be attempted repeatedly without creating duplicate question definitions.

Allow one question to be linked to multiple syllabus topics using a searchable multi-select dropdown. For example, a Combined Mathematics question may cover more than one lesson.

When the student selects a subject, year, and paper, show the registered question numbers for that paper.

If the desired paper or question is not registered, allow the student or parent to add it without leaving the workflow.

#### 7.2 Past-paper question attempt entry

For every attempt, capture:
- Question reference.
- Attempt date.
- Attempt number.
- Maximum marks.
- Marks earned.
- Worked time in minutes.
- Result status.
- Whether the attempt was independent or assisted.
- Optional mistake category.
- Optional explanation or notes.
- Whether the answer was checked against a marking scheme.

Attempt result options:
- Correct.
- Partially Correct.
- Wrong.
- Not Completed.

Mistake categories:
- Conceptual misunderstanding.
- Formula or method error.
- Calculation error.
- Question interpretation error.
- Time-management problem.
- Incomplete answer.
- Other.

Worked time is a duration only. Do not capture question start time or question end time.

The student can enter question-working times at the end of the day from memory or their paper-based notes. Exact timing is not required.

Keep each attempt as a separate historical record.

Example:
- First attempt: 4/10 marks in 35 minutes.
- Second attempt: 8/10 marks in 25 minutes.
- Third attempt: 10/10 marks in 20 minutes.

The app must preserve all three records and show the improvement.

Do not overwrite the first attempt when recording a retry.

#### 7.3 Paper completion

Provide two ways to practise:
1. Individual questions.
2. A complete examination paper.

For complete-paper practice, record:
- Paper selected.
- Attempt date.
- Total marks earned.
- Total maximum marks.
- Total worked time.
- Independent or assisted status.
- Optional notes.

Where individual question attempts are also recorded for the same paper attempt, calculate the paper total from the question marks when possible. Avoid adding a manually entered paper total and its question-level marks together.

Support papers that contain questions with different structures or optional sections. Do not assume all questions must be attempted.

---

### 8. Theory Study Tracking

The student must be able to record theory study without using a timer.

Provide a simple theory study entry:
- Study date.
- Subject.
- Syllabus unit.
- Topic or subtopic.
- Duration in minutes.
- Completion status.
- Confidence rating.
- Optional notes.

Allow multiple topics to be recorded on one day.

When theory study is entered, the duration should be allocated to the daily Study Theory category.

If the student studies several topics in a single session, allow the duration to be split across topics. The sum of topic allocations must not exceed the corresponding daily Study Theory duration without an explicit warning.

When a student marks theory as complete:
- Record the completion date.
- Update syllabus progress.
- Keep the completion history.
- Do not automatically mark the topic as mastered.

If a topic needs to be revised later, preserve the previous completion record and allow a new revision entry.

---

### 9. Revision and Mistake Management

Create a simple Mistake Review feature.

A question should be eligible for review when:
- The student gets it wrong.
- The student earns a low percentage of marks.
- The student needs assistance.
- The student takes significantly longer than expected.
- The student identifies a topic as difficult.

Allow the student to mark:
- Theory reviewed.
- Solution understood.
- Question attempted again.
- Question mastered independently.

Keep revision history and link every review to the original question.

The app should identify repeated mistakes and questions that continue to cause difficulty.

Use configurable thresholds for weak scores and slow questions. Do not hard-code one universal threshold for all subjects or question types.

A question should not be considered mastered solely because the solution was read. Independent performance on a later attempt should provide the evidence of mastery.

---

### 10. Dashboard

Build a clean, easy-to-understand dashboard.

#### 10.1 Study-period overview

Show:
- Study start date.
- Study end date.
- Days elapsed.
- Days remaining.
- Overall preparation progress.
- Total planned hours.
- Total actual hours.
- Planned hours remaining.
- Actual versus expected progress.

Display both hours and percentages.

#### 10.2 Four-category time dashboard

For each category, display:
- Planned total hours.
- Actual completed hours.
- Remaining planned hours.
- Completion percentage.
- Progress status.
- Required average hours per remaining week.

Categories:
- School.
- Tuition.
- Study Theory.
- Write Exam Papers.

Show independent-study hours separately from School and Tuition hours.

#### 10.3 Subject dashboard

For each subject, display:
- Current priority.
- Syllabus coverage.
- Theory completion.
- Questions attempted.
- Total marks earned and available marks.
- Average score.
- Latest timed-paper score.
- Latest full-paper result, if available.
- Total paper-working time.
- Weak topics.
- Revision backlog.
- Trend compared with previous assessments.

Do not combine unrelated paper scores into a single misleading average without explaining how the result was calculated.

Show the number of completed assessments alongside percentages.

#### 10.4 Quick actions

Provide buttons for:
- Enter Daily Log.
- Record Question Attempt.
- Record Theory Study.
- Update Syllabus.
- Review Mistakes.
- View Reports.

The student should be able to complete the daily entry in approximately two minutes.

---

### 11. Analytics and Progress Calculations

Implement the following calculations accurately.

**A. Category time completion**

Actual hours = sum of valid daily durations for the category.

Completion percentage = actual hours ÷ planned hours × 100.

If planned hours are zero, display Not Configured rather than dividing by zero.

If actual hours exceed the planned hours, show the overrun clearly rather than capping the actual value.

**B. Expected time progress**

Calculate expected cumulative hours using the configured study period and elapsed time.

Use a simple linear trajectory initially, but allow the parent to interpret or adjust the plan. Do not assume that every day has equal availability.

**C. Remaining workload**

Remaining planned hours = maximum of zero and (planned hours − actual hours).

Required future average hours = remaining planned hours divided by the remaining available study days or weeks.

Show a warning if the required rate becomes unrealistic or if the configured end date has passed.

**D. Question performance**

For valid marked attempts:

Question score percentage = marks earned ÷ maximum marks × 100.

Track:
- First-attempt score.
- Latest-attempt score.
- Best score.
- Score improvement.
- Time improvement.
- Independent versus assisted performance.

Display first-attempt performance separately from retry performance.

**E. Paper performance**

Paper score percentage = marks earned ÷ maximum marks × 100.

Track:
- Score by examination year.
- Score by paper.
- Score by subject.
- Time taken compared with official duration where known.
- Trends across comparable papers.

Do not treat repeated attempts of the same paper as independent new examination results.

**F. Syllabus performance**

Calculate:
- Total topics.
- Topics started.
- Topics with theory completed.
- Topics practised.
- Topics mastered.
- Topics requiring revision.
- Topics not yet practised.

Display both coverage and demonstrated mastery.

**G. Study consistency**

Calculate:
- Days with a daily log.
- Days with independent study.
- Planned versus completed study sessions, where sessions are configured.
- Average daily and weekly hours.
- Missing daily logs.

A missing log is not automatically a zero-hour day.

---

### 12. Automatic Recommendations

Create a lightweight rule-based recommendation engine. AI integration is not required in version one.

Examples:
- If Combined Mathematics scores remain low, recommend prioritizing its weak syllabus topics.
- If Physics theory is complete but question scores remain low, recommend more question practice and error review.
- If IT scores are consistently strong, recommend maintaining performance while protecting sufficient time for the other subjects.
- If theory hours are high but question-practice hours are low, recommend more written practice.
- If a question repeatedly receives low marks, add it to the revision queue.
- If the student is behind the time plan, calculate the additional weekly hours required.
- If the planned workload is no longer feasible, flag the issue for parent review rather than silently reducing targets.

Recommendations must be based on recorded data. If there is insufficient information, display “Not enough data yet.”

Do not promise a particular A/L result or predict a final grade from insufficient historical data.

---

### 13. Weekly and Monthly Reports

Create reports that the parent and student can review together.

#### Weekly report
- Planned versus actual hours for all four categories.
- Syllabus topics completed.
- Number of questions attempted.
- Marks obtained.
- Timed-paper results.
- Strongest and weakest topics.
- Mistakes reviewed and corrected.
- Outstanding revision items.
- Recommended priorities for the following week.

#### Monthly report
- Monthly and cumulative study hours.
- Cumulative syllabus coverage.
- Subject-wise score trends.
- Changes in question-solving time.
- First-attempt versus retry performance.
- Missed targets.
- Updated forecast of workload through the configured end date.

Allow date-range filtering, subject filtering, Excel/CSV export, and printable reports.

---

### 14. Database Design

Create a normalized MySQL database using Laravel migrations and appropriate foreign keys.

Suggested core tables:

1. users
2. student_profiles
3. study_settings
4. subjects
5. syllabus_units
6. syllabus_topics
7. topic_status_history
8. study_daily_logs
9. theory_study_entries
10. exam_papers
11. exam_questions
12. question_topic_links
13. question_attempts
14. paper_attempts
15. mistake_reviews
16. study_targets_history
17. audit_logs

Add any necessary supporting tables for role assignments, imports, or paper files.

Requirements:
- Use foreign keys and database indexes.
- Use decimal or integer-minute storage for durations; avoid floating-point rounding errors.
- Use a consistent approach to storing duration and displaying hours/minutes.
- Keep historical attempts immutable where practical, and use audit records for corrections.
- Avoid duplicate papers and question definitions.
- Ensure repeated attempts do not overwrite previous attempts.
- Record who created or edited important records.
- Use transactions for operations that update related records.
- Apply suitable database constraints and validation.

Question-level and topic-level durations are allocations of daily category time. Do not add these allocations to daily durations when calculating total hours.

---

### 15. UI/UX Requirements

Design a simple, modern, low-distraction interface.

Use:
- Responsive Bootstrap/AdminLTE components.
- Clear cards and progress bars.
- Simple charts.
- Searchable dropdowns.
- Tables with filters and pagination.
- Clear form validation.
- Confirmations for destructive actions.
- Helpful empty states when no data has been entered.

Keep the number of clicks low.

Prioritize these student actions:
1. Enter today's four time durations.
2. Record a past-paper question.
3. Mark a theory topic completed.
4. Review a weak question.

Do not add social features, chat, unnecessary notifications, leaderboards, or complex gamification to version one.

Use clear status labels and color indicators, but never rely on color alone to communicate meaning.

---

### 16. Data Import and Initial Setup

Implement:
- CSV/Excel syllabus import.
- Past-paper metadata import.
- Question metadata import.
- Validation and duplicate detection.
- Import error reports.
- Manual registration of missing papers and questions.

Do not automatically populate the database with invented syllabus topics, question numbers, maximum marks, or official examination durations.

Where paper PDFs and marking schemes are not supplied, allow the administrator to register links or upload legally obtained materials.

Keep the application usable even if some past-paper years or questions have not yet been registered.

---

### 17. Security and Data Integrity

Implement:
- Secure authentication.
- Role-based access.
- CSRF protection.
- Server-side validation.
- Protection against SQL injection and cross-site scripting.
- Authorization checks on every protected resource.
- Secure handling of uploaded files.
- Database backup guidance.
- Audit logging for configuration and record corrections.
- Appropriate deletion safeguards.

Only authorized users should be able to change the study period, planned hours, or historical records.

---

### 18. Development Process

Follow these steps in order.

**Phase 1 — Inspect and plan**
- Inspect the existing Laravel project.
- Review its database, authentication, templates, and coding conventions.
- Prepare a short implementation plan.
- Define the database schema and relationships.

**Phase 2 — Foundation**
- Create migrations, models, seeders, authentication roles, and settings.
- Implement configurable study dates and planned hours.

**Phase 3 — Tracking**
- Implement the daily log.
- Implement syllabus management.
- Implement the past-paper library.
- Implement question attempts and theory entries.
- Implement revision tracking.

**Phase 4 — Analytics**
- Implement dashboards, progress calculations, recommendations, and reports.
- Validate all calculations against sample records.

**Phase 5 — Testing**
- Test form validation and database persistence.
- Test repeated question attempts.
- Test date-range calculations.
- Test changing planned hours.
- Test missing logs and zero-hour entries.
- Test prevention of double-counting question and topic durations.
- Test authorization between student and parent accounts.
- Test Excel/CSV imports and exports.

**Phase 6 — Delivery**
- Provide a working application.
- Provide database migrations and seeders.
- Provide installation and configuration instructions.
- Provide sample data clearly marked as sample data.
- Provide a user guide for the student and parent.
- Provide a test report.
- Document any incomplete functionality honestly.

Do not stop after creating the dashboard. Continue until all core workflows work with real database records.

---

### 19. Acceptance Criteria

The application is complete only when all of the following are true:

1. The parent can configure the study start date, end date, and planned total hours for all four categories.
2. The student can enter daily durations without recording start or end times.
3. Daily time entries persist in the database and can be edited safely.
4. The dashboard compares actual hours with total planned hours.
5. The syllabus can be organized by subject, unit, topic, and subtopic.
6. Theory completion can be recorded with the associated duration.
7. Past papers can be registered by year, subject, and paper.
8. Questions can be registered without requiring sub-question numbers.
9. A question can be linked to one or multiple syllabus topics.
10. Each question attempt records marks, worked time, result, and assistance status.
11. Multiple attempts of the same question are preserved independently.
12. Complete-paper results can be tracked without double-counting question marks.
13. Weak topics and repeated mistakes are identifiable.
14. The dashboard shows meaningful progress toward 31 July 2027.
15. Weekly and monthly reports can be generated and exported.
16. Missing records are not silently treated as zero.
17. All progress calculations are tested with known sample values.
18. The application is responsive, secure, and usable on a desktop or mobile browser.
19. All core functions work with persistent data; no critical feature is simulated with hard-coded values.
20. The parent can review the student's progress without having to supervise every study session.

### 20. Final Instruction to the AI Developer

Build this as a maintainable, production-quality Laravel application, using the existing project template wherever possible.

Focus on **simplicity, accurate records, measurable learning improvement, and clear parent reporting**.

The student must be able to study without a phone, enter time durations once at the end of the day, register question results, and see exactly which areas need improvement.

The parent must be able to configure the full preparation period, planned workload, and syllabus, and then use reliable reports to understand whether the student is on track.

Do not add unnecessary features. Do not invent official syllabus or examination data. Do not overwrite historical attempts. Do not double-count study time. Do not mark a topic as mastered without evidence of learning.

Start by inspecting the existing codebase, then implement the complete application in manageable phases, testing each phase before proceeding to the next.