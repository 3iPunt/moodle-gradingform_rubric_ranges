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

use gradingform_instance;
use gradingform_rubric_ranges\event\grade_validated;
use gradingform_rubric_ranges\persistent\validation_history;
use gradingform_rubric_ranges_instance;

/**
 * Business rules of the academic validation.
 *
 * A grade (grading item) can be validated once by a user with gradingform/rubric_ranges:validate who is not
 * the grader. The validator grade and feedback replace the teacher ones, which are kept in the validation
 * history. A grade is validated when it has history; then nobody can change it.
 *
 * @package    gradingform_rubric_ranges
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class validation_manager {

    /** @var string Capability to validate grades. */
    const CAPABILITY = 'gradingform/rubric_ranges:validate';

    /** @var string Validation disabled: upstream behaviour. */
    const STATE_OFF = 'off';

    /** @var string User without the validate capability: upstream behaviour. */
    const STATE_NORMAL = 'normal';

    /** @var string The grade is validated: read only for everybody. */
    const STATE_VALIDATED = 'validated';

    /** @var string Validator and the teacher has not graded yet: read only. */
    const STATE_NOT_GRADED = 'notgraded';

    /** @var string Validator who gave the current grade: upstream behaviour (no self validation). */
    const STATE_SELF = 'self';

    /** @var string Validator and a grade of another teacher waiting for validation. */
    const STATE_PENDING = 'pending';

    /**
     * Whether the user can validate grades in the context.
     *
     * @param \context $context
     * @param int|null $userid
     * @return bool
     */
    public static function can_validate(\context $context, ?int $userid = null): bool {
        return features::validation_enabled() && self::has_validate_capability($context, $userid);
    }

    /**
     * Whether the user has the validate capability.
     *
     * Site administrators are not validators by default ($doanything = false): otherwise they could not
     * give the first grade. They need a role granting the capability explicitly.
     *
     * @param \context $context
     * @param int|null $userid
     * @return bool
     */
    private static function has_validate_capability(\context $context, ?int $userid): bool {
        return has_capability(self::CAPABILITY, $context, $userid, false);
    }

    /**
     * Whether the grade of the item has been validated.
     *
     * @param int $itemid grading item (grading_instances.itemid)
     * @return bool
     */
    public static function is_validated(int $itemid): bool {
        return validation_history::record_exists_select('itemid = :itemid', ['itemid' => $itemid]);
    }

    /**
     * Returns the validation history of an item.
     *
     * @param int $itemid
     * @return validation_history[] criterionid => history
     */
    public static function get_history(int $itemid): array {
        $history = [];
        foreach (validation_history::get_records(['itemid' => $itemid]) as $record) {
            $history[$record->get('criterionid')] = $record;
        }
        return $history;
    }

    /**
     * Returns the current graded instance of the item of the given instance (ACTIVE or NEEDUPDATE with filling).
     *
     * @param gradingform_rubric_ranges_instance $instance
     * @return gradingform_rubric_ranges_instance|null
     */
    public static function get_current_graded_instance(gradingform_rubric_ranges_instance $instance) {
        $current = $instance->get_current_instance();
        if (!$current || empty($current->get_rubric_filling()['criteria'])) {
            return null;
        }
        return $current;
    }

    /**
     * Returns the validation state of an instance for a user.
     *
     * @param gradingform_rubric_ranges_instance $instance
     * @param int $userid
     * @return string one of the STATE_* constants
     */
    public static function get_state(gradingform_rubric_ranges_instance $instance, int $userid): string {
        if (!features::validation_enabled()) {
            return self::STATE_OFF;
        }
        $itemid = (int) $instance->get_data('itemid');
        if ($itemid && self::is_validated($itemid)) {
            return self::STATE_VALIDATED;
        }
        if (!self::has_validate_capability($instance->get_controller()->get_context(), $userid)) {
            return self::STATE_NORMAL;
        }
        $current = self::get_current_graded_instance($instance);
        if (!$current) {
            return self::STATE_NOT_GRADED;
        }
        if ((int) $current->get_data('raterid') === $userid) {
            return self::STATE_SELF;
        }
        return self::STATE_PENDING;
    }

    /**
     * Whether a submission must be ignored in the given state.
     *
     * @param string $state
     * @param mixed $elementvalue submitted value of the grading element
     * @return bool
     */
    public static function is_locked(string $state, $elementvalue): bool {
        if ($state === self::STATE_VALIDATED || $state === self::STATE_NOT_GRADED) {
            return true;
        }
        return $state === self::STATE_PENDING && empty($elementvalue['validate']);
    }

    /**
     * Ignores a submission: discards the copy of the instance and returns the current grade.
     *
     * The copy is discarded instead of activated so the submitter does not become the grader.
     *
     * @param gradingform_rubric_ranges_instance $instance
     * @return float|int current grade, -1 when there is none
     */
    public static function keep_current_grade(gradingform_rubric_ranges_instance $instance) {
        $current = self::get_current_graded_instance($instance);
        if ($instance->get_status() == gradingform_instance::INSTANCE_STATUS_INCOMPLETE) {
            $instance->cancel();
        }
        return $current ? $current->get_grade() : -1;
    }

    /**
     * Stores the validation history once the validator filling has been saved.
     *
     * @param gradingform_rubric_ranges_instance $validated instance saved by the validator
     * @param gradingform_rubric_ranges_instance $teacher instance graded by the teacher
     * @param int $validatorid
     */
    public static function record_validation(gradingform_rubric_ranges_instance $validated,
            gradingform_rubric_ranges_instance $teacher, int $validatorid): void {
        $controller = $validated->get_controller();
        $teacherfilling = $teacher->get_rubric_filling()['criteria'];
        $validatedfilling = $validated->get_rubric_filling(true)['criteria'];
        $itemid = (int) $validated->get_data('itemid');
        $now = time();

        $first = null;
        foreach (array_keys($controller->get_definition()->rubric_criteria) as $criterionid) {
            $original = $teacherfilling[$criterionid] ?? [];
            $new = $validatedfilling[$criterionid] ?? [];
            $record = new validation_history(0, (object) [
                'itemid' => $itemid,
                'instanceid' => $validated->get_id(),
                'criterionid' => $criterionid,
                'teacherlevelid' => self::int_or_null($original['levelid'] ?? null),
                'teachergrade' => self::int_or_null($original['grade'] ?? null),
                'teacherremark' => $original['remark'] ?? null,
                'teacherremarkformat' => self::int_or_null($original['remarkformat'] ?? null),
                'teacherid' => (int) $teacher->get_data('raterid'),
                'validatedlevelid' => self::int_or_null($new['levelid'] ?? null),
                'validatedgrade' => self::int_or_null($new['grade'] ?? null),
                'validatedremark' => $new['remark'] ?? null,
                'validatorid' => $validatorid,
                'timevalidated' => $now,
            ]);
            $record->create();
            $first = $first ?? $record;
        }

        if ($first) {
            grade_validated::create([
                'context' => $controller->get_context(),
                'objectid' => $first->get('id'),
                'userid' => $validatorid,
                'other' => [
                    'itemid' => $itemid,
                    'instanceid' => $validated->get_id(),
                    'criteria' => count($controller->get_definition()->rubric_criteria),
                    'teacherid' => (int) $teacher->get_data('raterid'),
                ],
            ])->trigger();
        }
    }

    /**
     * Deletes the validation history of the given criteria.
     *
     * @param int[] $criterionids
     */
    public static function delete_for_criteria(array $criterionids): void {
        global $DB;

        $criterionids = array_filter(array_map('intval', $criterionids));
        if (empty($criterionids)) {
            return;
        }
        // Only builds the SQL fragment, the query is run by the persistent.
        [$insql, $params] = $DB->get_in_or_equal($criterionids, SQL_PARAMS_NAMED);
        foreach (validation_history::get_records_select("criterionid $insql", $params) as $record) {
            $record->delete();
        }
    }

    /**
     * Casts a value to int keeping nulls and empty strings as null.
     *
     * @param mixed $value
     * @return int|null
     */
    private static function int_or_null($value): ?int {
        return ($value === null || $value === '') ? null : (int) $value;
    }
}
