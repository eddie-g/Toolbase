/*
 * Font-size slider mapping (Phase 7ad).
 *
 * The format-bar font-size slider is 0–100 with the midpoint mapped
 * to 12pt via an exponential curve between 2pt (slider=0) and 72pt
 * (slider=100):
 *     pt = 2 * (72/2)^(slider/100)  →  slider=50 → pt = sqrt(2*72) = 12.
 *
 * Pure math; no editor state dependencies.
 */

export const FONT_SLIDER_MIN_PT = 2;
export const FONT_SLIDER_MAX_PT = 72;

export function sliderValueToFontPt(value) {
    const t = Math.min(1, Math.max(0, Number(value) / 100));
    const ratio = FONT_SLIDER_MAX_PT / FONT_SLIDER_MIN_PT;
    const pt = FONT_SLIDER_MIN_PT * Math.pow(ratio, t);
    return Math.max(FONT_SLIDER_MIN_PT, Math.min(FONT_SLIDER_MAX_PT, Math.round(pt)));
}

export function fontPtToSliderValue(pt) {
    const clamped = Math.max(FONT_SLIDER_MIN_PT, Math.min(FONT_SLIDER_MAX_PT, Number(pt) || 12));
    const ratio = FONT_SLIDER_MAX_PT / FONT_SLIDER_MIN_PT;
    const t = Math.log(clamped / FONT_SLIDER_MIN_PT) / Math.log(ratio);
    return Math.round(Math.min(100, Math.max(0, t * 100)));
}
