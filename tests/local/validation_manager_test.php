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

namespace gradingform_rubric_ranges\local;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../ied_test_helper.php');

use gradingform_rubric_ranges\persistent\validation_history;

/**
 * Tests of the academic validation.
 *
 * @package    gradingform_rubric_ranges
 * @category   test
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \gradingform_rubric_ranges\local\validation_manager
 * @covers     \gradingform_rubric_ranges_instance
 */
final class validation_manager_test extends \advanced_testcase {
    use \gradingform_rubric_ranges\ied_test_helper;

    /** @var \stdClass */
    private $course;
    /** @var \gradingform_rubric_ranges_controller */
    private $controller;
    /** @var int[] criterion ids by description */
    private $ids;
    /** @var \stdClass */
    private $teacher;
    /** @var \stdClass */
    private $validator;

    /**
     * Course with numeric grading and validation, a teacher and a validator.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->set_flags(false, true, true);
        [$this->course, , , $this->controller] = $this->create_assign_with_rubric();
        $this->ids = $this->criterion_ids($this->controller);
        $this->teacher = $this->getDataGenerator()->create_and_enrol(
            $this->course,
            'editingteacher',
            ['firstname' => 'Tea', 'lastname' => 'Cher']
        );
        $this->validator = $this->create_validator($this->course);
    }

    /**
     * Returns a new instance of the item for the user (as the grading form does).
     *
     * @param \stdClass $user
     * @param int $itemid
     * @return \gradingform_rubric_ranges_instance
     */
    private function instance_for(\stdClass $user, int $itemid): \gradingform_rubric_ranges_instance {
        $this->setUser($user);
        return $this->controller->get_or_create_instance(0, $user->id, $itemid);
    }

    /**
     * Current filling of the item.
     *
     * @param int $itemid
     * @return array
     */
    private function current_filling(int $itemid): array {
        return $this->instance_for(get_admin(), $itemid)->get_current_instance()->get_rubric_filling(true)['criteria'];
    }

    /**
     * States for teacher, validator and site admin; only the validator sees a pending grade.
     */
    public function test_states(): void {
        $this->grade_as($this->teacher, $this->controller, 900, $this->filling($this->ids, ['C1' => 65, 'C2' => 2], 'T'));
        $instance = $this->instance_for($this->validator, 900);
        $this->assertSame(validation_manager::STATE_PENDING, validation_manager::get_state($instance, (int) $this->validator->id));
        $this->assertSame(validation_manager::STATE_NORMAL, validation_manager::get_state($instance, (int) $this->teacher->id));
        // Site admins are not validators by default.
        $this->assertSame(validation_manager::STATE_NORMAL, validation_manager::get_state($instance, (int) get_admin()->id));
        $this->assertTrue($instance->validate_grading_element(['criteria' => []]));

        $this->set_flags(false, true, false);
        $this->assertSame(validation_manager::STATE_OFF, validation_manager::get_state($instance, (int) $this->validator->id));
    }

    /**
     * Saving without validating keeps the teacher grade and discards the validator copy.
     */
    public function test_save_without_validate(): void {
        global $DB;
        $grade = $this->grade_as($this->teacher, $this->controller, 900, $this->filling($this->ids, ['C1' => 65, 'C2' => 2], 'T'));
        $copy = $this->instance_for($this->validator, 900);
        $result = $copy->submit_and_get_grade($this->filling($this->ids, ['C1' => 99, 'C2' => 9], 'X'), 900);
        $this->assertEqualsWithDelta($grade, $result, 0.001);
        $this->assertFalse(validation_manager::is_validated(900));
        $this->assertFalse($DB->record_exists('grading_instances', ['id' => $copy->get_id()]));
        $this->assertEquals(
            $this->teacher->id,
            $DB->get_field_select('grading_instances', 'raterid', 'itemid = 900 AND status = 1')
        );
        $this->assertEquals(65, $this->current_filling(900)[$this->ids['C1']]['grade']);
    }

    /**
     * A validation replaces the teacher grade and feedback and keeps the original in the history.
     */
    public function test_validate(): void {
        $this->grade_as($this->teacher, $this->controller, 900, $this->filling($this->ids, ['C1' => 65, 'C2' => 2], 'T1'));
        $this->setUser($this->validator);
        $sink = $this->redirectEvents();
        $grade = $this->grade_as(
            $this->validator,
            $this->controller,
            900,
            $this->filling($this->ids, ['C1' => 80, 'C2' => 7], 'V1', true)
        );
        $events = array_filter($sink->get_events(), fn($e) => $e instanceof \gradingform_rubric_ranges\event\grade_validated);
        $sink->close();
        $this->assertEqualsWithDelta(8700 / 110, $grade, 0.001);
        $this->assertCount(1, $events);

        $history = validation_manager::get_history(900);
        $this->assertCount(2, $history);
        $definition = $this->controller->get_definition();
        $c1 = $definition->rubric_criteria[$this->ids['C1']];
        $row = $history[$this->ids['C1']];
        $this->assertSame(65, $row->get('teachergrade'));
        $this->assertSame($this->level_id($c1, 'Buen dominio conceptual'), $row->get('teacherlevelid'));
        $this->assertSame('T1', $row->get('teacherremark'));
        $this->assertEquals($this->teacher->id, $row->get('teacherid'));
        $this->assertSame(80, $row->get('validatedgrade'));
        $this->assertSame($this->level_id($c1, 'Exc'), $row->get('validatedlevelid'));
        $this->assertSame('V1', $row->get('validatedremark'));
        $this->assertEquals($this->validator->id, $row->get('validatorid'));
        $this->assertGreaterThan(0, $row->get('timevalidated'));

        $filling = $this->current_filling(900);
        $this->assertEquals(80, $filling[$this->ids['C1']]['grade']);
        $this->assertSame('V1', $filling[$this->ids['C1']]['remark']);
        $this->assertSame(
            validation_manager::STATE_VALIDATED,
            validation_manager::get_state($this->instance_for($this->teacher, 900), (int) $this->teacher->id)
        );
    }

    /**
     * Once validated, nobody can change the grade, not even with an empty form.
     */
    public function test_validated_is_locked(): void {
        $this->grade_as($this->teacher, $this->controller, 900, $this->filling($this->ids, ['C1' => 65, 'C2' => 2], 'T'));
        $validated = $this->grade_as(
            $this->validator,
            $this->controller,
            900,
            $this->filling($this->ids, ['C1' => 80, 'C2' => 7], 'V', true)
        );
        $again = $this->grade_as(
            $this->validator,
            $this->controller,
            900,
            $this->filling($this->ids, ['C1' => 10, 'C2' => 1], 'Z', true)
        );
        $this->assertEqualsWithDelta($validated, $again, 0.001);
        $this->assertCount(2, validation_manager::get_history(900));
        $regrade = $this->filling($this->ids, ['C1' => 10, 'C2' => 1], 'Z');
        $teacher = $this->grade_as($this->teacher, $this->controller, 900, $regrade);
        $this->assertEqualsWithDelta($validated, $teacher, 0.001);
        $empty = $this->grade_as($this->teacher, $this->controller, 900, ['criteria' => []]);
        $this->assertEqualsWithDelta($validated, $empty, 0.001);
        $this->assertEquals(80, $this->current_filling(900)[$this->ids['C1']]['grade']);
    }

    /**
     * A validator cannot give the first grade.
     */
    public function test_not_graded(): void {
        global $DB;
        $instance = $this->instance_for($this->validator, 901);
        $this->assertSame(
            validation_manager::STATE_NOT_GRADED,
            validation_manager::get_state($instance, (int) $this->validator->id)
        );
        $this->assertEquals(-1, $instance->submit_and_get_grade($this->filling($this->ids, ['C1' => 50, 'C2' => 5]), 901));
        $this->assertFalse($DB->record_exists_select('grading_instances', 'itemid = 901 AND status = 1'));
    }

    /**
     * A grader with the validate capability cannot validate their own grade.
     */
    public function test_no_self_validation(): void {
        $this->grade_as($this->teacher, $this->controller, 902, $this->filling($this->ids, ['C1' => 30, 'C2' => 3], 'S'));
        role_assign($this->get_validator_role(), $this->teacher->id, \context_course::instance($this->course->id)->id);
        $instance = $this->instance_for($this->teacher, 902);
        $this->assertSame(validation_manager::STATE_SELF, validation_manager::get_state($instance, (int) $this->teacher->id));
    }

    /**
     * Deleting the definition or a criterion deletes the history.
     */
    public function test_delete(): void {
        $this->grade_as($this->teacher, $this->controller, 900, $this->filling($this->ids, ['C1' => 65, 'C2' => 2], 'T'));
        $this->grade_as($this->validator, $this->controller, 900, $this->filling($this->ids, ['C1' => 80, 'C2' => 7], 'V', true));
        $this->setAdminUser();
        $data = $this->controller->get_definition_for_editing();
        unset($data->rubricranges['criteria'][$this->ids['C2']]);
        $this->controller->update_definition($data);
        $this->assertSame(0, validation_history::count_records(['criterionid' => $this->ids['C2']]));
        $this->assertSame(1, validation_history::count_records(['criterionid' => $this->ids['C1']]));
        $this->controller->delete_definition();
        $this->assertSame(0, validation_history::count_records());
    }
}
