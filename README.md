# local_quizsectionreset — Quiz Section Auto-Reset

[![Made by AtlasTech](https://img.shields.io/badge/Made%20by-AtlasTech-blue)](https://atlastech.it)
[![Latest release](https://img.shields.io/github/v/release/canardu1/moodle_quizreset)](https://github.com/canardu1/moodle_quizreset/releases/latest)
[![Moodle 4.3+](https://img.shields.io/badge/Moodle-4.3%2B-F98012?logo=moodle&logoColor=white)](https://moodle.org)

A Moodle local plugin (Moodle 4.3 or later) that automatically resets a course section for a specific user when they fail a configured quiz N times in a row.

---

## Features

- Tracks consecutive failed quiz attempts **per user** without affecting other users.
- Configurable **N** (max failed attempts) per quiz via the admin UI or per-course teacher UI.
- Configurable **section number** to reset (0-based: 0 = general, 1 = first topic, etc.).
- On threshold: resets the following for every activity in the section:
  - **Quiz** attempts and grades (`quiz_attempts`, `quiz_grades`)
  - **Assignment** submissions (all attempts), uploaded files, grades and feedback
  - **SCORM** tracking data
  - **Lesson** attempts, branches, grades, timers
  - **H5P Activity** attempts and results
  - **Completion** state for every cm in the section — for the affected user only
  - **Grade item** records (so grades recalculate on next attempt)
- Sends a **Moodle notification** (popup + email) to the student after each reset.
- Fires a custom **`\local_quizsectionreset\event\section_reset`** event for logging/audit.
- Full **GDPR / Privacy API** compliance.
- Compatible with Moodle **4.3 and later** (Moodle 5.x not yet verified).

---

## Installation

### From the release ZIP (recommended)

1. Download `local_quizsectionreset-<version>.zip` from the [latest release](https://github.com/canardu1/moodle_quizreset/releases/latest).
2. Go to **Site administration → Plugins → Install plugins**, upload the ZIP and follow the steps.

### Manually

1. Copy the plugin files into `<moodleroot>/local/quizsectionreset/` (the folder **must** be named `quizsectionreset`, not `moodle_quizreset`).
2. Log in as admin and run **Site administration → Notifications** to install the plugin.

The plugin creates two database tables:
   - `local_quizsectionreset_cfg` — one rule per quiz (quiz → section, max attempts).
   - `local_quizsectionreset_log` — per-user fail counts and reset counters.

---

## Configuration

### Site-wide (admin)

Go to **Site administration → Local plugins → Quiz Section Auto-Reset**.

### Per-course (teacher)

Navigate to:
```
/local/quizsectionreset/manage.php?courseid=<COURSE_ID>
```

Teachers with `editingteacher` role (or higher) can manage rules for their own course. In this view only quizzes and rules belonging to that course can be created or deleted.

### Rule fields

| Field | Description |
|---|---|
| **Quiz** | The quiz whose failed attempts are counted. Each quiz may have at most one rule. |
| **Section number** | Zero-based section index in the course to reset when the threshold is hit. |
| **Max failed attempts** | Number of *consecutive* failed attempts before the reset fires. A pass resets the counter to 0. |

---

## How pass/fail is determined

1. The attempt's raw `sumgrades` is scaled to the quiz's `grade` (max grade).
2. If a **grade to pass** is set on the quiz's grade item, that threshold is used.
3. Fallback: pass if the scaled grade ≥ 50 % of the maximum grade.
4. Attempts still waiting for manual grading (e.g. essay questions) are not counted as either pass or fail.

---

## Events fired

| Event | When |
|---|---|
| `\local_quizsectionreset\event\section_reset` | After a successful section reset. |

---

## Capabilities

| Capability | Default roles |
|---|---|
| `local/quizsectionreset:manage` | Manager, Course creator, Editing teacher |

---

## Supported activity types (reset)

| Module | What is reset |
|---|---|
| `quiz` | All attempts + quiz grade record |
| `assign` | All submission attempts (file + online text, including stored files) + grades and feedback |
| `scorm` | All SCO tracking data + attempt records (Moodle 4.3+ tables) |
| `lesson` | Attempts, branches, grades, timers |
| `h5pactivity` | Attempts + results |
| All others | Completion state only |

---

## Changelog

### 1.0.1 — 2026-11-24
- **Fix:** activity completion is now reset only for the user who failed the quiz (previously it was wiped for everyone in the course).
- **Fix:** SCORM reset uses the Moodle 4.3+ tables (`scorm_attempt`, `scorm_scoes_value`).
- **Security:** the per-course management page only accepts quizzes and rules from that course, and checks that the section exists.
- **Fix:** assignments with multiple attempts are handled; uploaded files and feedback are removed too.
- **Fix:** attempts awaiting manual grading are no longer counted as failures.
- **Fix:** the notification shows the correct number of failed attempts.

### 1.0.0 — 2026-04-19
- First release.

---

## License

GNU GPL v3 or later — http://www.gnu.org/copyleft/gpl.html
