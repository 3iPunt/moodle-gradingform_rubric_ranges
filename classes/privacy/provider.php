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

/**
 * Privacy class for requesting user data.
 *
 * @package    gradingform_rubric_ranges
 * @copyright  2022 Heena Agheda <heenaagheda@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace gradingform_rubric_ranges\privacy;

use \core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use gradingform_rubric_ranges\local\validation_manager;

/**
 * Privacy class for requesting user data.
 *
 * IED extension: besides the data of the graded user (called by core_grading through gradingform_provider_v2),
 * the plugin is a provider on its own for the teacher and the validator stored in the validation history:
 * their data is exported and, on deletion, anonymised so the grade stays validated.
 *
 * @copyright  2018 Sara Arjona <sara@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_grading\privacy\gradingform_provider_v2,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

    /**
     * Returns meta data about this system.
     *
     * @param  collection $collection The initialised collection to add items to.
     * @return collection A listing of user data stored through this system.
     */
    public static function get_metadata(collection $collection) : collection {
        $collection->add_database_table('gradingform_rubric_ranges_f', [
            'instanceid' => 'privacy:metadata:instanceid',
            'criterionid' => 'privacy:metadata:criterionid',
            'levelid' => 'privacy:metadata:levelid',
            'remark' => 'privacy:metadata:remark',
            'grade' => 'privacy:metadata:grade', // IED extension.
        ], 'privacy:metadata:fillingssummary');
        // IED extension: validation history and criteria weights.
        $collection->add_database_table('gradingform_rubric_ranges_h', [
            'itemid' => 'privacy:metadata:validation:itemid',
            'criterionid' => 'privacy:metadata:criterionid',
            'teachergrade' => 'privacy:metadata:validation:teachergrade',
            'teacherremark' => 'privacy:metadata:validation:teacherremark',
            'teacherid' => 'privacy:metadata:validation:teacherid',
            'validatedgrade' => 'privacy:metadata:validation:validatedgrade',
            'validatedremark' => 'privacy:metadata:validation:validatedremark',
            'validatorid' => 'privacy:metadata:validation:validatorid',
            'timevalidated' => 'privacy:metadata:validation:timevalidated',
            'usermodified' => 'privacy:metadata:usermodified',
        ], 'privacy:metadata:validationhistory');
        $collection->add_database_table('gradingform_rubric_ranges_w', [
            'usermodified' => 'privacy:metadata:usermodified',
        ], 'privacy:metadata:weights');
        return $collection;
    }

    /**
     * Export user data relating to an instance ID.
     *
     * @param  \context $context Context to use with the export writer.
     * @param  int $instanceid The instance ID to export data for.
     * @param  array $subcontext The directory to export this data to.
     */
    public static function export_gradingform_instance_data(\context $context, int $instanceid, array $subcontext) {
        global $DB;
        // Get records from the provided params.
        $params = ['instanceid' => $instanceid];
        // IED extension: also criteria without level (LEFT JOIN) and the numeric grade. The export stays keyed by
        // the criterion description, as upstream (criteria with the same description are merged: known limitation).
        $sql = "SELECT rc.description, rl.definition, rl.score, rf.grade, rf.remark
                  FROM {gradingform_rubric_ranges_f} rf
                  JOIN {gradingform_rubric_ranges_c} rc ON rc.id = rf.criterionid
             LEFT JOIN {gradingform_rubric_ranges_l} rl ON rf.levelid = rl.id
                 WHERE rf.instanceid = :instanceid";
        $records = $DB->get_records_sql($sql, $params);
        $subcontext = array_merge($subcontext, [get_string('rubric', 'gradingform_rubric_ranges'), $instanceid]);
        if ($records) {
            \core_privacy\local\request\writer::with_context($context)->export_data($subcontext, (object) $records);
        }
        self::export_instance_validation($context, $instanceid, $subcontext);
    }

    /**
     * IED extension: exports the validation history stored in an instance for the graded user.
     *
     * Grades, levels, feedback and date only: the identity of the teacher and the validator is not exported.
     *
     * @param \context $context
     * @param int $instanceid
     * @param array $subcontext
     */
    protected static function export_instance_validation(\context $context, int $instanceid, array $subcontext) {
        $history = validation_manager::get_history_for_instance($instanceid);
        if (empty($history)) {
            return;
        }
        $levels = self::level_definitions($history);
        $criteria = self::criterion_descriptions($history);
        $data = [];
        foreach ($history as $record) {
            $data[] = (object) [
                'criterion' => $criteria[$record->get('criterionid')] ?? '',
                'originalgrade' => $record->get('teachergrade'),
                'originallevel' => $levels[$record->get('teacherlevelid')] ?? null,
                'originalfeedback' => $record->get('teacherremark'),
                'validatedgrade' => $record->get('validatedgrade'),
                'validatedlevel' => $levels[$record->get('validatedlevelid')] ?? null,
                'validatedfeedback' => $record->get('validatedremark'),
                'timevalidated' => transform::datetime($record->get('timevalidated')),
            ];
        }
        writer::with_context($context)->export_data(
            array_merge($subcontext, [get_string('privacy:validation', 'gradingform_rubric_ranges')]),
            (object) ['validation' => $data]);
    }

    /**
     * Deletes all user data related to the provided instance IDs.
     *
     * @param  array  $instanceids The instance IDs to delete information from.
     */
    public static function delete_gradingform_for_instances(array $instanceids) {
        global $DB;
        $DB->delete_records_list('gradingform_rubric_ranges_f', 'instanceid', $instanceids);
        // IED extension: validation history of the graded user.
        validation_manager::delete_for_instances($instanceids);
    }

    /**
     * IED extension: contexts where the user is the teacher or the validator of a validated grade.
     *
     * The privacy API needs SQL to collect contexts; it is the only SQL on the plugin tables.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_from_sql("SELECT a.contextid
                                      FROM {gradingform_rubric_ranges_h} h
                                      JOIN {gradingform_rubric_ranges_c} c ON c.id = h.criterionid
                                      JOIN {grading_definitions} d ON d.id = c.definitionid
                                      JOIN {grading_areas} a ON a.id = d.areaid
                                     WHERE h.teacherid = :teacherid OR h.validatorid = :validatorid",
            ['teacherid' => $userid, 'validatorid' => $userid]);
        return $contextlist;
    }

    /**
     * IED extension: teachers and validators of the validation history in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }
        $sql = "SELECT h.teacherid, h.validatorid
                  FROM {gradingform_rubric_ranges_h} h
                  JOIN {gradingform_rubric_ranges_c} c ON c.id = h.criterionid
                  JOIN {grading_definitions} d ON d.id = c.definitionid
                  JOIN {grading_areas} a ON a.id = d.areaid
                 WHERE a.contextid = :contextid";
        $userlist->add_from_sql('teacherid', $sql, ['contextid' => $context->id]);
        $userlist->add_from_sql('validatorid', $sql, ['contextid' => $context->id]);
    }

    /**
     * IED extension: exports the validations where the user is the teacher or the validator.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $criteria = validation_manager::criteria_in_contexts([$context->id]);
            $history = validation_manager::get_history_for_user($userid, array_keys($criteria));
            if (empty($history)) {
                continue;
            }
            $data = [];
            foreach ($history as $record) {
                $isteacher = (int) $record->get('teacherid') === $userid;
                $isvalidator = (int) $record->get('validatorid') === $userid;
                $roles = [];
                if ($isteacher) {
                    $roles[] = get_string('privacy:role:teacher', 'gradingform_rubric_ranges');
                }
                if ($isvalidator) {
                    $roles[] = get_string('privacy:role:validator', 'gradingform_rubric_ranges');
                }
                $data[] = (object) [
                    'role' => implode(', ', $roles),
                    'itemid' => $record->get('itemid'),
                    'criterion' => $criteria[$record->get('criterionid')] ?? '',
                    'originalgrade' => $record->get('teachergrade'),
                    'validatedgrade' => $record->get('validatedgrade'),
                    'timevalidated' => transform::datetime($record->get('timevalidated')),
                    // Only the feedback written by the user.
                    'originalfeedback' => $isteacher ? $record->get('teacherremark') : null,
                    'validatedfeedback' => $isvalidator ? $record->get('validatedremark') : null,
                ];
            }
            writer::with_context($context)->export_data([
                get_string('rubric', 'gradingform_rubric_ranges'),
                get_string('privacy:validations', 'gradingform_rubric_ranges'),
            ], (object) ['validations' => $data]);
        }
    }

    /**
     * IED extension: anonymises the teachers and validators of the validation history in a context.
     *
     * The data of the graded users is deleted through the activity (core_grading).
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }
        validation_manager::anonymise_all(array_keys(validation_manager::criteria_in_contexts([$context->id])));
    }

    /**
     * IED extension: anonymises the user as teacher or validator in the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $criteria = validation_manager::criteria_in_contexts($contextlist->get_contextids());
        validation_manager::anonymise_user((int) $contextlist->get_user()->id, array_keys($criteria));
    }

    /**
     * IED extension: anonymises the users as teachers or validators in the context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $criteria = array_keys(validation_manager::criteria_in_contexts([$userlist->get_context()->id]));
        foreach ($userlist->get_userids() as $userid) {
            validation_manager::anonymise_user((int) $userid, $criteria);
        }
    }

    /**
     * IED extension: definitions of the levels referenced by the history (upstream table, no API).
     *
     * @param array $history validation_history records
     * @return array levelid => definition
     */
    protected static function level_definitions(array $history): array {
        global $DB;
        $ids = [];
        foreach ($history as $record) {
            $ids[] = $record->get('teacherlevelid');
            $ids[] = $record->get('validatedlevelid');
        }
        $ids = array_unique(array_filter($ids));
        if (empty($ids)) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($ids);
        return $DB->get_records_select_menu('gradingform_rubric_ranges_l', "id $insql", $params, '', 'id, definition');
    }

    /**
     * IED extension: descriptions of the criteria referenced by the history (upstream table, no API).
     *
     * @param array $history validation_history records
     * @return array criterionid => description
     */
    protected static function criterion_descriptions(array $history): array {
        global $DB;
        $ids = array_unique(array_map(fn($record) => $record->get('criterionid'), $history));
        if (empty($ids)) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($ids);
        return $DB->get_records_select_menu('gradingform_rubric_ranges_c', "id $insql", $params, '', 'id, description');
    }
}
