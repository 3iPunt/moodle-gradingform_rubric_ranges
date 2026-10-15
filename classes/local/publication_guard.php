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

use gradingform_rubric_ranges\event\grade_publication_blocked;

/**
 * Prevents publishing (releasing) a grade of a ranged rubric before it is validated.
 *
 * mod_assign triggers workflow_state_updated whenever the marking workflow state changes: from the grading form,
 * from quick grading and from the bulk action. A grade released without validation is set back to
 * "Ready for release", so mod_assign removes it from the gradebook.
 *
 * @package    gradingform_rubric_ranges
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class publication_guard {
    /**
     * Observer of \mod_assign\event\workflow_state_updated.
     *
     * @param \mod_assign\event\workflow_state_updated $event
     */
    public static function workflow_state_updated(\mod_assign\event\workflow_state_updated $event): void {
        global $CFG;

        if (!features::validation_enabled() || ($event->other['newstate'] ?? '') !== 'released') {
            return;
        }
        require_once($CFG->dirroot . '/grade/grading/lib.php');
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $assign = $event->get_assign();
        $manager = get_grading_manager($assign->get_context(), 'mod_assign', 'submissions');
        if ($manager->get_active_method() !== 'rubric_ranges') {
            return;
        }
        $userid = (int) $event->relateduserid;
        $grade = $assign->get_user_grade($userid, false);
        if ($grade && validation_manager::is_validated((int) $grade->id)) {
            return;
        }

        $flags = $assign->get_user_flags($userid, true);
        $flags->workflowstate = ASSIGN_MARKING_WORKFLOW_STATE_READYFORRELEASE;
        $assign->update_user_flags($flags);
        if ($grade) {
            // Not released any more: mod_assign removes the grade from the gradebook.
            $assign->update_grade($grade);
        }

        $user = \core_user::get_user($userid);
        \core\notification::error(get_string(
            'publicationblocked',
            'gradingform_rubric_ranges',
            $user ? s(fullname($user)) : $userid
        ));
        grade_publication_blocked::create([
            'context' => $assign->get_context(),
            'relateduserid' => $userid,
            'other' => ['itemid' => $grade ? (int) $grade->id : 0],
        ])->trigger();
    }
}
