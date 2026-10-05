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
 * gradingform_rubric_ranges plugin upgrade code.
 *
 * @package    gradingform_rubric_ranges
 * @author     Tomo Tsuyuki <tomotsuyuki@catalyst-au.net>
 * @copyright  2023 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion
 * @return bool always true
 */
function xmldb_gradingform_rubric_ranges_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2023071200) {
        // Rename table for gform_rubric_ranges_criteria.
        $table = new xmldb_table('gform_rubric_ranges_criteria');
        // Conditionally launch rename table for gform_rubric_ranges_criteria.
        if ($dbman->table_exists($table)) {
            $dbman->rename_table($table, 'gradingform_rubric_ranges_c');
        }

        // Rename table for gform_rubric_ranges_levels.
        $table = new xmldb_table('gform_rubric_ranges_levels');
        // Conditionally launch rename table for gform_rubric_ranges_levels.
        if ($dbman->table_exists($table)) {
            $dbman->rename_table($table, 'gradingform_rubric_ranges_l');
        }

        // Rename table for gform_rubric_ranges_fillings.
        $table = new xmldb_table('gform_rubric_ranges_fillings');
        // Conditionally launch rename table for gform_rubric_ranges_fillings.
        if ($dbman->table_exists($table)) {
            $dbman->rename_table($table, 'gradingform_rubric_ranges_f');
        }

        // Coursemigration savepoint reached.
        upgrade_plugin_savepoint(true, 2023071200, 'gradingform', 'rubric_ranges');
    }

    // IED extension: criteria weights and validation history.
    if ($oldversion < 2024112201) {
        // Define table gradingform_rubric_ranges_w to be created.
        $table = new xmldb_table('gradingform_rubric_ranges_w');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('criterionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('weight', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_criterionid', XMLDB_KEY_FOREIGN_UNIQUE, ['criterionid'], 'gradingform_rubric_ranges_c', ['id']);
        $table->add_key('fk_usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table gradingform_rubric_ranges_h to be created.
        $table = new xmldb_table('gradingform_rubric_ranges_h');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('itemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('instanceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('criterionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('teacherlevelid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('teachergrade', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('teacherremark', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('teacherremarkformat', XMLDB_TYPE_INTEGER, '2', null, null, null, null);
        $table->add_field('teacherid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('validatedlevelid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('validatedgrade', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('validatedremark', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('validatorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timevalidated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
        $table->add_key('fk_instanceid', XMLDB_KEY_FOREIGN, ['instanceid'], 'grading_instances', ['id']);
        $table->add_key('fk_criterionid', XMLDB_KEY_FOREIGN, ['criterionid'], 'gradingform_rubric_ranges_c', ['id']);
        $table->add_key('fk_teacherid', XMLDB_KEY_FOREIGN, ['teacherid'], 'user', ['id']);
        $table->add_key('fk_validatorid', XMLDB_KEY_FOREIGN, ['validatorid'], 'user', ['id']);
        $table->add_index('uq_itemid_criterionid', XMLDB_INDEX_UNIQUE, ['itemid', 'criterionid']);
        $table->add_index('ix_teacherlevelid', XMLDB_INDEX_NOTUNIQUE, ['teacherlevelid']);
        $table->add_index('ix_validatedlevelid', XMLDB_INDEX_NOTUNIQUE, ['validatedlevelid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Feature flags are disabled by default.
        \gradingform_rubric_ranges\local\features::set_defaults();

        upgrade_plugin_savepoint(true, 2024112201, 'gradingform', 'rubric_ranges');
    }

    return true;
}
