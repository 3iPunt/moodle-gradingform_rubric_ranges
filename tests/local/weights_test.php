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
require_once($CFG->dirroot . '/grade/grading/form/rubric_ranges/edit_form.php');

use gradingform_rubric_ranges\persistent\criterion_weight;

/**
 * Tests of the criteria weighting.
 *
 * @package    gradingform_rubric_ranges
 * @category   test
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \gradingform_rubric_ranges\local\weights
 * @covers     \gradingform_rubric_ranges_controller
 */
final class weights_test extends \advanced_testcase {
    use \gradingform_rubric_ranges\ied_test_helper;

    /**
     * Weights are normalised to 1-10.
     */
    public function test_clamp(): void {
        $this->assertSame(1, weights::clamp('abc'));
        $this->assertSame(1, weights::clamp(0));
        $this->assertSame(10, weights::clamp(99));
        $this->assertSame(4, weights::clamp('4'));
    }

    /**
     * Percentages over the total weight.
     */
    public function test_percentages(): void {
        $this->assertEquals([1 => 75.0, 2 => 25.0], weights::percentages([1 => ['weight' => 3], 2 => ['weight' => 1]]));
        $this->assertEquals([1 => 50.0, 2 => 50.0], weights::percentages([1 => [], 2 => []]));
    }

    /**
     * Existing rubrics weigh 1 without stored rows; weights are saved, loaded and change the rubric at level 3.
     */
    public function test_save_load_and_change_level(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->set_flags(true, false, false);
        [, , , $controller] = $this->create_assign_with_rubric();
        $ids = $this->criterion_ids($controller);
        $definition = $controller->get_definition(true);
        $this->assertSame(1, $definition->rubric_criteria[$ids['C1']]['weight']);
        $this->assertFalse(criterion_weight::get_record(['criterionid' => $ids['C1']]));

        $data = $controller->get_definition_for_editing();
        $data->rubricranges['criteria'][$ids['C1']]['weight'] = 3;
        $this->assertSame(3, $controller->update_or_check_rubric(clone $data));
        $controller->update_definition($data);
        $definition = $controller->get_definition(true);
        $this->assertSame(3, $definition->rubric_criteria[$ids['C1']]['weight']);
        $this->assertSame(1, $definition->rubric_criteria[$ids['C2']]['weight']);
    }

    /**
     * The editor element clamps submitted weights and never adds a default one.
     */
    public function test_editor_prepare_data(): void {
        $this->resetAfterTest();
        $element = new \MoodleQuickForm_rubricrangeseditor('rubricranges');
        $prepare = fn($value) => (function($v) {
            return $this->prepare_data($v);
        })->call($element, $value);
        $data = $prepare(['criteria' => [5 => ['description' => 'x', 'weight' => 99, 'levels' => []]]]);
        $this->assertSame(10, $data['criteria'][5]['weight']);
        $data = $prepare(['criteria' => [5 => ['description' => 'x', 'levels' => []]]]);
        $this->assertArrayNotHasKey('weight', $data['criteria'][5]);
    }

    /**
     * Saving the rubric with weighting disabled (no weight submitted) keeps the configured weights.
     */
    public function test_flag_off_keeps_weights(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->set_flags(true, false, false);
        [, , , $controller] = $this->create_assign_with_rubric(null, ['C1' => 3]);
        $ids = $this->criterion_ids($controller);
        $this->set_flags(false, false, false);
        $data = $controller->get_definition_for_editing();
        foreach ($data->rubricranges['criteria'] as &$criterion) {
            unset($criterion['weight']);
        }
        unset($criterion);
        $data->rubricranges['criteria'][$ids['C2']]['description'] = 'C2 changed';
        $controller->update_definition($data);
        $this->assertSame(3, $controller->get_definition(true)->rubric_criteria[$ids['C1']]['weight']);
    }

    /**
     * Upstream fix: a weight change with regrade marks the active instances for regrade.
     */
    public function test_regrade_fix(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->set_flags(true, false, false);
        [, , , $controller] = $this->create_assign_with_rubric();
        $ids = $this->criterion_ids($controller);
        $definition = $controller->get_definition(true);
        $filling = ['criteria' => []];
        foreach ($definition->rubric_criteria as $cid => $criterion) {
            $filling['criteria'][$cid] = ['levelid' => array_key_first($criterion['levels']), 'remark' => '', 'grade' => 0];
        }
        $instance = $controller->create_instance(get_admin()->id, 123);
        $instance->submit_and_get_grade($filling, 123);

        $data = $controller->get_definition_for_editing();
        $data->rubricranges['criteria'][$ids['C2']]['weight'] = 5;
        $data->rubricranges['regrade'] = 1;
        $controller->update_definition($data);
        $this->assertEquals(\gradingform_instance::INSTANCE_STATUS_NEEDUPDATE,
            $DB->get_field('grading_instances', 'status', ['id' => $instance->get_id()]));
    }

    /**
     * Copying the rubric to another area copies the weights.
     */
    public function test_definition_copy(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->set_flags(true, false, false);
        [$course, , , $controller] = $this->create_assign_with_rubric(null, ['C1' => 3, 'C2' => 5]);
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $manager = get_grading_manager(\context_module::instance($assign->cmid), 'mod_assign', 'submissions');
        $manager->set_active_method('rubric_ranges');
        $target = $manager->get_controller('rubric_ranges');
        $target->update_definition($controller->get_definition_copy($target));
        $weights = array_column($target->get_definition(true)->rubric_criteria, 'weight', 'description');
        $this->assertSame(['C1' => 3, 'C2' => 5], $weights);
    }

    /**
     * Deleting a criterion or the definition deletes its weights.
     */
    public function test_delete(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->set_flags(true, false, false);
        [, , , $controller] = $this->create_assign_with_rubric(null, ['C1' => 3, 'C2' => 5]);
        $ids = $this->criterion_ids($controller);
        $data = $controller->get_definition_for_editing();
        unset($data->rubricranges['criteria'][$ids['C2']]);
        $controller->update_definition($data);
        $this->assertFalse(criterion_weight::get_record(['criterionid' => $ids['C2']]));
        $this->assertNotFalse(criterion_weight::get_record(['criterionid' => $ids['C1']]));
        $controller->delete_definition();
        $this->assertFalse(criterion_weight::get_record(['criterionid' => $ids['C1']]));
    }
}
