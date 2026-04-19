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
 * Event observer for local_quizsectionreset.
 *
 * @package   local_quizsectionreset
 * @copyright 2026 Your Name
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_quizsectionreset;

defined('MOODLE_INTERNAL') || die();

/**
 * Listens to quiz attempt submissions and triggers section resets on fail threshold.
 */
class observer {

    /**
     * Called when a quiz attempt is submitted.
     *
     * @param \mod_quiz\event\attempt_submitted $event
     */
    public static function attempt_submitted(\mod_quiz\event\attempt_submitted $event): void {
        global $DB;

        $attemptid = $event->objectid;
        $userid    = $event->userid;
        $quizid    = $event->other['quizid'];
        $courseid  = $event->courseid;

        // Check if this quiz has a reset rule configured.
        $cfg = $DB->get_record('local_quizsectionreset_cfg', ['quizid' => $quizid]);
        if (!$cfg) {
            return;
        }

        // Load the attempt to check pass/fail.
        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], '*', MUST_EXIST);

        // Determine pass/fail by comparing sumgrades against the quiz's grade to pass.
        $quiz = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);

        $passed = self::attempt_passed($attempt, $quiz);

        // Fetch or create the log record.
        $log = $DB->get_record('local_quizsectionreset_log', ['quizid' => $quizid, 'userid' => $userid]);
        if (!$log) {
            $log = (object)[
                'quizid'       => $quizid,
                'userid'       => $userid,
                'failcount'    => 0,
                'resetcount'   => 0,
                'timemodified' => time(),
            ];
            $log->id = $DB->insert_record('local_quizsectionreset_log', $log);
        }

        if ($passed) {
            // Reset fail counter on pass.
            $log->failcount    = 0;
            $log->timemodified = time();
            $DB->update_record('local_quizsectionreset_log', $log);
            return;
        }

        // Increment fail counter.
        $log->failcount++;
        $log->timemodified = time();
        $DB->update_record('local_quizsectionreset_log', $log);

        if ($log->failcount >= $cfg->maxattempts) {
            // Perform the section reset.
            section_resetter::reset_section($courseid, $userid, $cfg->sectionnum, $quizid);

            // Reset the fail counter after the section reset.
            $log->failcount    = 0;
            $log->resetcount++;
            $log->timemodified = time();
            $DB->update_record('local_quizsectionreset_log', $log);
        }
    }

    /**
     * Determine if a quiz attempt is passing.
     *
     * Compares the attempt's sumgrades (raw score) against the quiz's gradepass
     * (stored in grade_items). Falls back to checking sumgrades >= grade if no
     * gradepass is set.
     *
     * @param \stdClass $attempt  A quiz_attempts record.
     * @param \stdClass $quiz     A quiz record.
     * @return bool
     */
    private static function attempt_passed(\stdClass $attempt, \stdClass $quiz): bool {
        global $DB;

        // quiz_attempt states: 'finished', 'abandoned'. Only finished ones count.
        if ($attempt->state !== 'finished') {
            return false;
        }

        // Get gradepass from grade_items.
        $gradeitem = $DB->get_record('grade_items', [
            'itemtype'     => 'mod',
            'itemmodule'   => 'quiz',
            'iteminstance' => $quiz->id,
            'itemnumber'   => 0,
        ]);

        $maxgrade  = (float)$quiz->grade;
        $sumgrades = (float)$attempt->sumgrades;
        $quizgrade = (float)$quiz->sumgrades; // Total raw points available.

        // Convert raw sumgrades to scaled grade (out of quiz->grade).
        $scaled = ($quizgrade > 0) ? ($sumgrades / $quizgrade) * $maxgrade : 0.0;

        if ($gradeitem && (float)$gradeitem->gradepass > 0) {
            return $scaled >= (float)$gradeitem->gradepass;
        }

        // Fallback: pass if score >= 50% of max grade.
        return $scaled >= ($maxgrade / 2);
    }
}
