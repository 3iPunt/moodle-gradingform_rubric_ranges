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
 * Site settings page of the IED extension.
 *
 * Core does not load settings.php for gradingform plugins, so the feature flags are managed here.
 *
 * @package    gradingform_rubric_ranges
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');

use gradingform_rubric_ranges\form\settings_form;
use gradingform_rubric_ranges\local\features;

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$url = new moodle_url('/grade/grading/form/rubric_ranges/settings_page.php');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('settingspage', 'gradingform_rubric_ranges'));
$PAGE->set_heading(get_string('settingspage', 'gradingform_rubric_ranges'));

$form = new settings_form($url);

$current = [];
foreach (features::all() as $name) {
    $current[$name] = (int) features::is_enabled($name);
}
$form->set_data($current);

if ($data = $form->get_data()) {
    $forced = $CFG->forced_plugin_settings[features::COMPONENT] ?? [];
    foreach (features::all() as $name) {
        if (array_key_exists($name, $forced) || !isset($data->$name)) {
            continue;
        }
        $new = (int) !empty($data->$name);
        if ($new !== $current[$name]) {
            add_to_config_log($name, $current[$name], $new, features::COMPONENT);
            set_config($name, $new, features::COMPONENT);
        }
    }
    redirect($url, get_string('settingssaved', 'gradingform_rubric_ranges'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
$form->display();
echo $OUTPUT->footer();
