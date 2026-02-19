/**
 * Signal collection module.
 * Gathers browser and device signals for the detection API.
 */

/**
 * Collect all available browser signals.
 * @param {string} fingerprint - The generated fingerprint hash.
 * @returns {object} Signals object matching the API spec.
 */
export function collectSignals(fingerprint) {
    return {
        fingerprint: fingerprint,
        timezone: getTimezone(),
        timezone_offset: new Date().getTimezoneOffset(),
        language: navigator.language || null,
        languages: Array.from(navigator.languages || []),
        user_agent: navigator.userAgent,
        screen: {
            width: screen.width,
            height: screen.height,
            color_depth: screen.colorDepth,
            pixel_ratio: window.devicePixelRatio || 1,
        },
        platform: navigator.platform || null,
        hardware_concurrency: navigator.hardwareConcurrency || null,
        device_memory: navigator.deviceMemory || null,
    };
}

/**
 * Get the timezone name.
 */
function getTimezone() {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone;
    } catch (e) {
        return null;
    }
}
