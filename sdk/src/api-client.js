/**
 * API communication module.
 * Handles sending detection requests and receiving responses.
 */

/**
 * Send detection request to the API.
 * @param {string} apiKey - Client's API key.
 * @param {object} signals - Collected browser signals.
 * @param {object} options - SDK options.
 * @returns {Promise<object>} Detection result.
 */
export async function sendDetection(apiKey, signals, options) {
    const endpoint = (options.apiEndpoint || '').replace(/\/$/, '') + '/v1/detect';
    const timeout = options.timeout || 10000;

    const body = JSON.stringify({
        signals: signals,
        options: {
            return_alternatives: options.returnAlternatives || false,
            include_debug_info: options.debug || false,
        },
    });

    const headers = {
        'Content-Type': 'application/json',
        'X-API-Key': apiKey,
    };

    // First attempt
    try {
        return await makeRequest(endpoint, headers, body, timeout);
    } catch (firstError) {
        if (options.debug) {
            console.warn('[UserDetect] First attempt failed, retrying...', firstError.message);
        }

        // Retry once after 1 second
        await sleep(1000);

        try {
            return await makeRequest(endpoint, headers, body, timeout);
        } catch (retryError) {
            return {
                success: false,
                error: {
                    code: retryError.code || 'NETWORK_ERROR',
                    message: retryError.message || 'Failed to connect to detection API.',
                },
                request_id: retryError.request_id || null,
            };
        }
    }
}

/**
 * Make an HTTP request with timeout support.
 */
async function makeRequest(url, headers, body, timeout) {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), timeout);

    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: headers,
            body: body,
            signal: controller.signal,
        });

        clearTimeout(timeoutId);

        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
            return {
                success: false,
                error: payload.error || {
                    code: 'HTTP_ERROR',
                    message: `HTTP ${response.status}: ${response.statusText}`,
                },
                request_id: payload.request_id || null,
            };
        }

        return payload;
    } catch (error) {
        clearTimeout(timeoutId);

        if (error.name === 'AbortError') {
            const timeoutError = new Error('Request timeout');
            timeoutError.code = 'NETWORK_TIMEOUT';
            throw timeoutError;
        }

        const networkError = new Error(error.message || 'Network request failed');
        networkError.code = 'NETWORK_ERROR';
        throw networkError;
    }
}

/**
 * Simple sleep/delay utility.
 */
function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}
