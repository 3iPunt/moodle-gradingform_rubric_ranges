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
 * With every IED feature disabled the plugin behaves as upstream.
 *
 * The byte to byte comparison of the HTML with the upstream code needs both code bases, so it is a separate
 * script (see PATCHES.md); here the absence of any IED markup is checked.
 *
 * @package    gradingform_rubric_ranges
 * @category   test
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \gradingform_rubric_ranges_instance::get_grade
 * @covers     \gradingform_rubric_ranges_renderer
 */
final class upstream_compat_test extends \advanced_testcase {
    use ied_test_helper;

    /**
     * Data provider for {@see test_grade_is_upstream()}.
     *
     * @return array
     */
    public static function lock_provider(): array {
        return ['lockzeropoints on' => [1], 'lockzeropoints off' => [0]];
    }

    /**
     * The grade is the upstream formula, even with stored weights.
     *
     * @dataProvider lock_provider
     * @param int $lock
     */
    public function test_grade_is_upstream(int $lock): void {
        $this->resetAfterTest();
        $this->set_flags(true, false, false);
        [, , , $controller] = $this->create_assign_with_rubric(null, ['C1' => 3]);
        $this->set_flags(false, false, false);
        $data = $controller->get_definition_for_editing();
        $data->rubricranges['options']['lockzeropoints'] = $lock;
        $controller->update_definition($data);
        $controller->set_grade_range(make_grades_menu(100), true);
        $ids = $this->criterion_ids($controller);
        $definition = $controller->get_definition(true);
        $c1 = $definition->rubric_criteria[$ids['C1']];
        $c2 = $definition->rubric_criteria[$ids['C2']];
        $instance = $controller->create_instance(get_admin()->id, 50);
        $grade = $instance->submit_and_get_grade(['criteria' => [
            $ids['C1'] => ['levelid' => $this->level_id($c1, 'Buen dominio conceptual'), 'grade' => 65, 'remark' => ''],
            $ids['C2'] => ['levelid' => $this->level_id($c2, 'Bad'), 'remark' => ''],
        ]], 50);
        // Upstream: C1 is ranged (grade 65), C2 counts its level score (5); min 55, max 110.
        $expected = $lock ? 70 / 110 * 100 : (70 - 55) / (110 - 55) * 100;
        $this->assertEqualsWithDelta($expected, $grade, 0.001);
        $this->assertEquals(['minscore' => 55, 'maxscore' => 110], $controller->get_min_max_score());
    }

    /**
     * No IED markup in any display mode.
     */
    public function test_no_ied_markup(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->set_flags(false, false, false);
        [, , , $controller] = $this->create_assign_with_rubric(null, []);
        $PAGE->set_url('/');
        $definition = $controller->get_definition(true);
        $renderer = $controller->get_renderer($PAGE);
        foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $mode) {
            $html = $renderer->display_rubric($definition->rubric_criteria, $controller->get_options(), $mode, 'rr');
            foreach (['weightselect', 'class="weight"', 'data-rangemin', 'gradeinput', 'validate', 'class="validation"'] as $mark) {
                $this->assertStringNotContainsString($mark, $html, "Mode $mode contains $mark");
            }
        }
    }
}
