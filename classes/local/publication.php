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

use gradingform_rubric_ranges_instance;

/**
 * Publication state of a grade, read from the marking workflow of mod_assign.
 *
 * With the marking workflow, mod_assign keeps the grade and the feedback out of the gradebook and hidden from the
 * student until the grade is "Released" (published). Other activities have no marking workflow.
 *
 * @package    gradingform_rubric_ranges
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class publication {
    /** @var string The activity has no marking workflow: grades are visible as soon as they are saved. */
    const NO_WORKFLOW = 'noworkflow';

    /** @var string Marking workflow enabled and the grade is not released yet. */
    const UNPUBLISHED = 'unpublished';

    /** @var string Marking workflow enabled and the grade is released. */
    const PUBLISHED = 'published';

    /**
     * Returns the publication state of the grade of a grading instance.
     *
     * @param gradingform_rubric_ranges_instance $instance
     * @return string one of the class constants
     */
    public static function status(gradingform_rubric_ranges_instance $instance): string {
        $controller = $instance->get_controller();
        $assign = self::get_assign($controller->get_context(), $controller->get_component());
        if (!$assign || empty($assign->get_instance()->markingworkflow)) {
            return self::NO_WORKFLOW;
        }
        $userid = self::graded_userid((int) $instance->get_data('itemid'));
        if (!$userid) {
            return self::UNPUBLISHED;
        }
        $flags = $assign->get_user_flags($userid, false);
        $state = $flags->workflowstate ?? '';
        return $state === ASSIGN_MARKING_WORKFLOW_STATE_RELEASED ? self::PUBLISHED : self::UNPUBLISHED;
    }

    /**
     * Returns the assignment of a grading area context, or null for other activities.
     *
     * @param \context $context
     * @param string $component component of the grading area
     * @return \assign|null
     */
    public static function get_assign(\context $context, string $component): ?\assign {
        global $CFG;

        if ($component !== 'mod_assign' || $context->contextlevel != CONTEXT_MODULE) {
            return null;
        }
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        [$course, $cm] = get_course_and_cm_from_cmid($context->instanceid, 'assign');
        return new \assign($context, $cm, $course);
    }

    /**
     * Returns the graded user of a mod_assign grading item.
     *
     * Direct query on a core table: mod_assign has no API to get a grade by its id.
     *
     * @param int $itemid assign_grades.id
     * @return int|null
     */
    private static function graded_userid(int $itemid): ?int {
        global $DB;

        if (!$itemid) {
            return null;
        }
        $userid = $DB->get_field('assign_grades', 'userid', ['id' => $itemid]);
        return $userid ? (int) $userid : null;
    }
}
