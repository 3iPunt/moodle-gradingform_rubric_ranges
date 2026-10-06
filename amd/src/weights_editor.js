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
 * IED extension: live weight percentages in the ranged rubric editor.
 *
 * Works next to the upstream YUI editor (js/rubricrangeseditor.js) without modifying it:
 * criteria added, duplicated or deleted by YUI are detected with a MutationObserver.
 *
 * @module     gradingform_rubric_ranges/weights_editor
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getStrings} from 'core/str';

const SELECTORS = {
    WEIGHT: 'select.weightselect',
    PERCENT: '.weightpercent',
    CRITERION: 'tr.criterion',
};

/** @type {string} Template of the percentage string, with a placeholder for the value. */
let percentTemplate = '{$a} %';

/** @type {string} Decimal separator of the current language. */
let decimalSeparator = '.';

/**
 * Formats a percentage with one decimal, removing trailing zeros.
 *
 * @param {Number} value
 * @returns {String}
 */
const formatPercent = (value) => {
    const formatted = String(Math.round(value * 10) / 10).replace('.', decimalSeparator);
    return percentTemplate.replace('{$a}', formatted);
};

/**
 * Recalculates the percentage of every criterion of the rubric.
 *
 * @param {HTMLElement} root the rubric editor container
 */
const recalculate = (root) => {
    const selects = Array.from(root.querySelectorAll(SELECTORS.WEIGHT));
    const total = selects.reduce((sum, select) => sum + (parseInt(select.value, 10) || 0), 0);
    selects.forEach((select) => {
        const percent = select.closest('.weight')?.querySelector(SELECTORS.PERCENT);
        if (percent) {
            const weight = parseInt(select.value, 10) || 0;
            const text = total ? formatPercent(weight / total * 100) : '';
            // Only write when changed: writing always would retrigger the MutationObserver endlessly.
            if (percent.textContent !== text) {
                percent.textContent = text;
            }
        }
    });
};

/**
 * Copies the weight of the duplicated criterion to the new one (YUI creates it with the default weight).
 *
 * The YUI handler is attached to the button itself, so when the click bubbles here the new row already exists.
 *
 * @param {HTMLElement} root the rubric editor container
 * @param {HTMLElement} button the duplicate button
 */
const copyDuplicatedWeight = (root, button) => {
    const source = button.closest(SELECTORS.CRITERION)?.querySelector(SELECTORS.WEIGHT);
    const criteria = root.querySelectorAll(SELECTORS.CRITERION);
    const target = criteria.length ? criteria[criteria.length - 1].querySelector(SELECTORS.WEIGHT) : null;
    if (source && target && source !== target) {
        target.value = source.value;
    }
};

/**
 * Initialises the weights editor.
 *
 * @param {String} name the rubric editor element name
 */
export const init = async(name) => {
    const root = document.getElementById(`rubric-${name}`);
    if (!root) {
        return;
    }

    try {
        [percentTemplate, decimalSeparator] = await getStrings([
            {key: 'weightpercent', component: 'gradingform_rubric_ranges', param: '{$a}'},
            {key: 'decsep', component: 'langconfig'},
        ]);
    } catch (e) {
        // Keep the defaults.
    }

    root.addEventListener('change', (e) => {
        if (e.target.matches(SELECTORS.WEIGHT)) {
            recalculate(root);
        }
    });

    root.addEventListener('click', (e) => {
        if (e.target.matches('input[type=submit][id$="-duplicate"]')) {
            copyDuplicatedWeight(root, e.target);
            recalculate(root);
        }
    });

    // Criteria added or deleted by the YUI editor (deletion happens after a confirmation dialog).
    let scheduled = false;
    const observer = new MutationObserver(() => {
        if (!scheduled) {
            scheduled = true;
            window.requestAnimationFrame(() => {
                scheduled = false;
                recalculate(root);
            });
        }
    });
    observer.observe(root, {childList: true, subtree: true});

    recalculate(root);
};
