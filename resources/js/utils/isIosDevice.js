/**
 * True for iPhone / iPad / iPod browsers. Includes iPadOS 13+ “Request Desktop Website”
 * (Safari reports MacIntel but exposes touch points). Mac Safari is NOT iOS — Apple Pay
 * on desktop is out of scope for “só dispositivos iOS” in our checkout UI.
 *
 * @returns {boolean}
 */
export function isIosDevice() {
    if (typeof navigator === 'undefined') {
        return false;
    }
    const ua = navigator.userAgent || '';
    if (/iPhone|iPod|iPad/i.test(ua)) {
        return true;
    }
    if (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1) {
        return true;
    }
    return false;
}
