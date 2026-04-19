# local_quizsectionreset — Quiz Section Auto-Reset

A Moodle local plugin (Moodle 4.0 – 5.1) that automatically resets a course section for a specific user when they fail a configured quiz N times in a row.

---

## Features

- Tracks consecutive failed quiz attempts **per user** without affecting other users.
- Configurable **N** (max failed attempts) per quiz via the admin UI or per-course teacher UI.
- Configurable **section number** to reset (0-based: 0 = general, 1 = first topic, etc.).
- On threshold: resets the following for every activity in the section:
  - **Quiz** attempts and grades (`quiz_attempts`, `quiz_grades`)
  - **Assignment** submissions and grades
  - **SCORM** tracking data
  - **Lesson** attempts, branches, grades, timers
  - **H5P Activity** attempts and results
  - **Completion** state for every cm in the section
  - **Grade item** records (so grades recalculate on next attempt)
- Sends a **Moodle notification** (popup + email) to the student after each reset.
- Fires a custom **`\local_quizsectionreset\event\section_reset`** event for logging/audit.
- Full **GDPR / Privacy API** compliance.
- Compatible with Moodle **4.0 – 5.1**.

---

## Installation

1. Copy the `quizsectionreset` folder into `<moodleroot>/local/`.
2. Log in as admin and run **Site administration → Notifications** to install the plugin.
3. The plugin creates two database tables:
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

Teachers with `editingteacher` role (or higher) can manage rules for their own course.

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
| `assign` | Submissions (file + online text) + grade |
| `scorm` | All SCO tracking data + attempt records |
| `lesson` | Attempts, branches, grades, timers |
| `h5pactivity` | Attempts + results |
| All others | Completion state only |

---

## License

GNU GPL v3 or later — http://www.gnu.org/copyleft/gpl.html
