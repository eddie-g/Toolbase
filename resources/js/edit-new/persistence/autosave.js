// Debounced auto-save scheduler used by the edit-new IIFE. Owns the timer
// and the schedule/trigger/flush/cancel surface; the rest of the editor
// supplies the dependencies (when to save, whether a save is already in
// flight, and how to actually run the save) via callbacks so this module
// stays free of editor-specific state.

const DEFAULT_DEBOUNCE_MS = 800;

/**
 * @param {object} opts
 * @param {() => boolean} opts.shouldSave  True when there are pending changes worth saving.
 * @param {() => boolean} opts.isBusy      True when a save is already in flight.
 * @param {() => Promise<unknown>} opts.runSave  Performs the actual save POST.
 * @param {number} [opts.debounceMs]
 * @returns {{
 *   schedule: () => void,
 *   trigger: () => void,
 *   flushIfPending: () => void,
 *   cancel: () => void,
 * }}
 */
export function createAutoSave({
    shouldSave,
    isBusy,
    runSave,
    debounceMs = DEFAULT_DEBOUNCE_MS,
}) {
    let timer = null;

    function schedule() {
        if (timer) clearTimeout(timer);
        timer = setTimeout(() => {
            timer = null;
            trigger();
        }, debounceMs);
    }

    function trigger() {
        if (!shouldSave()) return;
        if (isBusy()) {
            // A save is already running — reschedule once it should finish so
            // edits made during the save are not dropped.
            schedule();
            return;
        }
        // runSave is expected to surface its own error reporting.
        runSave().catch(() => {});
    }

    function flushIfPending() {
        if (timer) {
            clearTimeout(timer);
            timer = null;
            trigger();
        }
    }

    function cancel() {
        if (timer) {
            clearTimeout(timer);
            timer = null;
        }
    }

    return { schedule, trigger, flushIfPending, cancel };
}
