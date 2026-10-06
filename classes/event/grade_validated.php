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

namespace gradingform_rubric_ranges\event;

/**
 * Event triggered when a grade given with a ranged rubric is validated.
 *
 * The graded item is identified by other['itemid'] (grading_instances.itemid): there is no generic API
 * to get the graded user from it, so relateduserid is not set.
 *
 * @property-read array $other {
 *      - int itemid: grading item
 *      - int instanceid: grading instance saved by the validator
 *      - int criteria: number of criteria validated
 *      - int teacherid: grader of the original grade
 * }
 *
 * @package    gradingform_rubric_ranges
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_validated extends \core\event\base {

    /**
     * Init method.
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'gradingform_rubric_ranges_h';
    }

    /**
     * Returns localised general event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventgradevalidated', 'gradingform_rubric_ranges');
    }

    /**
     * Returns non-localised event description.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' validated the grade of the grading item with id " .
            "'{$this->other['itemid']}' given by the user with id '{$this->other['teacherid']}' " .
            "in the course module with id '{$this->contextinstanceid}'.";
    }

    /**
     * Custom validation.
     *
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();
        foreach (['itemid', 'instanceid', 'criteria', 'teacherid'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The '{$key}' value must be set in other.");
            }
        }
    }

    /**
     * Mapping of the object for backup/restore.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'gradingform_rubric_ranges_h', 'restore' => \core\event\base::NOT_MAPPED];
    }

    /**
     * Mapping of the other fields for backup/restore.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return [
            'itemid' => \core\event\base::NOT_MAPPED,
            'instanceid' => \core\event\base::NOT_MAPPED,
            'criteria' => \core\event\base::NOT_MAPPED,
            'teacherid' => ['db' => 'user', 'restore' => 'user'],
        ];
    }
}
