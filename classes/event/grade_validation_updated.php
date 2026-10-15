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
 * Event triggered when a validator corrects a validated grade before it is published.
 *
 * @property-read array $other {
 *      - int itemid: grading item
 *      - int instanceid: grading instance saved by the validator
 *      - array changes: per criterion, criterionid, oldlevelid, newlevelid, oldgrade, newgrade and remarkchanged
 * }
 *
 * @package    gradingform_rubric_ranges
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_validation_updated extends \core\event\base {
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
        return get_string('eventgradevalidationupdated', 'gradingform_rubric_ranges');
    }

    /**
     * Returns non-localised event description.
     *
     * @return string
     */
    public function get_description() {
        $changes = [];
        foreach ($this->other['changes'] as $change) {
            $changes[] = "criterion '{$change['criterionid']}': grade '{$change['oldgrade']}' to '{$change['newgrade']}'" .
                ($change['remarkchanged'] ? ', feedback changed' : '');
        }
        return "The user with id '{$this->userid}' corrected the validated grade of the grading item with id " .
            "'{$this->other['itemid']}' in the course module with id '{$this->contextinstanceid}' (" .
            implode('; ', $changes) . ").";
    }

    /**
     * Custom validation.
     *
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();
        foreach (['itemid', 'instanceid', 'changes'] as $key) {
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
            'changes' => \core\event\base::NOT_MAPPED,
        ];
    }
}
