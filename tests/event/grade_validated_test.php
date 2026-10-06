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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../ied_test_helper.php');

/**
 * Tests of the grade_validated event.
 *
 * @package    gradingform_rubric_ranges
 * @category   test
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \gradingform_rubric_ranges\event\grade_validated
 */
final class grade_validated_test extends \advanced_testcase {
    use \gradingform_rubric_ranges\ied_test_helper;

    /**
     * The event carries the validator, the context, the item and the teacher.
     */
    public function test_triggered_on_validation(): void {
        $this->resetAfterTest();
        $this->set_flags(false, true, true);
        [$course, , $context, $controller] = $this->create_assign_with_rubric();
        $ids = $this->criterion_ids($controller);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $validator = $this->create_validator($course);
        $this->grade_as($teacher, $controller, 900, $this->filling($ids, ['C1' => 65, 'C2' => 2], 'T'));

        $sink = $this->redirectEvents();
        $this->grade_as($validator, $controller, 900, $this->filling($ids, ['C1' => 80, 'C2' => 7], 'V', true));
        $events = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof grade_validated));
        $sink->close();

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertEquals($validator->id, $event->userid);
        $this->assertEquals($context->id, $event->contextid);
        $this->assertEquals('gradingform_rubric_ranges_h', $event->objecttable);
        $this->assertSame(900, $event->other['itemid']);
        $this->assertSame(2, $event->other['criteria']);
        $this->assertEquals($teacher->id, $event->other['teacherid']);
        $this->assertStringContainsString("validated the grade of the grading item with id '900'", $event->get_description());
        $this->assertEventContextNotUsed($event);
    }

    /**
     * The other values are mandatory.
     */
    public function test_validate_data(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->expectException(\coding_exception::class);
        grade_validated::create(['context' => \context_course::instance($course->id), 'objectid' => 1, 'other' => ['itemid' => 1]]);
    }
}
