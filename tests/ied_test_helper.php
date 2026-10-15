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

namespace gradingform_rubric_ranges;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/grade/grading/lib.php');
require_once($CFG->dirroot . '/grade/grading/form/rubric_ranges/lib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Shared setup for the IED extension tests (the upstream generator is not modified).
 *
 * @package    gradingform_rubric_ranges
 * @category   test
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait ied_test_helper {
    /** @var array Default rubric: C1 is ranged, C2 is not. */
    protected static $defaultcriteria = [
        ['name' => 'C1', 'isranged' => 1, 'levels' => ['Insuf' => 50, 'Suf' => 60, 'Buen dominio conceptual' => 70, 'Exc' => 100]],
        ['name' => 'C2', 'isranged' => 0, 'levels' => ['Bad' => 5, 'Good' => 10]],
    ];

    /**
     * Sets the IED feature flags.
     *
     * @param bool $weighting
     * @param bool $numeric
     * @param bool $validation
     */
    protected function set_flags(bool $weighting, bool $numeric, bool $validation): void {
        set_config('enableweighting', (int) $weighting, 'gradingform_rubric_ranges');
        set_config('enablenumericgrading', (int) $numeric, 'gradingform_rubric_ranges');
        set_config('enablevalidation', (int) $validation, 'gradingform_rubric_ranges');
    }

    /**
     * Creates a course with an assignment (max grade 100) graded with a ranged rubric.
     *
     * @param array|null $criteria criteria for the generator, the default rubric when null
     * @param array $weights criterion name => weight
     * @return array [course, cm, context, controller, assign]
     */
    protected function create_assign_with_rubric(?array $criteria = null, array $weights = []): array {
        // The upstream generator must run as a user.
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $assignrecord = $generator->create_module('assign', ['course' => $course->id, 'grade' => 100]);
        $cm = get_coursemodule_from_instance('assign', $assignrecord->id);
        $context = \context_module::instance($cm->id);
        $controller = $generator->get_plugin_generator('gradingform_rubric_ranges')
            ->create_instance($context, 'mod_assign', 'submissions', 'Rubric', 'Description', $criteria ?? self::$defaultcriteria);
        get_grading_manager($context, 'mod_assign', 'submissions')->set_active_method('rubric_ranges');
        if ($weights) {
            $data = $controller->get_definition_for_editing();
            foreach ($data->rubricranges['criteria'] as $id => $criterion) {
                if (isset($weights[$criterion['description']])) {
                    $data->rubricranges['criteria'][$id]['weight'] = $weights[$criterion['description']];
                }
            }
            $controller->update_definition($data);
        }
        // Done by mod_assign when it loads the grading form.
        $controller->set_grade_range(make_grades_menu(100), true);
        $assign = new \assign($context, $cm, $course);
        return [$course, $cm, $context, $controller, $assign];
    }

    /**
     * Returns the criterion ids of a rubric keyed by description.
     *
     * @param \gradingform_rubric_ranges_controller $controller
     * @return int[]
     */
    protected function criterion_ids(\gradingform_rubric_ranges_controller $controller): array {
        $ids = [];
        foreach ($controller->get_definition(true)->rubric_criteria as $id => $criterion) {
            $ids[$criterion['description']] = $id;
        }
        return $ids;
    }

    /**
     * Returns the id of the level of a criterion with the given definition.
     *
     * @param array $criterion
     * @param string $definition
     * @return int
     */
    protected function level_id(array $criterion, string $definition): int {
        foreach ($criterion['levels'] as $id => $level) {
            if ($level['definition'] === $definition) {
                return (int) $id;
            }
        }
        throw new \coding_exception("Level '$definition' not found");
    }

    /**
     * Creates a user with the validator role (validate capability) in the course.
     *
     * @param \stdClass $course
     * @return \stdClass
     */
    protected function create_validator(\stdClass $course): \stdClass {
        $validator = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $roleid = $this->get_validator_role();
        role_assign($roleid, $validator->id, \context_course::instance($course->id)->id);
        return $validator;
    }

    /**
     * Returns the id of a role granting the validate capability, creating it once.
     *
     * @return int
     */
    protected function get_validator_role(): int {
        global $DB;
        if ($roleid = $DB->get_field('role', 'id', ['shortname' => 'iedvalidator'])) {
            return (int) $roleid;
        }
        $roleid = create_role('Validator', 'iedvalidator', '');
        assign_capability('gradingform/rubric_ranges:validate', CAP_ALLOW, $roleid, \context_system::instance()->id);
        return (int) $roleid;
    }

    /**
     * Builds the submitted value of the grading element.
     *
     * @param array $ids criterion ids keyed by description
     * @param array $grades criterion description => grade
     * @param string $remark remark for every criterion
     * @param bool $validate whether the submission is a validation
     * @return array
     */
    protected function filling(array $ids, array $grades, string $remark = '', bool $validate = false): array {
        $data = ['criteria' => []];
        foreach ($grades as $description => $grade) {
            $data['criteria'][$ids[$description]] = ['grade' => (string) $grade, 'remark' => $remark];
        }
        if ($validate) {
            $data['validate'] = 1;
        }
        return $data;
    }

    /**
     * Grades an item as the given user, as mod_assign does.
     *
     * @param \stdClass $user
     * @param \gradingform_rubric_ranges_controller $controller
     * @param int $itemid
     * @param array $data submitted value of the grading element
     * @return float|int
     */
    protected function grade_as(\stdClass $user, \gradingform_rubric_ranges_controller $controller, int $itemid, array $data) {
        $this->setUser($user);
        return $controller->get_or_create_instance(0, $user->id, $itemid)->submit_and_get_grade($data, $itemid);
    }
}
