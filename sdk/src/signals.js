/**
 * Signal collection module.
 * Gathers browser and device signals for the detection API.
 */

// ─── Network probe constants ───────────────────────────────────────────────
// Cloudflare's /cdn-cgi/trace returns which CF PoP the *browser's* network
// path hits — independent of which PoP our API Worker uses.
// Response fields we care about: colo (e.g. "AMD"), ip (real client IP).
const CF_TRACE_URL     = 'https://www.cloudflare.com/cdn-cgi/trace';
const PROBE_TIMEOUT_MS = 2500;
// ──────────────────────────────────────────────────────────────────────────

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

/**
 * Probe the network to collect topology signals for accurate location detection.
 *
 * Fetches Cloudflare's /cdn-cgi/trace from the BROWSER (not from our server).
 * This independently reveals:
 *   - colo: which CF PoP is nearest to the user's physical location
 *   - ip:   the IP Cloudflare sees from the browser (exposes split-tunnel VPN)
 *   - RTT:  round-trip time to the nearest CF PoP (refines city radius)
 *
 * Also collects navigator.connection for connection type and quality.
 * Runs in under 2.5 seconds (hard timeout) and fails silently.
 *
 * @returns {Promise<object|null>}
 */
export async function probeNetwork() {
    const result = {};

    // Run CF trace (3 samples for min-RTT accuracy) and WebRTC probe in parallel.
    const [cfTrace, webrtc] = await Promise.all([
        probeCfTraceMultiSample(),
        probeWebRtcLocalIp(),
    ]);

    if (cfTrace)  result.cf_trace = cfTrace;
    if (webrtc)   result.webrtc   = webrtc;

    // navigator.connection — passive signal, no permission required
    const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    if (conn) {
        result.connection = {
            type:           conn.type           || null,
            effective_type: conn.effectiveType  || null,
            rtt:            conn.rtt            !== undefined ? conn.rtt      : null,
            downlink:       conn.downlink       !== undefined ? conn.downlink : null,
        };
    }

    return Object.keys(result).length > 0 ? result : null;
}

/**
 * Probe CF trace 3 times in parallel and return the MINIMUM RTT sample.
 *
 * Why minimum, not average?
 *   Network jitter, OS scheduling, and browser overhead add noise. The minimum
 *   RTT across 3 probes is the closest estimate of pure propagation delay
 *   (device → nearest CF PoP), which is what we need for distance estimation.
 *
 * @returns {Promise<{colo: string, ip: string, rtt_ms: number, samples: number}|null>}
 */
async function probeCfTraceMultiSample() {
    const SAMPLES = 3;
    const results = await Promise.all(
        Array.from({ length: SAMPLES }, () => probeCfTrace())
    );

    // Filter out failed probes
    const valid = results.filter(Boolean);
    if (valid.length === 0) return null;

    // All valid probes should return the same colo (same anycast routing).
    // Take the one with minimum RTT as the most accurate propagation estimate.
    const best = valid.reduce((a, b) => (a.rtt_ms <= b.rtt_ms ? a : b));
    return { ...best, samples: valid.length };
}

/**
 * Fetch Cloudflare's trace endpoint and parse the key=value response.
 * @returns {Promise<{colo: string, ip: string, rtt_ms: number}|null>}
 */
async function probeCfTrace() {
    if (typeof fetch === 'undefined' || typeof performance === 'undefined') {
        return null;
    }

    const controller = new AbortController();
    const timer      = setTimeout(() => controller.abort(), PROBE_TIMEOUT_MS);

    try {
        const t0       = performance.now();
        const response = await fetch(CF_TRACE_URL, {
            method:  'GET',
            cache:   'no-store',
            signal:  controller.signal,
        });
        const rttMs = Math.round(performance.now() - t0);
        clearTimeout(timer);

        if (!response.ok) return null;

        const text = await response.text();

        const parseField = (key) => {
            const m = text.match(new RegExp(key + '=([^\\n]+)'));
            return m ? m[1].trim() : null;
        };

        const colo = parseField('colo');
        const ip   = parseField('ip');

        if (!colo) return null;

        return { colo, ip, rtt_ms: rttMs };
    } catch (_e) {
        clearTimeout(timer);
        return null;
    }
}

/**
 * Collect local IP address(es) via WebRTC ICE candidate gathering.
 *
 * For 4G/5G mobile users, the OS-assigned local IP is a Carrier-Grade NAT (CGN)
 * address in the 100.64.0.0/10 or 10.0.0.0/8 range. The specific /24 subnet
 * identifies which ISP gateway (PGW/GGSN) is serving the device — different
 * gateways serve different geographic areas within the same telecom circle.
 *
 * This is the ONLY passive signal that can distinguish sub-circle city level
 * (e.g., Surat vs Vadodara within Airtel Gujarat) without GPS permission.
 *
 * Privacy notes:
 *  - No STUN server is used, so the public IP is NOT leaked.
 *  - Only "host" candidates (device's own interface IPs) are collected.
 *  - Browsers that replace local IPs with mDNS names will return null.
 *  - This is consistent with RFC 8828 (local IP exposure restrictions).
 *
 * @returns {Promise<{local_ips: string[], connection_type: string}|null>}
 */
async function probeWebRtcLocalIp() {
    if (typeof RTCPeerConnection === 'undefined') return null;

    return new Promise((resolve) => {
        const localIps = new Set();
        let settled   = false;

        const settle = () => {
            if (settled) return;
            settled = true;
            try { pc.close(); } catch (_) {}
            if (localIps.size === 0) {
                resolve(null);
                return;
            }
            // Determine connection type from local IP range
            const ips = Array.from(localIps);
            resolve({
                local_ips:       ips,
                connection_type: classifyLocalIp(ips[0]),
            });
        };

        // No STUN servers — only collects host (local interface) candidates
        const pc = new RTCPeerConnection({ iceServers: [] });
        pc.createDataChannel('__probe__');

        pc.onicecandidate = (e) => {
            if (!e.candidate) {
                // Null candidate = ICE gathering complete
                settle();
                return;
            }
            // Parse candidate SDP: "candidate:... IP port ..."
            const parts = e.candidate.candidate.split(' ');
            const type  = parts[7];   // host | srflx | relay
            const ip    = parts[4];   // IP address or mDNS name

            if (type === 'host' && ip && !ip.endsWith('.local')) {
                localIps.add(ip);
            }
        };

        pc.createOffer()
            .then(offer => pc.setLocalDescription(offer))
            .catch(() => settle());

        // Hard timeout — ICE gathering can stall on locked-down networks
        setTimeout(settle, 2000);
    });
}

/**
 * Classify a local IP address into its network type.
 * Used to flag cellular CGN addresses which carry ISP gateway info.
 *
 * @param {string} ip
 * @returns {'cgn_cellular'|'private_wifi'|'link_local'|'unknown'}
 */
function classifyLocalIp(ip) {
    if (!ip) return 'unknown';
    // RFC 6598 — Carrier-Grade NAT (CGNAT), assigned to ISP gateways
    if (/^100\.(6[4-9]|[7-9]\d|1[0-1]\d|12[0-7])\./.test(ip)) return 'cgn_cellular';
    // RFC 1918 private ranges — usually Wi-Fi router NAT
    if (/^10\./.test(ip) || /^172\.(1[6-9]|2\d|3[0-1])\./.test(ip) || /^192\.168\./.test(ip)) return 'private_wifi';
    // RFC 3927 link-local
    if (/^169\.254\./.test(ip)) return 'link_local';
    return 'unknown';
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
