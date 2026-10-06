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
 * IED extension: numeric grade per criterion with automatic level and feedback.
 *
 * Works next to the upstream YUI module (js/rubric_ranges.js) without modifying it. The YUI level
 * click handler is attached to each level cell, so it runs before the delegated handlers below.
 * The server always recalculates the level from the grade, this module only gives instant feedback.
 *
 * @module     gradingform_rubric_ranges/numeric_grading
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTORS = {
    CRITERION: 'tr.criterion',
    LEVEL: 'td.level',
    GRADE: 'input.gradeinput',
    REMARK: '.remark textarea',
    RADIO: 'input[type=radio]',
};

/**
 * Returns the level cell whose range contains the grade.
 *
 * @param {HTMLElement} criterion
 * @param {Number} grade
 * @returns {HTMLElement|null}
 */
const findLevel = (criterion, grade) => {
    return Array.from(criterion.querySelectorAll(SELECTORS.LEVEL)).find((level) => {
        return grade >= Number(level.dataset.rangemin) && grade <= Number(level.dataset.rangemax);
    }) || null;
};

/**
 * Writes the definition of the level as feedback, unless the grader has edited the feedback.
 *
 * @param {HTMLElement} criterion
 * @param {HTMLElement|null} level
 */
const applyFeedback = (criterion, level) => {
    const remark = criterion.querySelector(SELECTORS.REMARK);
    if (!remark || !level || level.dataset.definition === undefined) {
        return;
    }
    if (remark.value === '' || remark.value === remark.dataset.autofeedback) {
        remark.value = level.dataset.definition;
        remark.dataset.autofeedback = level.dataset.definition;
    }
};

/**
 * Marks a level of a criterion as selected (or none), as the upstream YUI module does on click.
 *
 * @param {HTMLElement} criterion
 * @param {HTMLElement|null} selected
 */
const markLevel = (criterion, selected) => {
    criterion.querySelectorAll(SELECTORS.LEVEL).forEach((level) => {
        const checked = level === selected;
        level.classList.toggle('checked', checked);
        level.setAttribute('aria-checked', checked ? 'true' : 'false');
        const radio = level.querySelector(SELECTORS.RADIO);
        if (radio) {
            radio.checked = checked;
        }
    });
    applyFeedback(criterion, selected);
};

/**
 * Handles a grade typed by the grader.
 *
 * @param {HTMLInputElement} input
 */
const gradeChanged = (input) => {
    const criterion = input.closest(SELECTORS.CRITERION);
    const value = input.value.trim();
    if (value === '') {
        input.classList.remove('is-invalid');
        markLevel(criterion, null);
        return;
    }
    const grade = Number(value);
    const level = Number.isInteger(grade) ? findLevel(criterion, grade) : null;
    input.classList.toggle('is-invalid', level === null);
    markLevel(criterion, level);
};

/**
 * Handles a level selected or unselected by the grader (after the YUI handler).
 *
 * @param {HTMLElement} level
 */
const levelChanged = (level) => {
    const criterion = level.closest(SELECTORS.CRITERION);
    const input = criterion?.querySelector(SELECTORS.GRADE);
    if (!input) {
        return;
    }
    input.classList.remove('is-invalid');
    if (level.classList.contains('checked')) {
        input.value = level.dataset.rangemin;
        applyFeedback(criterion, level);
    } else {
        input.value = '';
    }
};

/**
 * Initialises the numeric grading of a rubric.
 *
 * @param {String} name the grading element name
 */
export const init = (name) => {
    const root = document.getElementById(`rubric-${name}`);
    if (!root) {
        return;
    }

    // A remark equal to the definition of the selected level is considered automatic feedback.
    root.querySelectorAll(SELECTORS.CRITERION).forEach((criterion) => {
        const remark = criterion.querySelector(SELECTORS.REMARK);
        const checked = Array.from(criterion.querySelectorAll(SELECTORS.LEVEL))
            .find((level) => level.querySelector(`${SELECTORS.RADIO}:checked`));
        if (remark && checked && remark.value === checked.dataset.definition) {
            remark.dataset.autofeedback = remark.value;
        }
    });

    root.addEventListener('input', (e) => {
        if (e.target.matches(SELECTORS.GRADE)) {
            gradeChanged(e.target);
        }
    });

    root.addEventListener('click', (e) => {
        const level = e.target.closest(SELECTORS.LEVEL);
        if (level && root.contains(level)) {
            levelChanged(level);
        }
    });

    root.addEventListener('keyup', (e) => {
        if (e.key !== 'Enter' && e.key !== ' ') {
            return;
        }
        const level = e.target.closest(SELECTORS.LEVEL);
        if (level && root.contains(level)) {
            levelChanged(level);
        }
    });
};
