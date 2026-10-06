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
 * Support for restore API
 *
 * @package    gradingform_rubric_ranges
 * @copyright  2022 Heena Agheda <heenaagheda@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restores the rubric specific data from grading.xml file
 *
 * @package    gradingform_rubric_ranges
 * @copyright  2022 Heena Agheda <heenaagheda@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_gradingform_rubric_ranges_plugin extends restore_gradingform_plugin {

    /** @var array IED extension: area names by restored area id. */
    protected $restoredareanames = [];

    /**
     * Declares the rubric XML paths attached to the form definition element
     *
     * @return restore_path_element[]
     */
    protected function define_definition_plugin_structure() {

        $paths = array();

        $paths[] = new restore_path_element('gradingform_rubric_ranges_criterion',
            $this->get_pathfor('/rubric_ranges_criteria/rubric_ranges_criterion'));

        $paths[] = new restore_path_element('gradingform_rubric_ranges_level',
            $this->get_pathfor('/rubric_ranges_criteria/rubric_ranges_criterion/rubric_ranges_levels/rubric_ranges_level'));

        // IED extension: criteria weights.
        $paths[] = new restore_path_element('gradingform_rubric_ranges_weight',
            $this->get_pathfor('/rubric_ranges_criteria/rubric_ranges_criterion/rubric_ranges_weight'));

        return $paths;
    }

    /**
     * Declares the rubric XML paths attached to the form instance element
     *
     * @return restore_path_element[]
     */
    protected function define_instance_plugin_structure() {

        $paths = array();

        $paths[] = new restore_path_element('gradinform_rubric_ranges_filling',
            $this->get_pathfor('/rubric_ranges_fillings/rubric_ranges_filling'));

        // IED extension: validation history.
        $paths[] = new restore_path_element('gradingform_rubric_ranges_validation',
            $this->get_pathfor('/rubric_ranges_validations/rubric_ranges_validation'));

        return $paths;
    }

    /**
     * Processes criterion element data
     *
     * Sets the mapping 'gradingform_rubric_ranges_criterion' to be used later by
     *
     * @param stdClass|array $data
     */
    public function process_gradingform_rubric_ranges_criterion($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->definitionid = $this->get_new_parentid('grading_definition');

        $newid = $DB->insert_record('gradingform_rubric_ranges_c', $data);
        $this->set_mapping('gradingform_rubric_ranges_criterion', $oldid, $newid);
    }

    /**
     * Processes level element data
     *
     * Sets the mapping 'gradingform_rubric_ranges_level' to be used later by.
     *
     * @param stdClass|array $data
     */
    public function process_gradingform_rubric_ranges_level($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->criterionid = $this->get_new_parentid('gradingform_rubric_ranges_criterion');

        $newid = $DB->insert_record('gradingform_rubric_ranges_l', $data);
        $this->set_mapping('gradingform_rubric_ranges_level', $oldid, $newid);
    }

    /**
     * Processes filling element data
     *
     * @param stdClass|array $data
     */
    public function process_gradinform_rubric_ranges_filling($data) {
        global $DB;

        $data = (object)$data;
        $data->instanceid = $this->get_new_parentid('grading_instance');
        $data->criterionid = $this->get_mappingid('gradingform_rubric_ranges_criterion', $data->criterionid);
        $data->levelid = $this->get_mappingid('gradingform_rubric_ranges_level', $data->levelid);

        if (!empty($data->criterionid)) {
            $DB->insert_record('gradingform_rubric_ranges_f', $data);
        }

    }

    /**
     * IED extension: processes the weight of a criterion.
     *
     * @param stdClass|array $data
     */
    public function process_gradingform_rubric_ranges_weight($data) {
        $data = (object) $data;
        $criterionid = $this->get_new_parentid('gradingform_rubric_ranges_criterion');
        if ($criterionid) {
            \gradingform_rubric_ranges\local\weights::save($criterionid, $data->weight);
        }
    }

    /**
     * IED extension: processes one row of the validation history.
     *
     * Rows without criterion or graded item in the restored course are skipped. Users not included in the
     * backup are left empty, so the grade stays validated (locked) and keeps its original grade and date.
     *
     * @param stdClass|array $data
     */
    public function process_gradingform_rubric_ranges_validation($data) {
        $data = (object) $data;
        $instanceid = $this->get_new_parentid('grading_instance');
        $criterionid = $this->get_mappingid('gradingform_rubric_ranges_criterion', $data->criterionid);
        $itemid = $this->get_mappingid(self::itemid_mapping($this->get_restored_areaname()), $data->itemid);
        if (!$instanceid || !$criterionid || !$itemid) {
            return;
        }
        $history = \gradingform_rubric_ranges\persistent\validation_history::class;
        if ($history::record_exists_select('itemid = :itemid AND criterionid = :criterionid',
                ['itemid' => $itemid, 'criterionid' => $criterionid])) {
            // Already restored (e.g. restoring twice into the same course).
            return;
        }
        $record = new $history(0, (object) [
            'itemid' => $itemid,
            'instanceid' => $instanceid,
            'criterionid' => $criterionid,
            'teacherlevelid' => $this->map_or_null('gradingform_rubric_ranges_level', $data->teacherlevelid),
            'teachergrade' => $data->teachergrade,
            'teacherremark' => $data->teacherremark,
            'teacherremarkformat' => $data->teacherremarkformat,
            'teacherid' => $this->map_or_null('user', $data->teacherid),
            'validatedlevelid' => $this->map_or_null('gradingform_rubric_ranges_level', $data->validatedlevelid),
            'validatedgrade' => $data->validatedgrade,
            'validatedremark' => $data->validatedremark,
            'validatorid' => $this->map_or_null('user', $data->validatorid) ?? 0,
            'timevalidated' => $data->timevalidated,
        ]);
        $record->create();
    }

    /**
     * IED extension: name of the grading area being restored, from the grading manager API.
     *
     * @return string
     */
    protected function get_restored_areaname() {
        global $CFG;
        require_once($CFG->dirroot . '/grade/grading/lib.php');

        $areaid = (int) $this->get_new_parentid('grading_area');
        if (!isset($this->restoredareanames[$areaid])) {
            $this->restoredareanames[$areaid] = (string) get_grading_manager($areaid)->get_area();
        }
        return $this->restoredareanames[$areaid];
    }

    /**
     * IED extension: maps an id, returning null when the value is empty or cannot be mapped.
     *
     * @param string $itemname
     * @param mixed $oldid
     * @return int|null
     */
    protected function map_or_null($itemname, $oldid) {
        if (empty($oldid)) {
            return null;
        }
        $newid = $this->get_mappingid($itemname, $oldid);
        return $newid ? (int) $newid : null;
    }
}
