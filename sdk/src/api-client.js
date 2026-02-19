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
                    code: 'NETWORK_ERROR',
                    message: retryError.message || 'Failed to connect to detection API.',
                },
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

        if (!response.ok) {
            const errorData = await response.json().catch(() => ({}));
            return {
                success: false,
                error: errorData.error || {
                    code: 'HTTP_ERROR',
                    message: `HTTP ${response.status}: ${response.statusText}`,
                },
            };
        }

        return await response.json();
    } catch (error) {
        clearTimeout(timeoutId);

        if (error.name === 'AbortError') {
            throw new Error('Request timeout');
        }

        throw error;
    }
}

/**
 * Simple sleep/delay utility.
 */
function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}
