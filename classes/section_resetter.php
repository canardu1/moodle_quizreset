<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Section reset logic for local_quizsectionreset.
 *
 * @package   local_quizsectionreset
 * @copyright 2026 Your Name
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_quizsectionreset;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/lib/completionlib.php');
require_once($CFG->dirroot . '/grade/lib.php');

/**
 * Handles resetting all activities within a course section for a single user.
 */
class section_resetter {

    /**
     * Reset all activities in the given section for the given user.
     *
     * This includes:
     *  - Deleting quiz attempts
     *  - Deleting assignment submissions
     *  - Resetting activity completion records
     *  - Deleting user grade overrides so grades recalculate from scratch
     *
     * @param int $courseid   The course ID.
     * @param int $userid     The user whose data should be reset.
     * @param int $sectionnum The 0-based section number to reset.
     * @param int $triggerquizid The quiz that triggered the reset (used for notification).
     */
    public static function reset_section(int $courseid, int $userid, int $sectionnum, int $triggerquizid): void {
        global $DB, $CFG;

        // Get the course_sections record.
        $section = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $sectionnum]);
        if (!$section) {
            debugging("local_quizsectionreset: section $sectionnum not found in course $courseid", DEBUG_DEVELOPER);
            return;
        }

        // Parse the section's sequence of course module IDs.
        $cmids = array_filter(array_map('intval', explode(',', $section->sequence)));
        if (empty($cmids)) {
            return;
        }

        $course = get_course($courseid);
        $completioninfo = new \completion_info($course);

        foreach ($cmids as $cmid) {
            $cm = get_coursemodule_from_id('', $cmid, $courseid);
            if (!$cm) {
                continue;
            }

            // Reset activity-specific data.
            self::reset_module_for_user($cm, $userid);

            // Reset completion state for this cm.
            if ($completioninfo->is_enabled($cm)) {
                $completioninfo->delete_all_state($cm);
                // Re-evaluate completion for the user.
                $completioninfo->update_state($cm, COMPLETION_UNKNOWN, $userid);
            }

            // Delete grade for user in this cm (forces recalculation on next attempt).
            self::reset_grade_for_user($cm, $userid);
        }

        // Send a notification to the user.
        self::notify_user($userid, $course, $section, $triggerquizid);

        // Trigger a custom event for logging/audit purposes.
        $event = \local_quizsectionreset\event\section_reset::create([
            'context'  => \context_course::instance($courseid),
            'objectid' => $section->id,
            'relateduserid' => $userid,
            'other'    => ['sectionnum' => $sectionnum, 'triggerquizid' => $triggerquizid],
        ]);
        $event->trigger();
    }

    /**
     * Reset module-specific user data for a single course module.
     *
     * @param \stdClass $cm     Course module record (with ->modname populated).
     * @param int       $userid
     */
    private static function reset_module_for_user(\stdClass $cm, int $userid): void {
        global $DB;

        switch ($cm->modname) {
            case 'quiz':
                self::reset_quiz($cm->instance, $userid);
                break;

            case 'assign':
                self::reset_assignment($cm->instance, $userid);
                break;

            case 'scorm':
                self::reset_scorm($cm->instance, $userid);
                break;

            case 'lesson':
                self::reset_lesson($cm->instance, $userid);
                break;

            case 'h5pactivity':
                self::reset_h5p($cm->instance, $userid);
                break;

            default:
                // For other module types, completion reset above is sufficient.
                break;
        }
    }

    /**
     * Delete all quiz attempts for a user in a specific quiz instance.
     *
     * @param int $quizid
     * @param int $userid
     */
    private static function reset_quiz(int $quizid, int $userid): void {
        global $DB;

        $quiz     = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);
        $attempts = $DB->get_records('quiz_attempts', ['quiz' => $quizid, 'userid' => $userid]);

        foreach ($attempts as $attempt) {
            // Use the quiz API to delete the attempt cleanly (removes questions_usage etc.).
            quiz_delete_attempt($attempt, $quiz);
        }

        // Remove the quiz grade record so it recalculates on first new attempt.
        $DB->delete_records('quiz_grades', ['quiz' => $quizid, 'userid' => $userid]);
    }

    /**
     * Delete all assignment submissions and grades for a user.
     *
     * @param int $assignid
     * @param int $userid
     */
    private static function reset_assignment(int $assignid, int $userid): void {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        // Get and delete the submission.
        $submission = $DB->get_record('assign_submission', ['assignment' => $assignid, 'userid' => $userid, 'groupid' => 0]);
        if ($submission) {
            // Remove plugin submission data (files, online text, etc.).
            $DB->delete_records('assignsubmission_file', ['submission' => $submission->id]);
            $DB->delete_records('assignsubmission_onlinetext', ['submission' => $submission->id]);
            $DB->delete_records('assign_submission', ['id' => $submission->id]);
        }

        // Remove grade record.
        $DB->delete_records('assign_grades', ['assignment' => $assignid, 'userid' => $userid]);
    }

    /**
     * Reset SCORM tracks for a user.
     *
     * @param int $scormid
     * @param int $userid
     */
    private static function reset_scorm(int $scormid, int $userid): void {
        global $DB;

        $scos = $DB->get_records('scorm_scoes', ['scorm' => $scormid]);

        foreach ($scos as $sco) {
            $DB->delete_records('scorm_scoes_track', ['userid' => $userid, 'scoid' => $sco->id]);
        }

        $DB->delete_records('scorm_attempt', ['scormid' => $scormid, 'userid' => $userid]);
    }

    /**
     * Reset lesson attempts/grades for a user.
     *
     * @param int $lessonid
     * @param int $userid
     */
    private static function reset_lesson(int $lessonid, int $userid): void {
        global $DB;

        $DB->delete_records('lesson_attempts',   ['lessonid' => $lessonid, 'userid' => $userid]);
        $DB->delete_records('lesson_branch',     ['lessonid' => $lessonid, 'userid' => $userid]);
        $DB->delete_records('lesson_grades',     ['lessonid' => $lessonid, 'userid' => $userid]);
        $DB->delete_records('lesson_timer',      ['lessonid' => $lessonid, 'userid' => $userid]);
    }

    /**
     * Reset H5P activity attempts for a user.
     *
     * @param int $h5pactivityid
     * @param int $userid
     */
    private static function reset_h5p(int $h5pactivityid, int $userid): void {
        global $DB;

        $attempts = $DB->get_records('h5pactivity_attempts', ['h5pactivityid' => $h5pactivityid, 'userid' => $userid]);
        foreach ($attempts as $attempt) {
            $DB->delete_records('h5pactivity_attempts_results', ['attemptid' => $attempt->id]);
        }
        $DB->delete_records('h5pactivity_attempts', ['h5pactivityid' => $h5pactivityid, 'userid' => $userid]);
    }

    /**
     * Delete the user's grade for a course module so it recalculates on next attempt.
     *
     * @param \stdClass $cm
     * @param int       $userid
     * @param \stdClass $course
     */
    private static function reset_grade_for_user(\stdClass $cm, int $userid): void {
        global $DB;

        $gradeitem = $DB->get_record('grade_items', [
            'courseid'     => $cm->course,
            'itemtype'     => 'mod',
            'itemmodule'   => $cm->modname,
            'iteminstance' => $cm->instance,
            'itemnumber'   => 0,
        ]);

        if (!$gradeitem) {
            return;
        }

        $DB->delete_records('grade_grades', ['itemid' => $gradeitem->id, 'userid' => $userid]);

        // Trigger grade recalculation.
        grade_regrade_final_grades($cm->course);
    }

    /**
     * Send a Moodle message notification to the user informing them of the reset.
     *
     * @param int       $userid
     * @param \stdClass $course
     * @param \stdClass $section
     * @param int       $triggerquizid
     */
    private static function notify_user(int $userid, \stdClass $course, \stdClass $section, int $triggerquizid): void {
        global $DB, $CFG;

        $quiz = $DB->get_record('quiz', ['id' => $triggerquizid]);
        $user = \core_user::get_user($userid);

        if (!$user || !$quiz) {
            return;
        }

        $sectionname = !empty($section->name)
            ? $section->name
            : get_string('section') . ' ' . $section->section;

        $log = $DB->get_record('local_quizsectionreset_log', ['quizid' => $triggerquizid, 'userid' => $userid]);

        $a = (object)[
            'firstname'   => $user->firstname,
            'quizname'    => format_string($quiz->name),
            'failcount'   => $log ? ($log->failcount + 1) : 1,
            'sectionname' => $sectionname,
            'sitename'    => format_string($CFG->sitename ?? ''),
        ];

        $message = new \core\message\message();
        $message->component         = 'local_quizsectionreset';
        $message->name              = 'sectionresetnotification';
        $message->userfrom          = \core_user::get_noreply_user();
        $message->userto            = $user;
        $message->subject           = get_string('sectionresetnotification_subject', 'local_quizsectionreset');
        $message->fullmessage       = get_string('sectionresetnotification_body', 'local_quizsectionreset', $a);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml   = '';
        $message->smallmessage      = get_string('sectionresetnotification_subject', 'local_quizsectionreset');
        $message->notification      = 1;
        $message->contexturl        = (string)(new \moodle_url('/course/view.php', ['id' => $course->id]));
        $message->contexturlname    = format_string($course->fullname);

        message_send($message);
    }
}
