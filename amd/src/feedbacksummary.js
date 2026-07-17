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
 * AMD module for the teacher-facing AI feedback progress widget.
 *
 * Polls the get_assignment_feedback_summary webservice every 10 seconds
 * and updates the widget with current counts and progress bars.
 * Stops polling when all feedback is completed or no tasks are pending.
 *
 * @module     assignfeedback_aif/feedbacksummary
 * @copyright  2026 ISB Bayern
 * @author     Andreas Wagner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Log from 'core/log';
import {get_string as getString} from 'core/str';

/** @var {number} POLL_INTERVAL_MS Polling interval in milliseconds. */
const POLL_INTERVAL_MS = 10000;

/**
 * Initialize the feedback summary widget.
 *
 * @param {number} assignmentId The assignment instance id.
 */
export const init = async(assignmentId) => {
    const widget = document.querySelector('[data-aif="summary-widget"]');
    if (!widget) {
        return;
    }

    // Load strings once.
    const strings = await loadStrings();

    // Initial update.
    const shouldContinue = await updateWidget(widget, assignmentId, strings);

    // Start polling only if there are pending tasks.
    if (shouldContinue) {
        startPolling(widget, assignmentId, strings);
    }
};

/**
 * Load all required language strings.
 *
 * @returns {object} Object with named string properties.
 */
const loadStrings = async() => {
    const keys = [
        'widgetcompleted',
        'widgetpending',
        'widgeterrors',
        'widgetnotstarted',
        'widgetcounts',
        'widgetsystemicerror',
        'widgetretryall',
    ];
    const values = await Promise.all(
        keys.map(key => getString(key, 'assignfeedback_aif'))
    );
    const result = {};
    keys.forEach((key, i) => {
        result[key] = values[i];
    });
    return result;
};

/**
 * Start periodic polling.
 *
 * @param {HTMLElement} widget The widget container.
 * @param {number} assignmentId The assignment ID.
 * @param {object} strings Localised strings.
 */
const startPolling = (widget, assignmentId, strings) => {
    const timer = setInterval(async() => {
        const shouldContinue = await updateWidget(widget, assignmentId, strings);
        if (!shouldContinue) {
            clearInterval(timer);
        }
    }, POLL_INTERVAL_MS);
};

/**
 * Fetch summary data and update the widget DOM.
 *
 * @param {HTMLElement} widget The widget container.
 * @param {number} assignmentId The assignment ID.
 * @param {object} strings Localised strings.
 * @returns {boolean} True if polling should continue.
 */
const updateWidget = async(widget, assignmentId, strings) => {
    try {
        const data = await Ajax.call([{
            methodname: 'assignfeedback_aif_get_assignment_feedback_summary',
            args: {assignmentid: assignmentId},
        }])[0];

        if (data.totalsubmissions === 0) {
            widget.style.display = 'none';
            return false;
        }

        widget.style.display = '';
        const total = data.totalsubmissions;

        // Update progress bars.
        const barCompleted = widget.querySelector('[data-aif="bar-completed"]');
        const barPending = widget.querySelector('[data-aif="bar-pending"]');
        const barErrors = widget.querySelector('[data-aif="bar-errors"]');

        if (barCompleted) {
            barCompleted.style.width = (data.completed / total * 100).toFixed(1) + '%';
        }
        if (barPending) {
            const pendingWidth = ((data.pending + data.notstarted) / total * 100).toFixed(1);
            barPending.style.width = pendingWidth + '%';
        }
        if (barErrors) {
            barErrors.style.width = (data.errors / total * 100).toFixed(1) + '%';
        }

        // Update counts badge.
        const countsEl = widget.querySelector('[data-aif="summary-counts"]');
        if (countsEl) {
            countsEl.textContent = data.completed + ' / ' + total;
        }

        // Update detail text.
        const detailsEl = widget.querySelector('[data-aif="summary-details"]');
        if (detailsEl) {
            const parts = [];
            if (data.pending > 0 || data.notstarted > 0 || data.haspending) {
                parts.push('⏳ ' + (data.pending + data.notstarted) + ' ' + strings.widgetpending);
            }
            if (data.errors > 0) {
                parts.push('❌ ' + data.errors + ' ' + strings.widgeterrors);
            }
            if (data.completed > 0) {
                parts.push('✅ ' + data.completed + ' ' + strings.widgetcompleted);
            }
            detailsEl.innerHTML = parts.map(p => '<span class="mr-3">' + p + '</span>').join('');
        }

        // Update systemic errors.
        const errorContainer = widget.querySelector('[data-aif="summary-systemic-errors"]');
        if (errorContainer) {
            if (data.systemicerrors && data.systemicerrors.length > 0) {
                let html = '';
                data.systemicerrors.forEach(err => {
                    html += '<div class="alert alert-warning py-1 px-2 mt-1 mb-0 small">'
                        + '⚠️ ' + escapeHtml(err.message)
                        + ' <span class="text-muted">('
                        + strings.widgetsystemicerror.replace('{$a}', err.count)
                        + ')</span>'
                        + '</div>';
                });
                errorContainer.innerHTML = html;
            } else {
                errorContainer.innerHTML = '';
            }
        }

        // Continue polling if there are pending tasks or in-progress items.
        return data.haspending || data.pending > 0 || data.notstarted > 0;
    } catch (error) {
        Log.debug('assignfeedback_aif/feedbacksummary: poll error.');
        Log.debug(error);
        return false;
    }
};

/**
 * Escape HTML special characters to prevent XSS.
 *
 * @param {string} text Raw text.
 * @returns {string} Escaped text safe for innerHTML.
 */
const escapeHtml = (text) => {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(text));
    return div.innerHTML;
};

