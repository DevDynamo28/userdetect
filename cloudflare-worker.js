/**
 * Cloudflare Worker — Geolocation Proxy
 * 
 * Deploy this Worker to inject Cloudflare's geo data into requests
 * before they reach your Laravel backend.
 * 
 * Setup:
 * 1. Go to Cloudflare Dashboard → Workers & Pages → Create Worker
 * 2. Paste this code
 * 3. Add a route: yourdomain.com/api/* → this worker
 * 4. Set ORIGIN_URL environment variable to your Laravel server
 *    e.g., https://your-server.com
 */
export default {
    async fetch(request, env) {
        const url = new URL(request.url);

        // Build origin URL — forward to your Laravel backend
        const originUrl = (env.ORIGIN_URL || url.origin) + url.pathname + url.search;

        // Extract Cloudflare's geolocation data from request.cf
        const cf = request.cf || {};

        // Clone headers and inject geo data
        const headers = new Headers(request.headers);

        // Inject Cloudflare geo headers
        if (cf.city) headers.set('X-CF-City', cf.city);
        if (cf.region) headers.set('X-CF-Region', cf.region);
        if (cf.regionCode) headers.set('X-CF-Region-Code', cf.regionCode);
        if (cf.country) headers.set('X-CF-Country', cf.country);
        if (cf.latitude) headers.set('X-CF-Latitude', cf.latitude.toString());
        if (cf.longitude) headers.set('X-CF-Longitude', cf.longitude.toString());
        if (cf.timezone) headers.set('X-CF-Timezone', cf.timezone);
        if (cf.postalCode) headers.set('X-CF-PostalCode', cf.postalCode);
        if (cf.colo) headers.set('X-CF-Colo', cf.colo);
        if (cf.asn) headers.set('X-CF-ASN', cf.asn.toString());
        if (cf.asOrganization) headers.set('X-CF-ASOrg', cf.asOrganization);

        // Preserve the real client IP
        headers.set('X-Real-IP', request.headers.get('CF-Connecting-IP') || '');

        // Forward the request to your origin
        const originRequest = new Request(originUrl, {
            method: request.method,
            headers: headers,
            body: request.method !== 'GET' && request.method !== 'HEAD'
                ? request.body
                : undefined,
        });

        try {
            const response = await fetch(originRequest);

            // Add CORS headers for SDK requests
            const responseHeaders = new Headers(response.headers);
            responseHeaders.set('Access-Control-Allow-Origin', '*');
            responseHeaders.set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
            responseHeaders.set('Access-Control-Allow-Headers', 'Content-Type, X-API-Key');

            return new Response(response.body, {
                status: response.status,
                statusText: response.statusText,
                headers: responseHeaders,
            });
        } catch (error) {
            return new Response(JSON.stringify({
                error: 'Origin server unreachable'
            }), {
                status: 502,
                headers: { 'Content-Type': 'application/json' }
            });
        }
    }
};
