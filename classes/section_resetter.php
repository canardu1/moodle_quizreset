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
 * @copyright 2026 Carlo Anardu
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

            // Delete grade for user in this cm (forces recalculation on next attempt).
            self::reset_grade_for_user($cm, $userid);

            // Reset completion state for this cm, for this user only.
            // Note: completion_info::delete_all_state() must NOT be used here,
            // because it wipes completion for every user enrolled in the course.
            if ($completioninfo->is_enabled($cm)) {
                self::reset_completion_for_user($cm, $userid);
                // Re-evaluate completion for the user now that their data is gone.
                $completioninfo->update_state($cm, COMPLETION_UNKNOWN, $userid);
            }
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
     * Delete the activity completion state of a single user for a single course module.
     *
     * @param \stdClass $cm
     * @param int       $userid
     */
    private static function reset_completion_for_user(\stdClass $cm, int $userid): void {
        global $DB;

        $DB->delete_records('course_modules_completion', ['coursemoduleid' => $cm->id, 'userid' => $userid]);

        // "Viewed" state is stored separately since Moodle 4.3.
        if ($DB->get_manager()->table_exists('course_modules_viewed')) {
            $DB->delete_records('course_modules_viewed', ['coursemoduleid' => $cm->id, 'userid' => $userid]);
        }

        // Drop the user's cached completion data for this course.
        \cache::make('core', 'completion')->delete($userid . '_' . $cm->course);
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

        $cm      = get_coursemodule_from_instance('assign', $assignid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $fs      = get_file_storage();

        // An assignment can hold several submissions per user (one per attempt when
        // "additional attempts" are allowed), so handle all of them.
        $submissions = $DB->get_records('assign_submission',
            ['assignment' => $assignid, 'userid' => $userid, 'groupid' => 0]);
        foreach ($submissions as $submission) {
            // Remove plugin submission data and the files stored on disk.
            $fs->delete_area_files($context->id, 'assignsubmission_file', 'submission_files', $submission->id);
            $fs->delete_area_files($context->id, 'assignsubmission_onlinetext', 'submissions_onlinetext', $submission->id);
            $DB->delete_records('assignsubmission_file', ['submission' => $submission->id]);
            $DB->delete_records('assignsubmission_onlinetext', ['submission' => $submission->id]);
            $DB->delete_records('assign_submission', ['id' => $submission->id]);
        }

        // Remove grade records together with their feedback.
        $grades = $DB->get_records('assign_grades', ['assignment' => $assignid, 'userid' => $userid]);
        foreach ($grades as $grade) {
            $fs->delete_area_files($context->id, 'assignfeedback_file', 'feedback_files', $grade->id);
            $DB->delete_records('assignfeedback_comments', ['grade' => $grade->id]);
            $DB->delete_records('assignfeedback_file', ['grade' => $grade->id]);
        }
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

        // Since Moodle 4.3 SCORM tracking lives in scorm_attempt + scorm_scoes_value
        // (the old scorm_scoes_track table no longer exists).
        $attemptids = $DB->get_fieldset_select('scorm_attempt', 'id',
            'scormid = :scormid AND userid = :userid', ['scormid' => $scormid, 'userid' => $userid]);

        if ($attemptids) {
            [$insql, $params] = $DB->get_in_or_equal($attemptids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('scorm_scoes_value', "attemptid $insql", $params);
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
            // The observer has already counted the current attempt before calling us.
            'failcount'   => $log ? (int)$log->failcount : 1,
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
