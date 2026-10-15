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

namespace gradingform_rubric_ranges\privacy;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../ied_test_helper.php');

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use gradingform_rubric_ranges\local\validation_manager;

/**
 * Privacy tests of the IED data (numeric grade, validation history and weights).
 *
 * @package    gradingform_rubric_ranges
 * @category   test
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \gradingform_rubric_ranges\privacy\provider
 */
final class provider_ied_test extends provider_testcase {
    use \gradingform_rubric_ranges\ied_test_helper;

    /** @var \context_module */
    private $context;
    /** @var int[] */
    private $ids;
    /** @var \stdClass */
    private $teacher;
    /** @var \stdClass */
    private $validator;
    /** @var int item of the first student */
    private $itemid;
    /** @var int item of the second student */
    private $itemid2;

    /**
     * Two students graded by a teacher and validated by a validator.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->set_flags(false, true, true);
        [$course, , $this->context, $controller, $assign] = $this->create_assign_with_rubric();
        $this->ids = $this->criterion_ids($controller);
        $this->teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->validator = $this->create_validator($course);
        $items = [];
        foreach ([1, 2] as $n) {
            $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
            $itemid = (int) $assign->get_user_grade($student->id, true)->id;
            $teachergrade = $this->filling($this->ids, ['C1' => 65, 'C2' => 2], 'Teacher feedback');
            $this->grade_as($this->teacher, $controller, $itemid, $teachergrade);
            $this->grade_as(
                $this->validator,
                $controller,
                $itemid,
                $this->filling($this->ids, ['C1' => 80, 'C2' => 10], 'Validator feedback', true)
            );
            $items[] = $itemid;
        }
        [$this->itemid, $this->itemid2] = $items;
        $this->setAdminUser();
    }

    /**
     * The component is compliant and declares the IED tables.
     */
    public function test_metadata(): void {
        $this->assertTrue((new \core_privacy\manager())->component_is_compliant('gradingform_rubric_ranges'));
        $tables = array_map(
            fn($item) => $item->get_name(),
            provider::get_metadata(new \core_privacy\local\metadata\collection('gradingform_rubric_ranges'))->get_collection()
        );
        $this->assertContains('gradingform_rubric_ranges_h', $tables);
        $this->assertContains('gradingform_rubric_ranges_w', $tables);
    }

    /**
     * The graded user gets the original and validated grades without the identity of the staff.
     */
    public function test_export_graded_user(): void {
        global $DB;
        \core_grading\privacy\provider::export_item_data($this->context, $this->itemid, ['Assign']);
        $active = $DB->get_record_select('grading_instances', 'itemid = ? AND status = 1', [$this->itemid]);
        $writer = writer::with_context($this->context);
        $filling = (array) $writer->get_data(['Assign', 'Rubric', $active->id]);
        $this->assertEquals(80, $filling['C1']->grade);
        $validation = $writer->get_data(['Assign', 'Rubric', $active->id, 'Validation']);
        $row = $validation->validation[0];
        $this->assertEquals(65, $row->originalgrade);
        $this->assertEquals(80, $row->validatedgrade);
        $this->assertSame('Buen dominio conceptual', $row->originallevel);
        $this->assertSame('Exc', $row->validatedlevel);
        $this->assertSame('Teacher feedback', $row->originalfeedback);
        $this->assertSame('Validator feedback', $row->validatedfeedback);
        $this->assertNotEmpty($row->timevalidated);
        $this->assertFalse(property_exists($row, 'teacherid'));
        $this->assertFalse(property_exists($row, 'validatorid'));
    }

    /**
     * Teacher and validator are found through the privacy manager.
     */
    public function test_contexts_and_users(): void {
        $manager = new \core_privacy\manager();
        foreach ([$this->teacher, $this->validator] as $user) {
            $list = $manager->get_contexts_for_userid($user->id)->get_contextlist_for_component('gradingform_rubric_ranges');
            $this->assertContains((int) $this->context->id, array_map('intval', $list->get_contextids()));
        }
        $userlist = new \core_privacy\local\request\userlist($this->context, 'gradingform_rubric_ranges');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing(
            [(int) $this->teacher->id, (int) $this->validator->id],
            array_map('intval', $userlist->get_userids())
        );
    }

    /**
     * The staff export lists their validations with their role and only their own feedback.
     */
    public function test_export_staff(): void {
        provider::export_user_data(new approved_contextlist($this->validator, 'gradingform_rubric_ranges', [$this->context->id]));
        $data = writer::with_context($this->context)->get_data(['Rubric', 'Validations']);
        $this->assertCount(4, $data->validations);
        $row = $data->validations[0];
        $this->assertSame('Validator', $row->role);
        $this->assertSame('Validator feedback', $row->validatedfeedback);
        $this->assertNull($row->originalfeedback);

        writer::reset();
        provider::export_user_data(new approved_contextlist($this->teacher, 'gradingform_rubric_ranges', [$this->context->id]));
        $row = writer::with_context($this->context)->get_data(['Rubric', 'Validations'])->validations[0];
        $this->assertSame('Teacher', $row->role);
        $this->assertSame('Teacher feedback', $row->originalfeedback);
        $this->assertNull($row->validatedfeedback);
    }

    /**
     * Deleting staff data anonymises them and the grade stays validated.
     */
    public function test_delete_staff(): void {
        $contextlist = new approved_contextlist($this->validator, 'gradingform_rubric_ranges', [$this->context->id]);
        provider::delete_data_for_user($contextlist);
        $row = validation_manager::get_history($this->itemid)[$this->ids['C1']];
        $this->assertSame(0, $row->get('validatorid'));
        $this->assertEquals($this->teacher->id, $row->get('teacherid'));
        $this->assertTrue(validation_manager::is_validated($this->itemid));

        provider::delete_data_for_users(new approved_userlist($this->context, 'gradingform_rubric_ranges', [$this->teacher->id]));
        $row = validation_manager::get_history($this->itemid)[$this->ids['C1']];
        $this->assertNull($row->get('teacherid'));
        $this->assertSame(65, $row->get('teachergrade'));

        provider::delete_data_for_all_users_in_context($this->context);
        $row = validation_manager::get_history($this->itemid2)[$this->ids['C1']];
        $this->assertSame(0, $row->get('validatorid'));
        $this->assertNull($row->get('teacherid'));
        $this->assertSame(80, $row->get('validatedgrade'));
    }

    /**
     * Deleting the graded user data removes their fillings and history only.
     */
    public function test_delete_graded_user(): void {
        global $DB;
        \core_grading\privacy\provider::delete_instance_data($this->context, $this->itemid);
        $this->assertFalse(validation_manager::is_validated($this->itemid));
        $this->assertSame(0, $DB->count_records_select(
            'gradingform_rubric_ranges_f',
            'instanceid IN (SELECT id FROM {grading_instances} WHERE itemid = ?)',
            [$this->itemid]
        ));
        $this->assertTrue(validation_manager::is_validated($this->itemid2));
    }
}
