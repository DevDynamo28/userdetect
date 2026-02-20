/**
 * Signal collection module.
 * Gathers browser and device signals for the detection API.
 */

const REGIONAL_LANGUAGE_CODES = new Set([
    'as', 'bn', 'bho', 'doi', 'gu', 'hi', 'kn', 'kok', 'mai', 'ml',
    'mni', 'mr', 'ne', 'or', 'pa', 'sat', 'sd', 'ta', 'te', 'ur',
]);

const REGIONAL_FONT_CANDIDATES = [
    'Shruti',
    'Lohit Gujarati',
    'Noto Sans Gujarati',
    'Gujarati Sangam MN',
    'Lohit Tamil',
    'Noto Sans Tamil',
    'Tamil Sangam MN',
    'InaiMathi',
    'Lohit Telugu',
    'Noto Sans Telugu',
    'Telugu Sangam MN',
    'Vrinda',
    'Lohit Bengali',
    'Noto Sans Bengali',
    'Bangla Sangam MN',
    'Tunga',
    'Lohit Kannada',
    'Noto Sans Kannada',
    'Kannada Sangam MN',
    'Kartika',
    'Lohit Malayalam',
    'Noto Sans Malayalam',
    'Malayalam Sangam MN',
    'Raavi',
    'Lohit Punjabi',
    'Noto Sans Gurmukhi',
    'Gurmukhi Sangam MN',
    'Kalinga',
    'Lohit Odia',
    'Noto Sans Oriya',
    'Oriya Sangam MN',
];

/**
 * Collect all available browser signals.
 * @param {string} fingerprint - The generated fingerprint hash.
 * @returns {object} Signals object matching the API spec.
 */
export function collectSignals(fingerprint) {
    const languages = Array.from(navigator.languages || []);

    return {
        fingerprint: fingerprint,
        timezone: getTimezone(),
        timezone_offset: new Date().getTimezoneOffset(),
        language: navigator.language || null,
        languages: languages,
        language_analysis: buildLanguageAnalysis(languages),
        regional_fonts: detectRegionalFonts(),
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

function buildLanguageAnalysis(languages) {
    if (!Array.isArray(languages) || languages.length === 0) {
        return null;
    }

    const regional = languages
        .map((entry, index) => {
            const rawCode = (entry || '').toString().trim();
            const baseCode = rawCode.split('-')[0].toLowerCase();

            if (!REGIONAL_LANGUAGE_CODES.has(baseCode)) {
                return null;
            }

            return {
                code: rawCode || baseCode,
                language: baseCode,
                position: index,
            };
        })
        .filter(Boolean);

    if (regional.length === 0) {
        return null;
    }

    return {
        regional: regional,
        total_languages: languages.length,
    };
}

function detectRegionalFonts() {
    if (typeof document === 'undefined' || !document.body) {
        return [];
    }

    const baseFonts = ['monospace', 'sans-serif', 'serif'];
    const testString = 'mmmmmmmmlli';
    const testSize = '72px';

    const span = document.createElement('span');
    span.style.fontSize = testSize;
    span.style.position = 'absolute';
    span.style.left = '-9999px';
    span.textContent = testString;
    document.body.appendChild(span);

    const baseWidths = {};
    baseFonts.forEach((font) => {
        span.style.fontFamily = font;
        baseWidths[font] = span.offsetWidth;
    });

    const detected = [];
    REGIONAL_FONT_CANDIDATES.forEach((font) => {
        const isPresent = baseFonts.some((baseFont) => {
            span.style.fontFamily = `'${font}', ${baseFont}`;
            return span.offsetWidth !== baseWidths[baseFont];
        });

        if (isPresent) {
            detected.push(font);
        }
    });

    document.body.removeChild(span);
    return detected;
}
