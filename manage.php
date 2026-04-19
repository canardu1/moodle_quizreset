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
 * Admin management page for local_quizsectionreset.
 *
 * Allows site admins to view, add, and delete auto-reset rules per quiz.
 * Teachers with manage capability can access the page scoped to their courses.
 *
 * @package   local_quizsectionreset
 * @copyright 2026 Your Name
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$courseid = optional_param('courseid', 0, PARAM_INT);
$delete   = optional_param('delete',   0, PARAM_INT);
$confirm  = optional_param('confirm',  0, PARAM_BOOL);

// Determine context: course context if courseid is given, else system.
if ($courseid) {
    $course  = get_course($courseid);
    $context = context_course::instance($courseid);
    require_login($course);
    require_capability('local/quizsectionreset:manage', $context);
    $PAGE->set_course($course);
} else {
    require_login();
    require_capability('moodle/site:config', context_system::instance());
    admin_externalpage_setup('local_quizsectionreset_manage');
    $context = context_system::instance();
}

$PAGE->set_url(new moodle_url('/local/quizsectionreset/manage.php', ['courseid' => $courseid]));
$PAGE->set_title(get_string('manage', 'local_quizsectionreset'));
$PAGE->set_heading(get_string('manage', 'local_quizsectionreset'));

// Handle deletion.
if ($delete && $confirm && confirm_sesskey()) {
    $cfg = $DB->get_record('local_quizsectionreset_cfg', ['id' => $delete], '*', MUST_EXIST);
    $DB->delete_records('local_quizsectionreset_cfg', ['id' => $delete]);
    redirect(new moodle_url('/local/quizsectionreset/manage.php', ['courseid' => $courseid]),
        get_string('ruledeleted', 'local_quizsectionreset'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($delete && !$confirm) {
    // Show confirmation page.
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('confirmdelete', 'local_quizsectionreset'),
        new moodle_url('/local/quizsectionreset/manage.php', [
            'courseid' => $courseid,
            'delete'   => $delete,
            'confirm'  => 1,
            'sesskey'  => sesskey(),
        ]),
        new moodle_url('/local/quizsectionreset/manage.php', ['courseid' => $courseid])
    );
    echo $OUTPUT->footer();
    exit;
}

// Process add/edit form submission.
$formaction = optional_param('formaction', '', PARAM_ALPHA);
if ($formaction === 'save' && confirm_sesskey()) {
    $quizid      = required_param('quizid',      PARAM_INT);
    $sectionnum  = required_param('sectionnum',  PARAM_INT);
    $maxattempts = required_param('maxattempts', PARAM_INT);

    // Validate.
    if (!$DB->record_exists('quiz', ['id' => $quizid])) {
        redirect(new moodle_url('/local/quizsectionreset/manage.php', ['courseid' => $courseid]),
            get_string('invalidquiz', 'local_quizsectionreset'), null, \core\output\notification::NOTIFY_ERROR);
    }
    if ($sectionnum < 0) {
        redirect(new moodle_url('/local/quizsectionreset/manage.php', ['courseid' => $courseid]),
            get_string('invalidsection', 'local_quizsectionreset'), null, \core\output\notification::NOTIFY_ERROR);
    }
    if ($maxattempts < 1) {
        redirect(new moodle_url('/local/quizsectionreset/manage.php', ['courseid' => $courseid]),
            get_string('invalidmaxattempts', 'local_quizsectionreset'), null, \core\output\notification::NOTIFY_ERROR);
    }

    $existing = $DB->get_record('local_quizsectionreset_cfg', ['quizid' => $quizid]);
    $now      = time();

    if ($existing) {
        $existing->sectionnum  = $sectionnum;
        $existing->maxattempts = $maxattempts;
        $existing->timemodified = $now;
        $DB->update_record('local_quizsectionreset_cfg', $existing);
    } else {
        $record = (object)[
            'quizid'       => $quizid,
            'sectionnum'   => $sectionnum,
            'maxattempts'  => $maxattempts,
            'timecreated'  => $now,
            'timemodified' => $now,
        ];
        $DB->insert_record('local_quizsectionreset_cfg', $record);
    }

    redirect(new moodle_url('/local/quizsectionreset/manage.php', ['courseid' => $courseid]),
        get_string('rulessaved', 'local_quizsectionreset'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// Build list of rules.
if ($courseid) {
    // Only show rules for quizzes in this course.
    $sql = "SELECT r.*, q.name AS quizname, cs.name AS sectionname, cs.section AS sectionnumber
              FROM {local_quizsectionreset_cfg} r
              JOIN {quiz} q ON q.id = r.quizid
              JOIN {course_sections} cs ON cs.course = q.course AND cs.section = r.sectionnum
             WHERE q.course = :courseid
          ORDER BY q.name";
    $rules = $DB->get_records_sql($sql, ['courseid' => $courseid]);
} else {
    $sql = "SELECT r.*, q.name AS quizname, c.shortname AS courseshortname,
                   cs.name AS sectionname, cs.section AS sectionnumber
              FROM {local_quizsectionreset_cfg} r
              JOIN {quiz} q ON q.id = r.quizid
              JOIN {course} c ON c.id = q.course
              JOIN {course_sections} cs ON cs.course = q.course AND cs.section = r.sectionnum
          ORDER BY c.shortname, q.name";
    $rules = $DB->get_records_sql($sql);
}

// Build quiz selector for the add form (scoped to course if set).
if ($courseid) {
    $quizlist = $DB->get_records_menu('quiz', ['course' => $courseid], 'name', 'id,name');
} else {
    // All quizzes across all courses (admin view).
    $sql = "SELECT q.id, CONCAT(c.shortname, ' / ', q.name) AS label
              FROM {quiz} q
              JOIN {course} c ON c.id = q.course
          ORDER BY c.shortname, q.name";
    $quizlist = $DB->get_records_sql_menu($sql);
}

// Output.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manage', 'local_quizsectionreset'));

// Existing rules table.
if (empty($rules)) {
    echo $OUTPUT->notification(get_string('norules', 'local_quizsectionreset'), 'info');
} else {
    $table = new html_table();
    if ($courseid) {
        $table->head = [
            get_string('selectquiz', 'local_quizsectionreset'),
            get_string('sectionnum', 'local_quizsectionreset'),
            get_string('maxattempts', 'local_quizsectionreset'),
            '',
        ];
    } else {
        $table->head = [
            get_string('coursefield', 'local_quizsectionreset'),
            get_string('selectquiz', 'local_quizsectionreset'),
            get_string('sectionnum', 'local_quizsectionreset'),
            get_string('maxattempts', 'local_quizsectionreset'),
            '',
        ];
    }

    foreach ($rules as $rule) {
        $deleteurl = new moodle_url('/local/quizsectionreset/manage.php', [
            'courseid' => $courseid,
            'delete'   => $rule->id,
        ]);
        $deletelink = html_writer::link($deleteurl, get_string('deletecfg', 'local_quizsectionreset'),
            ['class' => 'btn btn-sm btn-danger']);

        $sectionlabel = !empty($rule->sectionname) ? $rule->sectionname : get_string('section') . ' ' . $rule->sectionnumber;

        if ($courseid) {
            $table->data[] = [
                format_string($rule->quizname),
                $sectionlabel,
                $rule->maxattempts,
                $deletelink,
            ];
        } else {
            $table->data[] = [
                $rule->courseshortname,
                format_string($rule->quizname),
                $sectionlabel,
                $rule->maxattempts,
                $deletelink,
            ];
        }
    }

    echo html_writer::table($table);
}

// Add new rule form.
echo $OUTPUT->heading(get_string('addnewrule', 'local_quizsectionreset'), 4);

$formurl = new moodle_url('/local/quizsectionreset/manage.php', ['courseid' => $courseid]);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $formurl->out(false), 'class' => 'mform']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'formaction', 'value' => 'save']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid',   'value' => $courseid]);

echo html_writer::start_div('form-group row');
echo html_writer::tag('label', get_string('selectquiz', 'local_quizsectionreset'),
    ['for' => 'quizid', 'class' => 'col-sm-3 col-form-label']);
echo html_writer::start_div('col-sm-9');
echo html_writer::select($quizlist, 'quizid', '', ['' => 'choosedots'], ['id' => 'quizid', 'class' => 'form-control custom-select']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('form-group row');
echo html_writer::tag('label', get_string('sectionnum', 'local_quizsectionreset'),
    ['for' => 'sectionnum', 'class' => 'col-sm-3 col-form-label']);
echo html_writer::start_div('col-sm-9');
echo html_writer::empty_tag('input', [
    'type'  => 'number',
    'name'  => 'sectionnum',
    'id'    => 'sectionnum',
    'class' => 'form-control',
    'min'   => '0',
    'value' => '1',
]);
echo html_writer::tag('small', get_string('sectionnum_help', 'local_quizsectionreset'), ['class' => 'form-text text-muted']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('form-group row');
echo html_writer::tag('label', get_string('maxattempts', 'local_quizsectionreset'),
    ['for' => 'maxattempts', 'class' => 'col-sm-3 col-form-label']);
echo html_writer::start_div('col-sm-9');
echo html_writer::empty_tag('input', [
    'type'  => 'number',
    'name'  => 'maxattempts',
    'id'    => 'maxattempts',
    'class' => 'form-control',
    'min'   => '1',
    'value' => '3',
]);
echo html_writer::tag('small', get_string('maxattempts_help', 'local_quizsectionreset'), ['class' => 'form-text text-muted']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('form-group row');
echo html_writer::start_div('col-sm-9 offset-sm-3');
echo html_writer::empty_tag('input', [
    'type'  => 'submit',
    'value' => get_string('savesettings', 'local_quizsectionreset'),
    'class' => 'btn btn-primary',
]);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_tag('form');

echo $OUTPUT->footer();
