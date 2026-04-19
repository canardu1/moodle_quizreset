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
 * Section reset event for local_quizsectionreset.
 *
 * @package   local_quizsectionreset
 * @copyright 2026 Your Name
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_quizsectionreset\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Fired when a course section is automatically reset for a user.
 */
class section_reset extends \core\event\base {

    /**
     * {@inheritdoc}
     */
    protected function init(): void {
        $this->data['crud']        = 'd'; // d = delete (data is cleared).
        $this->data['edulevel']    = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'course_sections';
    }

    /**
     * {@inheritdoc}
     */
    public static function get_name(): string {
        return get_string('pluginname', 'local_quizsectionreset') . ': section reset';
    }

    /**
     * {@inheritdoc}
     */
    public function get_description(): string {
        return "The user with id '{$this->relateduserid}' had section "
            . "'{$this->other['sectionnum']}' reset in course '{$this->courseid}' "
            . "after too many failed attempts on quiz '{$this->other['triggerquizid']}'.";
    }

    /**
     * {@inheritdoc}
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/course/view.php', ['id' => $this->courseid]);
    }
}
