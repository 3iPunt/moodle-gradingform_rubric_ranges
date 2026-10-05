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

namespace gradingform_rubric_ranges\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use gradingform_rubric_ranges\local\features;

/**
 * Site settings form of the IED extension.
 *
 * @package    gradingform_rubric_ranges
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings_form extends \moodleform {

    /**
     * Form definition.
     */
    protected function definition() {
        global $CFG;

        $mform = $this->_form;
        $forced = $CFG->forced_plugin_settings[features::COMPONENT] ?? [];

        foreach (features::all() as $name) {
            $mform->addElement('advcheckbox', $name, get_string($name, 'gradingform_rubric_ranges'));
            $mform->addHelpButton($name, $name, 'gradingform_rubric_ranges');
            $mform->setType($name, PARAM_BOOL);
            if (array_key_exists($name, $forced)) {
                $mform->freeze($name);
                $mform->addElement('static', $name . '_forced', '', get_string('settingforced', 'gradingform_rubric_ranges'));
            }
        }

        $this->add_action_buttons(false);
    }
}
