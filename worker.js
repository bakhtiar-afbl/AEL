/**
 * AEL Performance Console — Cloudflare Worker CORS proxy.
 *
 * Purpose: let the static GitHub Pages site call your protected API without any
 * CORS change on the API server. The browser talks to THIS worker; the worker
 * adds the CORS headers the browser needs and forwards the request to the real
 * API server-side (server-to-server calls are not subject to CORS).
 *
 * Deploy: Cloudflare dashboard → Workers & Pages → Create Worker → paste this →
 * Deploy. You'll get a URL like https://ael-proxy.<your-subdomain>.workers.dev
 * Then set that URL as API_DIRECT in index.html (see the deploy notes).
 *
 * Security: only /api/ paths are forwarded, and only to the fixed host below,
 * so this can't be used as an open relay to arbitrary URLs. The bearer token is
 * supplied by the caller (the embedded token in index.html) and passed through.
 */

const API_ORIGIN = "https://arlapi.ibos.io";   // the real API (fixed)

export default {
  async fetch(request) {
    const url = new URL(request.url);
    const reqOrigin = request.headers.get("Origin") || "*";

    // CORS headers echoed back to the browser
    const cors = {
      "Access-Control-Allow-Origin": reqOrigin,
      "Access-Control-Allow-Methods": "GET,POST,PUT,PATCH,DELETE,OPTIONS",
      "Access-Control-Allow-Headers": "Authorization, Content-Type, Accept",
      "Access-Control-Max-Age": "86400",
      "Vary": "Origin",
    };

    // Preflight
    if (request.method === "OPTIONS") {
      return new Response(null, { status: 204, headers: cors });
    }

    // Only proxy /api/ paths
    if (!url.pathname.startsWith("/api/")) {
      return new Response(JSON.stringify({ detail: "Not found" }), {
        status: 404,
        headers: { ...cors, "Content-Type": "application/json" },
      });
    }

    // Build the upstream request
    const target = API_ORIGIN + url.pathname + url.search;
    const init = {
      method: request.method,
      headers: { "Accept": "application/json" },
      redirect: "follow",
    };
    const auth = request.headers.get("Authorization");
    if (auth) init.headers["Authorization"] = auth;
    if (request.method !== "GET" && request.method !== "HEAD") {
      init.body = await request.text();
      init.headers["Content-Type"] = "application/json";
    }

    let upstream;
    try {
      upstream = await fetch(target, init);
    } catch (e) {
      return new Response(JSON.stringify({ detail: "Upstream request failed" }), {
        status: 502,
        headers: { ...cors, "Content-Type": "application/json" },
      });
    }

    const body = await upstream.arrayBuffer();
    const headers = new Headers(cors);
    headers.set("Content-Type", upstream.headers.get("Content-Type") || "application/json");
    return new Response(body, { status: upstream.status, headers });
  },
};
