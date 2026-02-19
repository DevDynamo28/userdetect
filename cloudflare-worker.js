/**
 * Cloudflare Worker - Geolocation Proxy
 *
 * Required env var:
 * - ORIGIN_URL=https://<direct-origin-host>
 *
 * Important:
 * - ORIGIN_URL must not point to the same hostname/route protected by this Worker.
 *   Use a direct IP or a DNS-only (gray cloud) subdomain like origin.devdemosite.live
 */

const API_PATH_PREFIX = "/api/";
const FORWARDED_MARKER_HEADER = "X-Worker-Forwarded";

// Paths that should pass through to origin without geo processing
const PASSTHROUGH_PATHS = ["/api/health"];

function withCors(headers = new Headers()) {
    const out = new Headers(headers);
    out.set("Access-Control-Allow-Origin", "*");
    out.set("Access-Control-Allow-Methods", "GET, POST, PUT, PATCH, DELETE, OPTIONS");
    out.set("Access-Control-Allow-Headers", "Content-Type, X-API-Key");
    out.set("Access-Control-Max-Age", "86400");
    return out;
}

function jsonError(status, code, message, extra = {}) {
    return new Response(
        JSON.stringify({
            success: false,
            error: { code, message, ...extra },
        }),
        {
            status,
            headers: withCors(new Headers({ "Content-Type": "application/json" })),
        },
    );
}

export default {
    async fetch(request, env) {
        if (request.method === "OPTIONS") {
            return new Response(null, { status: 204, headers: withCors() });
        }

        const requestUrl = new URL(request.url);
        const pathname = requestUrl.pathname;

        // Only process /api/* routes — let everything else pass through to Cloudflare origin
        if (!pathname.startsWith(API_PATH_PREFIX)) {
            return fetch(request);
        }

        // Passthrough paths don't need geo headers — let Cloudflare handle normally
        if (PASSTHROUGH_PATHS.includes(pathname)) {
            return fetch(request);
        }

        const originBase = (env.ORIGIN_URL || "").trim();

        if (!originBase) {
            return jsonError(
                500,
                "WORKER_CONFIG_ERROR",
                "Worker is missing ORIGIN_URL. Configure a direct origin host (IP or DNS-only subdomain).",
            );
        }

        let originBaseUrl;
        try {
            originBaseUrl = new URL(originBase);
        } catch (_err) {
            return jsonError(
                500,
                "WORKER_CONFIG_ERROR",
                "ORIGIN_URL is invalid. Use a full URL like https://origin.example.com.",
            );
        }

        if (request.headers.get(FORWARDED_MARKER_HEADER) === "1") {
            return jsonError(
                508,
                "LOOP_DETECTED",
                "Worker forwarding loop detected. Check Worker route and ORIGIN_URL.",
            );
        }

        // Build origin URL — route to direct origin, bypassing Cloudflare
        const originUrl = new URL(pathname + requestUrl.search, originBaseUrl).toString();
        const headers = new Headers(request.headers);
        const cf = request.cf || {};

        // Mark as forwarded to prevent loops
        headers.set(FORWARDED_MARKER_HEADER, "1");
        headers.set("X-Forwarded-Host", requestUrl.hostname);
        headers.set("X-Real-IP", request.headers.get("CF-Connecting-IP") || "");

        // Inject Cloudflare geo data as custom headers
        if (cf.city) headers.set("X-CF-City", cf.city);
        if (cf.region) headers.set("X-CF-Region", cf.region);
        if (cf.regionCode) headers.set("X-CF-Region-Code", cf.regionCode);
        if (cf.country) headers.set("X-CF-Country", cf.country);
        if (cf.latitude) headers.set("X-CF-Latitude", cf.latitude.toString());
        if (cf.longitude) headers.set("X-CF-Longitude", cf.longitude.toString());
        if (cf.timezone) headers.set("X-CF-Timezone", cf.timezone);
        if (cf.postalCode) headers.set("X-CF-PostalCode", cf.postalCode);
        if (cf.colo) headers.set("X-CF-Colo", cf.colo);
        if (cf.asn) headers.set("X-CF-ASN", cf.asn.toString());
        if (cf.asOrganization) headers.set("X-CF-ASOrg", cf.asOrganization);

        const originRequest = new Request(originUrl, {
            method: request.method,
            headers,
            body: request.method !== "GET" && request.method !== "HEAD" ? request.body : undefined,
        });

        try {
            const originResponse = await fetch(originRequest);
            return new Response(originResponse.body, {
                status: originResponse.status,
                statusText: originResponse.statusText,
                headers: withCors(originResponse.headers),
            });
        } catch (error) {
            const message = (error && error.message) || "Origin fetch failed";
            const code = /tls|ssl|certificate/i.test(message) ? "ORIGIN_TLS_ERROR" : "ORIGIN_UNREACHABLE";

            return jsonError(502, code, "Origin server is unreachable from Worker.", {
                detail: message,
            });
        }
    },
};
