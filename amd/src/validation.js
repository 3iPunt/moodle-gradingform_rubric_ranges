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
 * IED extension: "Validate" button of the academic validation.
 *
 * The teacher grade is shown read only. Clicking the button shows the editable rubric and flags the
 * submission as a validation. The server ignores any submission without the flag.
 *
 * @module     gradingform_rubric_ranges/validation
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Initialises the validate button of a grading element.
 *
 * @param {String} name the grading element name
 */
export const init = (name) => {
    const button = document.getElementById(`${name}-validate-button`);
    const review = document.getElementById(`${name}-validation-review`);
    const editable = document.getElementById(`${name}-validation-editable`);
    const flag = document.getElementById(`${name}-validate`);
    if (!button || !review || !editable || !flag) {
        return;
    }

    button.addEventListener('click', () => {
        review.hidden = true;
        button.hidden = true;
        editable.hidden = false;
        flag.value = '1';
        editable.querySelector('input:not([type=hidden]), textarea')?.focus();
    });
};
