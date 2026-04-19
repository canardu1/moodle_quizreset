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
 * Language strings for local_quizsectionreset.
 *
 * @package   local_quizsectionreset
 * @copyright 2026 Your Name
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname']              = 'Quiz Section Auto-Reset';
$string['plugindesc']              = 'Automatically resets a course section when a user fails a quiz a configurable number of times.';

// Settings page strings.
$string['quizsettings']            = 'Quiz auto-reset settings';
$string['quizsettings_desc']       = 'Configure which quiz triggers a section reset and how many failed attempts are allowed.';
$string['selectquiz']              = 'Quiz';
$string['selectquiz_help']         = 'The quiz whose failed attempts will be counted.';
$string['sectionnum']              = 'Section number to reset';
$string['sectionnum_help']         = 'Zero-based section index in the course. Section 0 is the general section; Section 1 is the first topic, etc.';
$string['maxattempts']             = 'Maximum failed attempts';
$string['maxattempts_help']        = 'Number of consecutive failed attempts before the section is automatically reset.';
$string['savesettings']            = 'Save settings';
$string['settingssaved']           = 'Settings saved successfully.';
$string['deletecfg']               = 'Delete';
$string['confirmdelete']           = 'Are you sure you want to delete this rule?';
$string['addnewrule']              = 'Add new rule';
$string['norules']                 = 'No auto-reset rules configured yet.';
$string['manage']                  = 'Manage Quiz Auto-Reset Rules';
$string['coursefield']             = 'Course';
$string['rulessaved']              = 'Rule saved.';
$string['ruledeleted']             = 'Rule deleted.';
$string['invalidquiz']             = 'Invalid quiz selected.';
$string['invalidsection']          = 'Invalid section number.';
$string['invalidmaxattempts']      = 'Maximum attempts must be a positive integer.';

// Notification sent to student.
$string['sectionresetnotification']         = 'Section reset notification';
$string['sectionresetnotification_subject'] = 'Your course section has been reset';
$string['sectionresetnotification_body']    = 'Dear {$a->firstname},

You have failed the quiz "{$a->quizname}" {$a->failcount} times. The section "{$a->sectionname}" has been automatically reset so you can try again.

Please review the learning materials in that section before attempting the quiz again.

Best regards,
{$a->sitename}';

// Privacy.
$string['privacy:metadata:local_quizsectionreset_log']              = 'Stores per-user quiz fail attempt counts used to trigger section resets.';
$string['privacy:metadata:local_quizsectionreset_log:userid']       = 'The ID of the user.';
$string['privacy:metadata:local_quizsectionreset_log:quizid']       = 'The ID of the quiz.';
$string['privacy:metadata:local_quizsectionreset_log:failcount']    = 'Number of consecutive failed attempts.';
$string['privacy:metadata:local_quizsectionreset_log:resetcount']   = 'Total resets performed for this user.';
$string['privacy:metadata:local_quizsectionreset_log:timemodified'] = 'Last modified timestamp.';
