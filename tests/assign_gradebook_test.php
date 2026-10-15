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
require_once(__DIR__ . '/ied_test_helper.php');
require_once($CFG->libdir . '/gradelib.php');

use gradingform_rubric_ranges\local\validation_manager;

/**
 * End to end tests through the mod_assign grading API down to the gradebook.
 *
 * @package    gradingform_rubric_ranges
 * @category   test
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \gradingform_rubric_ranges_instance::submit_and_get_grade
 * @covers     \gradingform_rubric_ranges_instance::get_grade
 */
final class assign_gradebook_test extends \advanced_testcase {
    use ied_test_helper;

    /**
     * Saves a grade as mod_assign does from the grading form.
     *
     * @param \assign $assign
     * @param \stdClass $grader
     * @param \stdClass $student
     * @param array $advancedgrading submitted value of the grading element
     */
    private function save_grade(\assign $assign, \stdClass $grader, \stdClass $student, array $advancedgrading): void {
        $this->setUser($grader);
        $assign->save_grade($student->id, (object) [
            'advancedgrading' => $advancedgrading,
            'attemptnumber' => -1,
            'sendstudentnotifications' => false,
        ]);
    }

    /**
     * Grade of the student in the gradebook.
     *
     * @param \stdClass $course
     * @param \assign $assign
     * @param \stdClass $student
     * @return float|null
     */
    private function gradebook_grade(\stdClass $course, \assign $assign, \stdClass $student): ?float {
        $grades = grade_get_grades($course->id, 'mod', 'assign', $assign->get_instance()->id, $student->id);
        $grade = $grades->items[0]->grades[$student->id]->grade ?? null;
        return $grade === null ? null : (float) $grade;
    }

    /**
     * Weighted numeric grade from the teacher, then the validated grade, reach the gradebook.
     */
    public function test_weighted_validated_grade_reaches_gradebook(): void {
        $this->resetAfterTest();
        $this->set_flags(true, true, true);
        [$course, , , $controller, $assign] = $this->create_assign_with_rubric(null, ['C1' => 3, 'C2' => 1]);
        $ids = $this->criterion_ids($controller);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $validator = $this->create_validator($course);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->save_grade($assign, $teacher, $student, $this->filling($ids, ['C1' => 65, 'C2' => 2], 'Teacher'));
        $this->assertEqualsWithDelta(53.75, $this->gradebook_grade($course, $assign, $student), 0.001);

        $this->save_grade($assign, $validator, $student, $this->filling($ids, ['C1' => 80, 'C2' => 10], 'Validator', true));
        $this->assertEqualsWithDelta(85, $this->gradebook_grade($course, $assign, $student), 0.001);
        $this->assertTrue(validation_manager::is_validated((int) $assign->get_user_grade($student->id, false)->id));

        // A later teacher regrade is ignored and the gradebook keeps the validated grade.
        $this->save_grade($assign, $teacher, $student, $this->filling($ids, ['C1' => 10, 'C2' => 1], 'Again'));
        $this->assertEqualsWithDelta(85, $this->gradebook_grade($course, $assign, $student), 0.001);
    }

    /**
     * With every feature disabled the gradebook gets the upstream grade.
     */
    public function test_upstream_grade_reaches_gradebook(): void {
        $this->resetAfterTest();
        $this->set_flags(false, false, false);
        [$course, , , $controller, $assign] = $this->create_assign_with_rubric();
        $ids = $this->criterion_ids($controller);
        $definition = $controller->get_definition(true);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->save_grade($assign, $teacher, $student, ['criteria' => [
            $ids['C1'] => ['levelid' => $this->level_id($definition->rubric_criteria[$ids['C1']], 'Buen dominio conceptual'),
                'grade' => 65, 'remark' => ''],
            $ids['C2'] => ['levelid' => $this->level_id($definition->rubric_criteria[$ids['C2']], 'Bad'), 'remark' => ''],
        ]]);
        // Upstream with lockzeropoints: (65 + 5) / 110 * 100.
        $this->assertEqualsWithDelta(70 / 110 * 100, $this->gradebook_grade($course, $assign, $student), 0.001);
    }
}
