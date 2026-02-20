/**
 * UserDetect SDK
 * Browser-based user location detection.
 *
 * Usage:
 *   UserDetect.init('api_key', callback, options);
 */

import { generateFingerprint } from './fingerprint.js';
import { collectSignals, probeNetwork } from './signals.js';
import { sendDetection } from './api-client.js';

// Internal state
let _apiKey = null;
let _options = {};
let _callback = null;
let _lastDetection = null;
let _fingerprint = null;
let _initialized = false;

/**
 * Default options.
 */
const DEFAULTS = {
    apiEndpoint: '',  // Will be auto-detected from script src if empty
    timeout: 10000,
    debug: false,
    autoDetect: true,
    returnAlternatives: false,
    onEvent: null,
};

/**
 * Initialize the SDK.
 * @param {string} apiKey - Client's API key.
 * @param {function} callback - Function called with detection result.
 * @param {object} [options] - Configuration options.
 */
function init(apiKey, callback, options) {
    if (!apiKey) {
        console.error('[UserDetect] API key is required.');
        return;
    }

    if (typeof callback !== 'function') {
        console.error('[UserDetect] Callback function is required.');
        return;
    }

    _apiKey = apiKey;
    _callback = callback;
    _options = Object.assign({}, DEFAULTS, options || {});
    _initialized = true;

    // Auto-detect API endpoint from script src
    if (!_options.apiEndpoint) {
        _options.apiEndpoint = detectApiEndpoint();
    }

    if (_options.debug) {
        console.log('[UserDetect] Initialized with options:', _options);
    }

    if (_options.autoDetect) {
        detect().catch(function(err) {
            const fallbackError = {
                success: false,
                error: {
                    code: 'SDK_ERROR',
                    message: (err && err.message) || 'Auto-detect failed.',
                },
            };

            _lastDetection = fallbackError;
            if (_callback) {
                _callback(fallbackError);
            }

            if (_options.debug) {
                console.error('[UserDetect] Auto-detect failed:', err);
            }
        });
    }
}

/**
 * Manually trigger detection.
 * @returns {Promise<object>} Detection result.
 */
async function detect() {
    if (!_initialized) {
        throw new Error('[UserDetect] SDK not initialized. Call UserDetect.init() first.');
    }

    emitEvent('detect_started', { timestamp: Date.now() });

    if (_options.debug) {
        console.log('[UserDetect] Starting detection...');
    }

    let result;
    try {
        // Generate fingerprint and run network probe in parallel.
        // probeNetwork() fetches CF's /cdn-cgi/trace from the browser to determine
        // which CF PoP the device's network path hits (independent of our API's PoP).
        // Running in parallel means the probe adds ZERO extra latency to detection.
        const [fingerprint, networkProbes] = await Promise.all([
            generateFingerprint(),
            probeNetwork().catch(() => null),
        ]);
        _fingerprint = fingerprint;

        if (_options.debug) {
            console.log('[UserDetect] Fingerprint:', _fingerprint);
            console.log('[UserDetect] Network probes:', networkProbes);
        }

        // Collect signals and attach probe data
        const signals = collectSignals(_fingerprint);
        if (networkProbes) {
            signals.network_probes = networkProbes;
        }

        if (_options.debug) {
            console.log('[UserDetect] Signals:', signals);
        }

        // Send to API
        result = await sendDetection(_apiKey, signals, _options);
    } catch (err) {
        result = {
            success: false,
            error: {
                code: 'SDK_ERROR',
                message: (err && err.message) || 'SDK detection failed before API request.',
            },
        };
    }

    // Cache result
    _lastDetection = result;

    emitEvent(result.success ? 'detect_succeeded' : 'detect_failed', {
        success: result.success,
        error: result.error || null,
        request_id: result.request_id || null,
    });

    if (_options.debug) {
        console.log('[UserDetect] Result:', result);
    }

    // Call callback
    if (_callback) {
        _callback(result);
    }

    return result;
}

/**
 * Get current user's fingerprint ID.
 * @returns {string|null} Fingerprint ID.
 */
function getUserId() {
    return _fingerprint;
}

/**
 * Get cached last detection result.
 * @returns {object|null} Detection data.
 */
function getLastDetection() {
    return _lastDetection;
}

/**
 * Auto-detect the API endpoint from the script's src URL.
 */
function detectApiEndpoint() {
    try {
        const scripts = document.getElementsByTagName('script');
        for (let i = scripts.length - 1; i >= 0; i--) {
            const src = scripts[i].src || '';
            if (src.includes('userdetect')) {
                const url = new URL(src);
                return url.origin + '/api';
            }
        }
    } catch (e) {
        // Ignore
    }

    // Fallback: use current page origin
    return window.location.origin + '/api';
}

function emitEvent(name, payload) {
    if (typeof _options.onEvent !== 'function') {
        return;
    }

    try {
        _options.onEvent({
            name: name,
            payload: payload || {},
        });
    } catch (_err) {
        // Never throw from SDK event hooks.
    }
}

// Public API
export { init, detect, getUserId, getLastDetection };
