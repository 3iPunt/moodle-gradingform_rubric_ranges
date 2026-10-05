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

namespace gradingform_rubric_ranges\persistent;

use core\persistent;

/**
 * Validation history of a criterion grade (table gradingform_rubric_ranges_h).
 *
 * Stores the original teacher filling replaced by a validator. validatorid and timevalidated are
 * business data kept apart from usermodified/timecreated, which change when a backup is restored.
 *
 * Data access only: business rules live in \gradingform_rubric_ranges\local\validation_manager.
 *
 * @package    gradingform_rubric_ranges
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class validation_history extends persistent {

    /** @var string Table name. */
    const TABLE = 'gradingform_rubric_ranges_h';

    /**
     * Return the definition of the properties of this model.
     *
     * @return array
     */
    protected static function define_properties() {
        return [
            'itemid' => [
                'type' => PARAM_INT,
            ],
            'instanceid' => [
                'type' => PARAM_INT,
            ],
            'criterionid' => [
                'type' => PARAM_INT,
            ],
            'teacherlevelid' => [
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'teachergrade' => [
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'teacherremark' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'teacherremarkformat' => [
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'teacherid' => [
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'validatedlevelid' => [
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'validatedgrade' => [
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'validatedremark' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'validatorid' => [
                'type' => PARAM_INT,
            ],
            'timevalidated' => [
                'type' => PARAM_INT,
            ],
        ];
    }
}
