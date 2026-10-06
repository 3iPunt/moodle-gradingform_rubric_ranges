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

require_once(__DIR__ . '/ied_test_helper.php');

/**
 * Tests of the numeric grading in the grading instance and of the weighted final grade.
 *
 * @package    gradingform_rubric_ranges
 * @category   test
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \gradingform_rubric_ranges_instance
 * @covers     \gradingform_rubric_ranges_controller
 */
final class numeric_grading_test extends \advanced_testcase {
    use ied_test_helper;

    /**
     * The server decides the level from the grade and the final grade uses a minimum of 0.
     */
    public function test_submit(): void {
        $this->resetAfterTest();
        $this->set_flags(false, true, false);
        [, , , $controller] = $this->create_assign_with_rubric();
        $ids = $this->criterion_ids($controller);
        $definition = $controller->get_definition(true);
        $c1 = $definition->rubric_criteria[$ids['C1']];
        $c2 = $definition->rubric_criteria[$ids['C2']];

        $instance = $controller->create_instance(get_admin()->id, 777);
        $this->assertFalse($instance->is_empty_form(['criteria' => [$ids['C1'] => ['grade' => '65'], $ids['C2'] => []]]));
        $data = $this->filling($ids, ['C1' => 65, 'C2' => 2], 'feedback');
        // The browser sends a wrong level: the server fixes it.
        $data['criteria'][$ids['C1']]['levelid'] = $this->level_id($c1, 'Exc');
        $grade = $instance->submit_and_get_grade($data, 777);

        $filling = $instance->get_rubric_filling(true)['criteria'];
        $this->assertSame($this->level_id($c1, 'Buen dominio conceptual'), (int) $filling[$ids['C1']]['levelid']);
        $this->assertSame($this->level_id($c2, 'Bad'), (int) $filling[$ids['C2']]['levelid']);
        $this->assertEquals(65, $filling[$ids['C1']]['grade']);
        $this->assertEqualsWithDelta(6700 / 110, $grade, 0.001);
        $this->assertEquals(['minscore' => 0, 'maxscore' => 110], $controller->get_min_max_score());
    }

    /**
     * Invalid numeric grades are rejected by the form validation.
     */
    public function test_validate_grading_element(): void {
        $this->resetAfterTest();
        $this->set_flags(false, true, false);
        [, , , $controller] = $this->create_assign_with_rubric();
        $ids = $this->criterion_ids($controller);
        $instance = $controller->create_instance(get_admin()->id, 778);
        $this->assertTrue($instance->validate_grading_element($this->filling($ids, ['C1' => 65, 'C2' => 2])));
        $this->assertFalse($instance->validate_grading_element($this->filling($ids, ['C1' => 101, 'C2' => 2])));
        $this->assertFalse($instance->validate_grading_element($this->filling($ids, ['C1' => '6.5', 'C2' => 2])));
    }

    /**
     * Weighted final grade with numeric grading, also when the grade is validated.
     */
    public function test_weighted_grade(): void {
        $this->resetAfterTest();
        $this->set_flags(true, true, true);
        [$course, , , $controller] = $this->create_assign_with_rubric(null, ['C1' => 3, 'C2' => 1]);
        $ids = $this->criterion_ids($controller);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $validator = $this->create_validator($course);
        $grade = $this->grade_as($teacher, $controller, 556, $this->filling($ids, ['C1' => 65, 'C2' => 2], 'T'));
        $this->assertEqualsWithDelta(53.75, $grade, 0.001);
        $validated = $this->grade_as($validator, $controller, 556, $this->filling($ids, ['C1' => 80, 'C2' => 10], 'V', true));
        $this->assertEqualsWithDelta(85, $validated, 0.001);
    }
}
