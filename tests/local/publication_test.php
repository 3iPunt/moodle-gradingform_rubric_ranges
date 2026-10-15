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

global $CFG;
require_once(__DIR__ . '/../ied_test_helper.php');
require_once($CFG->libdir . '/gradelib.php');

use gradingform_rubric_ranges\event\grade_publication_blocked;
use gradingform_rubric_ranges\event\grade_validation_updated;

/**
 * Tests of the validation with the marking workflow of mod_assign: corrections until publication and the
 * publication guard.
 *
 * @package    gradingform_rubric_ranges
 * @category   test
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \gradingform_rubric_ranges\local\publication
 * @covers     \gradingform_rubric_ranges\local\publication_guard
 * @covers     \gradingform_rubric_ranges\local\validation_manager
 */
final class publication_test extends \advanced_testcase {
    use \gradingform_rubric_ranges\ied_test_helper;

    /** @var \stdClass */
    private $course;
    /** @var \gradingform_rubric_ranges_controller */
    private $controller;
    /** @var \assign */
    private $assign;
    /** @var int[] */
    private $ids;
    /** @var \stdClass */
    private $teacher;
    /** @var \stdClass */
    private $validator;
    /** @var \stdClass */
    private $student;

    /**
     * Assignment with numeric grading, validation and (by default) the marking workflow.
     *
     * @param bool $workflow
     */
    private function setup_course(bool $workflow = true): void {
        $this->resetAfterTest();
        $this->set_flags(false, true, true);
        [$this->course, , , $this->controller, $this->assign] =
            $this->create_assign_with_rubric(null, [], ['markingworkflow' => (int) $workflow]);
        $this->ids = $this->criterion_ids($this->controller);
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->validator = $this->create_validator($this->course);
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * Saves the grading form as mod_assign does.
     *
     * @param \stdClass $grader
     * @param array $grades criterion description => grade
     * @param string $remark
     * @param bool $validate
     * @param string|null $state marking workflow state chosen in the form
     */
    private function save(\stdClass $grader, array $grades, string $remark, bool $validate, ?string $state): void {
        $this->setUser($grader);
        $data = (object) [
            'advancedgrading' => $this->filling($this->ids, $grades, $remark, $validate),
            'attemptnumber' => -1,
            'sendstudentnotifications' => false,
        ];
        if ($state !== null) {
            $data->workflowstate = $state;
        }
        $this->assign->save_grade($this->student->id, $data);
    }

    /**
     * Grade of the student in the gradebook (null when there is none).
     *
     * @return float|null
     */
    private function gradebook(): ?float {
        $grades = grade_get_grades($this->course->id, 'mod', 'assign', $this->assign->get_instance()->id, $this->student->id);
        $grade = $grades->items[0]->grades[$this->student->id]->grade ?? null;
        return $grade === null ? null : (float) $grade;
    }

    /**
     * State of the current item for a user.
     *
     * @param \stdClass $user
     * @return string
     */
    private function state_for(\stdClass $user): string {
        $itemid = (int) $this->assign->get_user_grade($this->student->id, false)->id;
        $this->setUser($user);
        $instance = $this->controller->get_or_create_instance(0, $user->id, $itemid);
        return validation_manager::get_state($instance, (int) $user->id);
    }

    /**
     * Teacher grades, validator validates and corrects twice, then publishes; afterwards nobody can change it.
     */
    public function test_validate_correct_and_publish(): void {
        $this->setup_course();
        $itemid = fn() => (int) $this->assign->get_user_grade($this->student->id, false)->id;

        $this->save($this->teacher, ['C1' => 65, 'C2' => 2], 'Teacher', false, ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW);
        $this->assertNull($this->gradebook(), 'Nothing reaches the gradebook before publication');

        $this->save($this->validator, ['C1' => 80, 'C2' => 10], 'Validated', true, ASSIGN_MARKING_WORKFLOW_STATE_READYFORRELEASE);
        $this->assertNull($this->gradebook());
        $this->assertSame(validation_manager::STATE_VALIDATED_EDITABLE, $this->state_for($this->validator));
        $this->assertSame(validation_manager::STATE_VALIDATED, $this->state_for($this->teacher));

        $sink = $this->redirectEvents();
        $this->save($this->validator, ['C1' => 90, 'C2' => 10], 'Typo fixed', false, ASSIGN_MARKING_WORKFLOW_STATE_READYFORRELEASE);
        $this->save($this->validator, ['C1' => 85, 'C2' => 10], 'Typo fixed', false, ASSIGN_MARKING_WORKFLOW_STATE_READYFORRELEASE);
        $updates = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof grade_validation_updated));
        $sink->close();
        $this->assertCount(2, $updates);
        $this->assertSame(80, $updates[0]->other['changes'][0]['oldgrade']);
        $this->assertSame(90, $updates[0]->other['changes'][0]['newgrade']);
        $this->assertTrue($updates[0]->other['changes'][0]['remarkchanged']);
        $this->assertSame(85, $updates[1]->other['changes'][0]['newgrade']);

        $history = validation_manager::get_history($itemid())[$this->ids['C1']];
        $this->assertSame(65, $history->get('teachergrade'), 'The original teacher grade is kept');
        $this->assertSame('Teacher', $history->get('teacherremark'));
        $this->assertSame(85, $history->get('validatedgrade'), 'The latest validated grade is kept');
        $this->assertSame('Typo fixed', $history->get('validatedremark'));
        $this->assertNull($this->gradebook());

        // Publish: the validated grade reaches the gradebook. Publishing without changes keeps the validation time.
        $timevalidated = validation_manager::get_history($itemid())[$this->ids['C1']]->get('timevalidated');
        $this->waitForSecond();
        $this->save($this->validator, ['C1' => 85, 'C2' => 10], 'Typo fixed', false, ASSIGN_MARKING_WORKFLOW_STATE_RELEASED);
        $this->assertEqualsWithDelta(9500 / 110, $this->gradebook(), 0.001);
        $this->assertSame($timevalidated, validation_manager::get_history($itemid())[$this->ids['C1']]->get('timevalidated'));

        // Once published nobody can change it.
        $this->assertSame(validation_manager::STATE_VALIDATED, $this->state_for($this->validator));
        $this->save($this->validator, ['C1' => 10, 'C2' => 1], 'Late change', false, ASSIGN_MARKING_WORKFLOW_STATE_RELEASED);
        $this->save($this->teacher, ['C1' => 10, 'C2' => 1], 'Late change', false, ASSIGN_MARKING_WORKFLOW_STATE_RELEASED);
        $this->assertEqualsWithDelta(9500 / 110, $this->gradebook(), 0.001);
        $this->assertSame(85, validation_manager::get_history($itemid())[$this->ids['C1']]->get('validatedgrade'));
    }

    /**
     * Validating and publishing in the same save is allowed.
     */
    public function test_validate_and_publish_at_once(): void {
        $this->setup_course();
        $this->save($this->teacher, ['C1' => 65, 'C2' => 2], 'Teacher', false, ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW);
        $this->save($this->validator, ['C1' => 80, 'C2' => 10], 'Validated', true, ASSIGN_MARKING_WORKFLOW_STATE_RELEASED);
        $this->assertEqualsWithDelta(9000 / 110, $this->gradebook(), 0.001);
        $flags = $this->assign->get_user_flags($this->student->id, false);
        $this->assertSame(ASSIGN_MARKING_WORKFLOW_STATE_RELEASED, $flags->workflowstate);
    }

    /**
     * Releasing from the grading form a grade not validated is reverted.
     */
    public function test_publication_blocked_from_form(): void {
        $this->setup_course();
        // No event sink here: it would stop the events from reaching the observer.
        $this->save($this->teacher, ['C1' => 65, 'C2' => 2], 'Teacher', false, ASSIGN_MARKING_WORKFLOW_STATE_RELEASED);
        $flags = $this->assign->get_user_flags($this->student->id, false);
        $this->assertSame(ASSIGN_MARKING_WORKFLOW_STATE_READYFORRELEASE, $flags->workflowstate);
        $this->assertNull($this->gradebook(), 'The grade is removed from the gradebook');
    }

    /**
     * The guard triggers grade_publication_blocked (the observer is called directly, as event sinks do not
     * dispatch to observers).
     */
    public function test_publication_blocked_event(): void {
        $this->setup_course();
        $this->save($this->teacher, ['C1' => 65, 'C2' => 2], 'Teacher', false, ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW);
        $itemid = (int) $this->assign->get_user_grade($this->student->id, false)->id;
        $event = \mod_assign\event\workflow_state_updated::create_from_user(
            $this->assign,
            $this->student,
            ASSIGN_MARKING_WORKFLOW_STATE_RELEASED
        );
        $sink = $this->redirectEvents();
        publication_guard::workflow_state_updated($event);
        $blocked = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof grade_publication_blocked));
        $sink->close();
        $this->assertCount(1, $blocked);
        $this->assertEquals($this->student->id, $blocked[0]->relateduserid);
        $this->assertSame($itemid, $blocked[0]->other['itemid']);
        $this->assertStringContainsString(
            "tried to release the grade of the user with id '{$this->student->id}'",
            $blocked[0]->get_description()
        );
    }

    /**
     * Releasing in bulk (flags + gradebook update + event, as the bulk action does) a grade not validated is reverted.
     */
    public function test_publication_blocked_in_bulk(): void {
        $this->setup_course();
        $this->save($this->teacher, ['C1' => 65, 'C2' => 2], 'Teacher', false, ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW);
        $this->setUser($this->teacher);
        $flags = $this->assign->get_user_flags($this->student->id, true);
        $flags->workflowstate = ASSIGN_MARKING_WORKFLOW_STATE_RELEASED;
        $this->assign->update_user_flags($flags);
        $this->assign->update_grade($this->assign->get_user_grade($this->student->id, true));
        \mod_assign\event\workflow_state_updated::create_from_user(
            $this->assign,
            $this->student,
            ASSIGN_MARKING_WORKFLOW_STATE_RELEASED
        )->trigger();
        $flags = $this->assign->get_user_flags($this->student->id, false);
        $this->assertSame(ASSIGN_MARKING_WORKFLOW_STATE_READYFORRELEASE, $flags->workflowstate);
        $this->assertNull($this->gradebook());
    }

    /**
     * Without the marking workflow there is no publication: the validated grade is locked as in v1.0.0.
     */
    public function test_without_workflow(): void {
        $this->setup_course(false);
        $this->save($this->teacher, ['C1' => 65, 'C2' => 2], 'Teacher', false, null);
        $this->save($this->validator, ['C1' => 80, 'C2' => 10], 'Validated', true, null);
        $this->assertEqualsWithDelta(9000 / 110, $this->gradebook(), 0.001);
        $this->assertSame(validation_manager::STATE_VALIDATED, $this->state_for($this->validator));
        $instance = $this->controller->get_or_create_instance(
            0,
            $this->validator->id,
            (int) $this->assign->get_user_grade($this->student->id, false)->id
        );
        $this->assertSame(publication::NO_WORKFLOW, publication::status($instance));
    }
}
