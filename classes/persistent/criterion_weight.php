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
 * Weight of a rubric criterion (table gradingform_rubric_ranges_w).
 *
 * Data access only: business rules live in \gradingform_rubric_ranges\local\weights.
 *
 * @package    gradingform_rubric_ranges
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class criterion_weight extends persistent {

    /** @var string Table name. */
    const TABLE = 'gradingform_rubric_ranges_w';

    /**
     * Return the definition of the properties of this model.
     *
     * @return array
     */
    protected static function define_properties() {
        return [
            'criterionid' => [
                'type' => PARAM_INT,
            ],
            'weight' => [
                'type' => PARAM_INT,
                'default' => 1,
            ],
        ];
    }
}
