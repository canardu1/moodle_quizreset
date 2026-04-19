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
 * Privacy provider for local_quizsectionreset.
 *
 * @package   local_quizsectionreset
 * @copyright 2026 Your Name
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_quizsectionreset\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider implementation.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * {@inheritdoc}
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_quizsectionreset_log',
            [
                'userid'       => 'privacy:metadata:local_quizsectionreset_log:userid',
                'quizid'       => 'privacy:metadata:local_quizsectionreset_log:quizid',
                'failcount'    => 'privacy:metadata:local_quizsectionreset_log:failcount',
                'resetcount'   => 'privacy:metadata:local_quizsectionreset_log:resetcount',
                'timemodified' => 'privacy:metadata:local_quizsectionreset_log:timemodified',
            ],
            'privacy:metadata:local_quizsectionreset_log'
        );
        return $collection;
    }

    /**
     * {@inheritdoc}
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT DISTINCT ctx.id
                  FROM {local_quizsectionreset_log} l
                  JOIN {quiz} q           ON q.id = l.quizid
                  JOIN {course_modules} cm ON cm.instance = q.id
                  JOIN {modules} m         ON m.id = cm.module AND m.name = 'quiz'
                  JOIN {context} ctx       ON ctx.instanceid = cm.id AND ctx.contextlevel = :modlevel
                 WHERE l.userid = :userid";

        $contextlist->add_from_sql($sql, [
            'modlevel' => CONTEXT_MODULE,
            'userid'   => $userid,
        ]);

        return $contextlist;
    }

    /**
     * {@inheritdoc}
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $sql = "SELECT l.userid
                  FROM {local_quizsectionreset_log} l
                  JOIN {quiz} q            ON q.id = l.quizid
                  JOIN {course_modules} cm  ON cm.instance = q.id AND cm.id = :cmid
                  JOIN {modules} m          ON m.id = cm.module AND m.name = 'quiz'";

        $userlist->add_from_sql('userid', $sql, ['cmid' => $context->instanceid]);
    }

    /**
     * {@inheritdoc}
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        $logs   = $DB->get_records('local_quizsectionreset_log', ['userid' => $userid]);

        foreach ($logs as $log) {
            $context = \context_system::instance();
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_quizsectionreset')],
                $log
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        // We do not store data per context; this is a no-op.
    }

    /**
     * {@inheritdoc}
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $DB->delete_records('local_quizsectionreset_log', ['userid' => $contextlist->get_user()->id]);
    }

    /**
     * {@inheritdoc}
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        list($insql, $inparams) = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $DB->delete_records_select('local_quizsectionreset_log', "userid $insql", $inparams);
    }
}
