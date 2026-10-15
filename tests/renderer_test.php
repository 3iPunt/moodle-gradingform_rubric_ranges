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
require_once($CFG->libdir . '/formslib.php');
require_once('HTML/QuickForm/input.php');
require_once($CFG->libdir . '/form/grading.php');

use gradingform_rubric_ranges_controller as controller;

/**
 * Tests of the rendering of the IED extension.
 *
 * @package    gradingform_rubric_ranges
 * @category   test
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \gradingform_rubric_ranges_renderer
 * @covers     \gradingform_rubric_ranges_instance::render_grading_element
 */
final class renderer_test extends \advanced_testcase {
    use ied_test_helper;

    /**
     * Renders the rubric of a controller in a mode.
     *
     * @param \gradingform_rubric_ranges_controller $controller
     * @param int $mode
     * @param array|null $values
     * @param array $options option overrides
     * @return string
     */
    private function render(
        \gradingform_rubric_ranges_controller $controller,
        int $mode,
        ?array $values = null,
        array $options = []
    ): string {
        global $PAGE;
        $PAGE->set_url('/');
        $definition = $controller->get_definition(true);
        return $controller->get_renderer($PAGE)->display_rubric(
            $definition->rubric_criteria,
            array_merge($controller->get_options(), $options),
            $mode,
            'rr',
            $values
        );
    }

    /**
     * Weights: selector when editing, summary for graders, nothing for students.
     */
    public function test_weights(): void {
        $this->resetAfterTest();
        $this->set_flags(true, false, false);
        [, , , $controller] = $this->create_assign_with_rubric(null, ['C1' => 3]);
        $ids = $this->criterion_ids($controller);
        $this->assertStringContainsString(
            'name="rr[criteria][' . $ids['C1'] . '][weight]"',
            $this->render($controller, controller::DISPLAY_EDIT_FULL)
        );
        $this->assertStringContainsString('Weight 3 · 75 %', $this->render($controller, controller::DISPLAY_REVIEW));
        $this->assertStringNotContainsString('class="weight"', $this->render($controller, controller::DISPLAY_VIEW));
        $this->assertMatchesRegularExpression(
            '/type="hidden" name="rr\[criteria\]\[' . $ids['C1'] . '\]\[weight\]" value="3"/',
            $this->render($controller, controller::DISPLAY_EDIT_FROZEN)
        );
        $this->set_flags(false, false, false);
        $this->assertStringNotContainsString('weightselect', $this->render($controller, controller::DISPLAY_EDIT_FULL));
    }

    /**
     * Numeric grading: number input, level data attributes, ranges for every criterion and remark warning.
     */
    public function test_numeric(): void {
        $this->resetAfterTest();
        $this->set_flags(false, true, false);
        [, , , $controller] = $this->create_assign_with_rubric();
        $ids = $this->criterion_ids($controller);
        $instance = $controller->create_instance(get_admin()->id, 10);
        $instance->submit_and_get_grade($this->filling($ids, ['C1' => 65, 'C2' => 2]), 10);
        $values = $instance->get_rubric_filling();

        $eval = $this->render($controller, controller::DISPLAY_EVAL, $values);
        $this->assertMatchesRegularExpression(
            '/<input type="number" name="rr\[criteria\]\[' . $ids['C1'] . '\]\[grade\]"[^>]*max="100"[^>]*value="65"/',
            $eval
        );
        $this->assertStringNotContainsString('<select', $eval);
        $this->assertStringContainsString('data-rangemin="61" data-rangemax="70" data-definition="Buen dominio conceptual"', $eval);
        $this->assertStringContainsString('0 to 5', $eval);
        $review = $this->render($controller, controller::DISPLAY_REVIEW, $values);
        $this->assertStringContainsString('65 pts', $review);
        $this->assertStringContainsString(
            'feedback of the level will not be applied',
            $this->render($controller, controller::DISPLAY_EDIT_FULL, null, ['enableremarks' => 0])
        );
        // No PHP notice for the upstream $currentgrade when remarks are disabled.
        $this->render($controller, controller::DISPLAY_EVAL_FROZEN, $values, ['enableremarks' => 0]);
    }

    /**
     * Validation states in the grading element and in the feedback shown to graders and students.
     */
    public function test_validation(): void {
        global $PAGE, $DB;
        $this->resetAfterTest();
        $this->set_flags(false, true, true);
        [$course, , $context, $controller] = $this->create_assign_with_rubric();
        $ids = $this->criterion_ids($controller);
        $teacher = $this->getDataGenerator()->create_and_enrol(
            $course,
            'editingteacher',
            ['firstname' => 'Tea', 'lastname' => 'Cher']
        );
        $validator = $this->create_validator($course);
        $DB->set_field('user', 'firstname', 'Vali', ['id' => $validator->id]);
        // Markup in the name must be escaped in the validation badge.
        $DB->set_field('user', 'lastname', 'Dator <i>x</i>', ['id' => $validator->id]);
        foreach ([900, 903] as $itemid) {
            $this->grade_as($teacher, $controller, $itemid, $this->filling($ids, ['C1' => 65, 'C2' => 2], 'T1'));
        }
        $this->grade_as($validator, $controller, 900, $this->filling($ids, ['C1' => 80, 'C2' => 7], 'V1', true));

        $PAGE->set_context($context);
        $PAGE->set_url('/');
        $render = function (int $itemid) use ($controller, $validator) {
            global $PAGE;
            $instance = $controller->get_or_create_instance(0, $validator->id, $itemid);
            $element = new \MoodleQuickForm_grading('advancedgrading', 'Grade', ['gradinginstance' => $instance]);
            return $instance->render_grading_element($PAGE, $element);
        };
        $this->setUser($validator);
        $pending = $render(903);
        $this->assertStringContainsString('id="advancedgrading-validate-button"', $pending);
        $this->assertStringContainsString('id="rubric-advancedgrading-review"', $pending);
        $this->assertMatchesRegularExpression('/id="advancedgrading-validation-editable" hidden="hidden"/', $pending);
        $this->assertStringContainsString('name="advancedgrading[validate]" id="advancedgrading-validate" value="0"', $pending);

        $validated = $render(900);
        $this->assertStringContainsString('Grade validated by Vali Dator &lt;i&gt;x&lt;/i&gt;', $validated);
        $this->assertStringNotContainsString('<i>x</i>', $validated);
        $this->assertStringContainsString('Original grade: 65 · Buen dominio conceptual · Tea Cher', $validated);
        $this->assertStringContainsString('Original feedback: T1', $validated);
        $this->assertStringNotContainsString('type="number"', $validated);

        $this->assertStringContainsString('has not graded yet', $render(901));

        $active = $controller->get_or_create_instance(0, $validator->id, 900)->get_current_instance();
        $renderer = $controller->get_renderer($PAGE);
        $this->assertStringContainsString('Grade validated by', $renderer->display_instance($active, 0, true));
        $student = $renderer->display_instance($active, 0, false);
        $this->assertStringNotContainsString('validated', $student);
        $this->assertStringNotContainsString('Original grade', $student);

        // Without the marking workflow the graders are warned that grades reach the students at once.
        $this->assertStringContainsString('does not use the marking workflow', $validated);
    }

    /**
     * With the marking workflow, a validated grade not published is editable for the validator only.
     */
    public function test_validated_pending_publication(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->set_flags(false, true, true);
        [$course, , $context, $controller, $assign] = $this->create_assign_with_rubric(null, [], ['markingworkflow' => 1]);
        $ids = $this->criterion_ids($controller);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $validator = $this->create_validator($course);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $itemid = (int) $assign->get_user_grade($student->id, true)->id;
        $this->grade_as($teacher, $controller, $itemid, $this->filling($ids, ['C1' => 65, 'C2' => 2], 'T1'));
        $this->grade_as($validator, $controller, $itemid, $this->filling($ids, ['C1' => 80, 'C2' => 7], 'V1', true));

        $PAGE->set_context($context);
        $PAGE->set_url('/');
        $render = function (\stdClass $user) use ($controller, $itemid) {
            global $PAGE;
            $this->setUser($user);
            $instance = $controller->get_or_create_instance(0, $user->id, $itemid);
            $element = new \MoodleQuickForm_grading('advancedgrading', 'Grade', ['gradinginstance' => $instance]);
            return $instance->render_grading_element($PAGE, $element);
        };
        $forvalidator = $render($validator);
        $this->assertStringContainsString('you can still correct it until it is released', $forvalidator);
        $this->assertMatchesRegularExpression('/<input type="number" name="advancedgrading\[criteria\]\[' . $ids['C1'] .
            '\]\[grade\]"[^>]*value="80"/', $forvalidator);
        $this->assertStringContainsString('Original grade: 65', $forvalidator);
        $this->assertStringNotContainsString('does not use the marking workflow', $forvalidator);

        $forteacher = $render($teacher);
        $this->assertStringContainsString('pending publication by the validator and cannot be changed', $forteacher);
        $this->assertStringNotContainsString('type="number"', $forteacher);
    }
}
