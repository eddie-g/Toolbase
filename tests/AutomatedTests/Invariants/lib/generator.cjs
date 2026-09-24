/**
 * Seeded action-sequence generator. Steps are plain JSON so they can be
 * saved, shrunk and replayed. Targets are fractions (row, word) resolved
 * against the live block at execution time, so a step still means "the
 * same kind of place" after earlier steps were dropped by the shrinker.
 *
 * Step ops (executed with REAL mouse/keyboard input by session.cjs):
 *   enter      {via:'menu'|'click', at}      open the block for editing
 *   exit       {via:'outside'|'escape'}       leave edit (commit)
 *   openClose  {via}                          enter + exit without typing (invariant 1)
 *   click      {at}                           place the caret (enters edit first if needed)
 *   dblclick   {at}                           select a word
 *   dragSelect {from, to}                     mouse drag selection
 *   type       {text}                         one keystroke per character
 *   key        {key, n}                       Backspace/Delete/Enter/Home/End/Arrow*
 *   typeDelete {text}                         type then Backspace the same count (restore check)
 *   undo / redo                               Ctrl+Z / Ctrl+Y
 *   format     {control, value}               #afb-font/#afb-size/#afb-bold/#afb-italic/#afb-text-color
 *   cycles     {n}                            n open/commit cycles (invariant 5)
 *   move       {dx, dy}                       drag the move grip (invariant 6)
 *   reload                                    reload the editor (invariant 8)
 *   download                                  Download PDF + compare with untouched download (invariant 7)
 */
const { rng } = require('./prng.cjs');

const TARGET_KINDS = ['wordStart', 'wordEnd', 'between', 'afterMarker', 'rowEnd', 'hanging', 'mid'];
const LETTERS = 'abcdefghijklmnopqrstuvwxyzAEIOU';
const FONTS = ['Helvetica', 'Arial', 'Georgia', 'TimesRoman', 'Courier', 'Verdana'];
const COLORS = ['#c0392b', '#1f6feb', '#2e7d32', '#000000'];

function target(r) {
    return { kind: r.pick(TARGET_KINDS), row: r.frac(), word: r.frac() };
}

function generate(seed, count) {
    const r = rng(seed, 2);
    const steps = [];
    let editing = false;
    let dirtyStyle = false;
    const push = (s) => { steps.push(s); };
    // Always start with an open/close (the most common user action).
    push({ op: 'openClose', via: r.pick(['menu', 'click']) });
    while (steps.length < count) {
        if (!editing) {
            const op = r.weighted([
                ['enter', 6], ['openClose', 1.5], ['move', 1], ['reload', 0.7], ['download', 0.5], ['cycles', dirtyStyle ? 3 : 0.5],
            ]);
            if (op === 'enter') { push({ op, via: r.pick(['menu', 'click', 'click']), at: target(r) }); editing = true; }
            else if (op === 'openClose') push({ op, via: r.pick(['menu', 'click']) });
            else if (op === 'move') push({ op, dx: r.pick([-1, 1]) * r.int(24, 90), dy: r.pick([-1, 1]) * r.int(16, 60) });
            else if (op === 'cycles') { push({ op, n: 4 }); dirtyStyle = false; }
            else push({ op });
            continue;
        }
        const op = r.weighted([
            ['click', 3], ['type', 5], ['key', 3], ['typeDelete', 2], ['dblclick', 1], ['dragSelect', 1],
            ['undo', 0.7], ['redo', 0.3], ['format', 1.2], ['exit', 2],
        ]);
        switch (op) {
            case 'click': push({ op, at: target(r) }); break;
            case 'dblclick': push({ op, at: target(r) }); break;
            case 'dragSelect': push({ op, from: target(r), to: target(r) }); break;
            case 'type': {
                const n = r.weighted([[1, 5], [2, 2], [3, 1]]);
                let text = '';
                for (let i = 0; i < n; i += 1) text += r.chance(0.12) ? ' ' : r.pick(LETTERS.split(''));
                push({ op, text });
                break;
            }
            case 'key': push({ op, key: r.weighted([['Backspace', 4], ['Delete', 3], ['Enter', 1], ['Home', 1], ['End', 1], ['ArrowLeft', 1], ['ArrowRight', 1], ['ArrowUp', 0.5], ['ArrowDown', 0.5]]), n: r.weighted([[1, 5], [2, 2], [3, 1]]) }); break;
            case 'typeDelete': push({ op, text: r.pick(['x', 'ab', 'xyz', 'q q']) }); break;
            case 'format': {
                const control = r.weighted([['font', 2], ['size', 2], ['bold', 1], ['italic', 1], ['color', 1]]);
                const value = control === 'font' ? r.pick(FONTS) : control === 'size' ? r.frac() : control === 'color' ? r.pick(COLORS) : null;
                push({ op, control, value });
                if (control === 'font' || control === 'size') dirtyStyle = true;
                break;
            }
            case 'exit': push({ op, via: r.pick(['outside', 'outside', 'escape']) }); editing = false; break;
            default: push({ op });
        }
    }
    // A sequence that changed the block ends with a download: style and
    // row fidelity (I7) is only visible in the exported PDF.
    const changed = steps.some((st) => st.op === 'type' || st.op === 'format' || (st.op === 'key' && st.key === 'Enter'));
    if (changed && steps[steps.length - 1].op !== 'download') {
        if (editing) push({ op: 'exit', via: 'outside' });
        push({ op: 'download' });
    }
    return steps;
}

/** Block choice for a seed: a page with text, then one box on it (promoted blocks weighted up). */
function blockChoice(seed) {
    const r = rng(seed, 1);
    return { pageFrac: r.frac(), boxFrac: r.frac(), preferPromoted: r.chance(0.7) };
}

module.exports = { generate, blockChoice, TARGET_KINDS };
