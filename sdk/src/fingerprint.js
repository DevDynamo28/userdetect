/**
 * Browser fingerprint generation module.
 * Generates a unique 64-character hex hash from multiple browser signals.
 */

const CACHE_KEY = '__ud_fp';

/**
 * Generate a SHA-256 hash of the given string.
 */
async function sha256(str) {
    const buffer = new TextEncoder().encode(str);
    const hashBuffer = await crypto.subtle.digest('SHA-256', buffer);
    const hashArray = Array.from(new Uint8Array(hashBuffer));
    return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
}

/**
 * Get canvas fingerprint.
 */
function getCanvasFingerprint() {
    try {
        const canvas = document.createElement('canvas');
        canvas.width = 200;
        canvas.height = 50;
        const ctx = canvas.getContext('2d');

        ctx.textBaseline = 'top';
        ctx.font = '14px Arial';
        ctx.fillStyle = '#f60';
        ctx.fillRect(125, 1, 62, 20);
        ctx.fillStyle = '#069';
        ctx.fillText('UserDetect,fp!', 2, 15);
        ctx.fillStyle = 'rgba(102, 204, 0, 0.7)';
        ctx.fillText('UserDetect,fp!', 4, 17);

        // Add some shapes
        ctx.beginPath();
        ctx.arc(50, 25, 10, 0, Math.PI * 2);
        ctx.fillStyle = '#f0f';
        ctx.fill();

        return canvas.toDataURL();
    } catch (e) {
        return '';
    }
}

/**
 * Get WebGL fingerprint.
 */
function getWebGLFingerprint() {
    try {
        const canvas = document.createElement('canvas');
        const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
        if (!gl) return '';

        const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
        const vendor = debugInfo ? gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL) : '';
        const renderer = debugInfo ? gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL) : '';

        return `${vendor}~${renderer}`;
    } catch (e) {
        return '';
    }
}

/**
 * Get audio fingerprint.
 */
function getAudioFingerprint() {
    try {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        if (!AudioContext) return '';

        const context = new AudioContext();
        const oscillator = context.createOscillator();
        const analyser = context.createAnalyser();
        const gain = context.createGain();
        const processor = context.createScriptProcessor(4096, 1, 1);

        oscillator.type = 'triangle';
        oscillator.frequency.setValueAtTime(10000, context.currentTime);
        gain.gain.setValueAtTime(0, context.currentTime);

        oscillator.connect(analyser);
        analyser.connect(processor);
        processor.connect(gain);
        gain.connect(context.destination);

        const data = new Float32Array(analyser.frequencyBinCount);
        analyser.getFloatFrequencyData(data);

        context.close();

        return data.slice(0, 30).join(',');
    } catch (e) {
        return '';
    }
}

/**
 * Detect available fonts by measuring text width differences.
 */
function detectFonts() {
    const baseFonts = ['monospace', 'sans-serif', 'serif'];
    const testFonts = [
        'Arial', 'Courier New', 'Georgia', 'Times New Roman', 'Trebuchet MS',
        'Verdana', 'Comic Sans MS', 'Impact', 'Lucida Console', 'Tahoma',
        'Palatino', 'Garamond', 'Bookman', 'Arial Black', 'Calibri',
    ];

    const testString = 'mmmmmmmmlli';
    const testSize = '72px';
    const body = document.body;

    const span = document.createElement('span');
    span.style.fontSize = testSize;
    span.style.position = 'absolute';
    span.style.left = '-9999px';
    span.textContent = testString;
    body.appendChild(span);

    const baseWidths = {};
    baseFonts.forEach(font => {
        span.style.fontFamily = font;
        baseWidths[font] = span.offsetWidth;
    });

    const detectedFonts = [];
    testFonts.forEach(font => {
        const detected = baseFonts.some(baseFont => {
            span.style.fontFamily = `'${font}', ${baseFont}`;
            return span.offsetWidth !== baseWidths[baseFont];
        });
        if (detected) {
            detectedFonts.push(font);
        }
    });

    body.removeChild(span);
    return detectedFonts.join(',');
}

/**
 * Generate a unique browser fingerprint.
 * Returns a 64-character hex string (SHA-256 hash).
 */
export async function generateFingerprint() {
    // Check cache first
    try {
        const cached = sessionStorage.getItem(CACHE_KEY);
        if (cached) return cached;
    } catch (e) {
        // sessionStorage not available
    }

    const components = [
        getCanvasFingerprint(),
        getWebGLFingerprint(),
        getAudioFingerprint(),
        detectFonts(),
        `${screen.width}x${screen.height}x${screen.colorDepth}x${window.devicePixelRatio || 1}`,
        Intl.DateTimeFormat().resolvedOptions().timeZone,
        navigator.language,
        navigator.platform,
        navigator.hardwareConcurrency || 0,
        navigator.deviceMemory || 0,
        navigator.maxTouchPoints || 0,
        new Date().getTimezoneOffset(),
    ];

    const raw = components.join('|||');
    const hash = await sha256(raw);

    // Cache the result
    try {
        sessionStorage.setItem(CACHE_KEY, hash);
    } catch (e) {
        // Ignore
    }

    return hash;
}
