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
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

use gradingform_rubric_ranges\local\validation_manager;
use gradingform_rubric_ranges\persistent\validation_history;

/**
 * Tests of the backup and restore of weights and validation history.
 *
 * @package    gradingform_rubric_ranges
 * @category   test
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_gradingform_rubric_ranges_plugin
 * @covers     \restore_gradingform_rubric_ranges_plugin
 */
final class backup_restore_test extends \advanced_testcase {
    use ied_test_helper;

    /**
     * Backs up a course and restores it into a new one.
     *
     * @param int $courseid
     * @param bool $users
     * @return int new course id
     */
    private function backup_restore(int $courseid, bool $users): int {
        global $CFG, $USER;
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $courseid,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value($users);
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $CFG->tempdir . '/backup/' . $backupid);
        $bc->destroy();
        $newcourseid = \restore_dbops::create_new_course('Restored', 'RST' . random_string(6), 1);
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_value($users);
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();
        return $newcourseid;
    }

    /**
     * Rubric controller and course module of the only assignment of a course.
     *
     * @param int $courseid
     * @return array
     */
    private function controller_of(int $courseid): array {
        $cm = array_values(get_fast_modinfo($courseid)->get_instances_of('assign'))[0];
        $manager = get_grading_manager(\context_module::instance($cm->id), 'mod_assign', 'submissions');
        return [$manager->get_controller('rubric_ranges'), $cm];
    }

    /**
     * Weights and history are restored and remapped; without user data only the weights.
     */
    public function test_backup_restore(): void {
        $this->resetAfterTest();
        $this->set_flags(true, true, true);
        [$course, , , $controller, $assign] = $this->create_assign_with_rubric(null, ['C1' => 3, 'C2' => 1]);
        $ids = $this->criterion_ids($controller);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $validator = $this->create_validator($course);
        $s1 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $s2 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        // Real assign_grades rows: mod_assign maps the grading item id through them.
        $grade1 = $assign->get_user_grade($s1->id, true);
        $grade2 = $assign->get_user_grade($s2->id, true);
        $this->grade_as($teacher, $controller, $grade1->id, $this->filling($ids, ['C1' => 65, 'C2' => 2], 'Teacher'));
        $this->grade_as($teacher, $controller, $grade2->id, $this->filling($ids, ['C1' => 40, 'C2' => 8], 'Teacher2'));
        $this->grade_as($validator, $controller, $grade1->id, $this->filling($ids, ['C1' => 80, 'C2' => 10], 'Validator', true));
        $original = validation_manager::get_history($grade1->id)[$ids['C1']];

        $this->setAdminUser();
        $newcourseid = $this->backup_restore($course->id, true);
        role_assign($this->get_validator_role(), $validator->id, \context_course::instance($newcourseid)->id);
        [$newcontroller, $newcm] = $this->controller_of($newcourseid);
        $newids = $this->criterion_ids($newcontroller);
        $newdefinition = $newcontroller->get_definition(true);
        $this->assertSame(3, $newdefinition->rubric_criteria[$newids['C1']]['weight']);
        $this->assertSame(1, $newdefinition->rubric_criteria[$newids['C2']]['weight']);

        $newassign = new \assign(\context_module::instance($newcm->id), $newcm, get_course($newcourseid));
        $newgrade1 = $newassign->get_user_grade($s1->id, false);
        $newgrade2 = $newassign->get_user_grade($s2->id, false);
        $history = validation_manager::get_history($newgrade1->id);
        $this->assertCount(2, $history);
        $row = $history[$newids['C1']];
        $levels = $newdefinition->rubric_criteria[$newids['C1']]['levels'];
        $this->assertSame('Buen dominio conceptual', $levels[$row->get('teacherlevelid')]['definition']);
        $this->assertSame('Exc', $levels[$row->get('validatedlevelid')]['definition']);
        $this->assertSame(65, $row->get('teachergrade'));
        $this->assertSame(80, $row->get('validatedgrade'));
        $this->assertSame('Teacher', $row->get('teacherremark'));
        $this->assertSame('Validator', $row->get('validatedremark'));
        $this->assertEquals($original->get('timevalidated'), $row->get('timevalidated'));
        $this->assertEquals($teacher->id, $row->get('teacherid'));
        $this->assertEquals($validator->id, $row->get('validatorid'));

        $this->assertSame(validation_manager::STATE_VALIDATED, validation_manager::get_state(
            $newcontroller->get_or_create_instance(0, get_admin()->id, $newgrade1->id),
            (int) $validator->id
        ));
        $this->assertSame(validation_manager::STATE_PENDING, validation_manager::get_state(
            $newcontroller->get_or_create_instance(0, $validator->id, $newgrade2->id),
            (int) $validator->id
        ));

        // Without user data: weights yes, history no.
        $nousersid = $this->backup_restore($course->id, false);
        [$nucontroller] = $this->controller_of($nousersid);
        $nudefinition = $nucontroller->get_definition(true);
        $this->assertSame(['C1' => 3, 'C2' => 1], array_column($nudefinition->rubric_criteria, 'weight', 'description'));
        [$insql, $params] = $GLOBALS['DB']->get_in_or_equal(array_keys($nudefinition->rubric_criteria));
        $this->assertSame(0, validation_history::count_records_select("criterionid $insql", $params));
    }
}
