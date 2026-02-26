#!/usr/bin/env tsx
/**
 * test-mcp-connector.ts
 *
 * Demonstrates and validates AIquila's MCP-Connector integration using
 * Anthropic's Messages API beta (mcp-client-2025-11-20).
 *
 * Flow:
 *   1. OAuth PKCE flow against the AIquila MCP server → access_token
 *   2. Calls anthropic.beta.messages.create() with mcp_servers pointing to AIquila
 *   3. Claude autonomously uses AIquila tools to verify the server works
 *
 * Required env vars:
 *   ANTHROPIC_API_KEY     Anthropic API key
 *   NEXTCLOUD_USER        Nextcloud username
 *   NEXTCLOUD_PASSWORD    Nextcloud password (or app password)
 *
 * Optional env vars:
 *   MCP_URL               Base URL of the MCP server (default: http://localhost:3339)
 *   DEFER_TOOLS           Set to "true" to demo defer_loading optimization (default: false)
 *
 * Usage:
 *   cd mcp-server/scripts
 *   npm install
 *   MCP_URL=http://localhost:3339 tsx test-mcp-connector.ts
 *
 * Or via make (from docker/standalone/):
 *   make test-mcp-connector
 */
import Anthropic from "@anthropic-ai/sdk";
import * as crypto from "node:crypto";

const BASE_URL = (process.env.MCP_URL ?? "http://localhost:3339").replace(
  /\/$/,
  ""
);
const NC_USER = process.env.NEXTCLOUD_USER ?? "";
const NC_PASS = process.env.NEXTCLOUD_PASSWORD ?? "";
const DEFER_TOOLS = process.env.DEFER_TOOLS === "true";

const REDIRECT_URI = "https://localhost/callback";

function log(msg: string) {
  process.stderr.write(msg + "\n");
}

function sep(label: string) {
  log("\n─────────────────────────────────────────────────");
  log(`  ${label}`);
  log("─────────────────────────────────────────────────");
}

// ── PKCE helpers ──────────────────────────────────────────────────────────────

function generateCodeVerifier(): string {
  return crypto.randomBytes(96).toString("base64url").slice(0, 128);
}

function generateCodeChallenge(verifier: string): string {
  return crypto
    .createHash("sha256")
    .update(verifier)
    .digest()
    .toString("base64url");
}

// ── OAuth PKCE flow ───────────────────────────────────────────────────────────

async function getAccessToken(): Promise<string> {
  // 1. Discovery
  sep("OAuth — Discovery");
  const discoveryRes = await fetch(
    `${BASE_URL}/.well-known/oauth-authorization-server`
  );
  if (!discoveryRes.ok) {
    throw new Error(
      `Discovery failed: ${discoveryRes.status} ${discoveryRes.statusText}`
    );
  }
  const discovery = (await discoveryRes.json()) as Record<string, string>;
  log(`issuer             : ${discovery.issuer}`);
  log(`token_endpoint     : ${discovery.token_endpoint}`);
  log(`registration_endpoint: ${discovery.registration_endpoint}`);

  // Resolve endpoints relative to BASE_URL — strip issuer host so HTTP/HTTPS
  // port mismatches (e.g. MCP_AUTH_ISSUER=https://localhost:3340 vs
  // MCP_URL=http://localhost:3339) don't cause TLS errors.
  // Mirrors the approach used by test-oauth.sh.
  const registrationUrl =
    BASE_URL + new URL(discovery.registration_endpoint).pathname;
  const tokenUrl = BASE_URL + new URL(discovery.token_endpoint).pathname;
  const loginUrl = `${BASE_URL}/auth/login`;
  log(`registration → ${registrationUrl}`);
  log(`token        → ${tokenUrl}`);

  // 2. Dynamic client registration
  sep("OAuth — Client Registration");
  const regRes = await fetch(registrationUrl, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      client_name: "mcp-connector-test",
      redirect_uris: [REDIRECT_URI],
      grant_types: ["authorization_code"],
      response_types: ["code"],
      token_endpoint_auth_method: "none",
    }),
  });
  if (!regRes.ok) {
    const body = await regRes.text();
    throw new Error(`Client registration failed: ${regRes.status} ${body}`);
  }
  const registration = (await regRes.json()) as Record<string, string>;
  const clientId = registration.client_id;
  log(`client_id : ${clientId}`);

  // 3. PKCE challenge
  const codeVerifier = generateCodeVerifier();
  const codeChallenge = generateCodeChallenge(codeVerifier);
  const state = crypto.randomBytes(8).toString("hex");

  // 4. Login — POST credentials, follow redirect manually to extract auth code
  sep("OAuth — Login");
  const loginParams = new URLSearchParams({
    username: NC_USER,
    password: NC_PASS,
    client_id: clientId,
    redirect_uri: REDIRECT_URI,
    state,
    code_challenge: codeChallenge,
    code_challenge_method: "S256",
    scope: "",
  });

  const loginRes = await fetch(loginUrl, {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: loginParams.toString(),
    redirect: "manual",
  });

  const locationHeader = loginRes.headers.get("location");
  if (!locationHeader) {
    const body = await loginRes.text();
    throw new Error(
      `Login failed — no redirect location. Status: ${loginRes.status}\n${body.slice(0, 200)}`
    );
  }

  const locationUrl = new URL(locationHeader, BASE_URL);
  const authCode = locationUrl.searchParams.get("code");
  if (!authCode) {
    throw new Error(
      `Login failed — no code in redirect location: ${locationHeader}`
    );
  }
  log("Login succeeded (auth code received)");

  // 5. Token exchange
  sep("OAuth — Token Exchange");
  const tokenParams = new URLSearchParams({
    grant_type: "authorization_code",
    code: authCode,
    redirect_uri: REDIRECT_URI,
    code_verifier: codeVerifier,
    client_id: clientId,
  });

  const tokenRes = await fetch(tokenUrl, {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: tokenParams.toString(),
  });
  if (!tokenRes.ok) {
    const body = await tokenRes.text();
    throw new Error(`Token exchange failed: ${tokenRes.status} ${body}`);
  }
  const tokenData = (await tokenRes.json()) as Record<string, unknown>;
  const accessToken = tokenData.access_token as string;
  if (!accessToken) {
    throw new Error(
      `No access_token in response: ${JSON.stringify(tokenData)}`
    );
  }
  log(`access_token : ${accessToken.slice(0, 20)}...`);

  return accessToken;
}

// ── MCP-Connector test ────────────────────────────────────────────────────────

async function testWithConnector(accessToken: string): Promise<boolean> {
  sep("MCP-Connector — Messages API (beta: mcp-client-2025-11-20)");

  const anthropic = new Anthropic();

  // Build toolset config — optionally demonstrate defer_loading
  // defer_loading: true sends only tool summaries initially, saving tokens.
  // Individual tools can opt out with defer_loading: false.
  // See docs/mcp/mcp-connector.md for the full defer_loading pattern.
  const toolset: Record<string, unknown> = {
    type: "mcp_toolset",
    mcp_server_name: "aiquila",
  };

  if (DEFER_TOOLS) {
    log(
      "defer_loading: enabled — only system_status and list_files loaded eagerly"
    );
    toolset.default_config = { defer_loading: true };
    toolset.configs = {
      system_status: { defer_loading: false },
      list_files: { defer_loading: false },
    };
  } else {
    log("defer_loading: disabled (all tools loaded)");
  }

  log(`\nMCP server URL : ${BASE_URL}/mcp`);
  log("Sending request to Messages API...\n");

  // Use beta.messages for mcp-client-2025-11-20 support.
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const response = await (anthropic.beta.messages as any).create({
    model: "claude-opus-4-6",
    max_tokens: 2000,
    messages: [
      {
        role: "user",
        content:
          'Use the system_status tool to verify the Nextcloud connection, then use list_files with path "/" to list the root folder. Report PASS if both tools return valid data, FAIL otherwise.',
      },
    ],
    mcp_servers: [
      {
        type: "url",
        url: `${BASE_URL}/mcp`,
        name: "aiquila",
        authorization_token: accessToken,
      },
    ],
    tools: [toolset],
    betas: ["mcp-client-2025-11-20"],
  });

  // Print Claude's response text to stdout; keep logs on stderr
  let resultText = "";
  for (const block of response.content as Array<{ type: string; text?: string }>) {
    if (block.type === "text" && block.text) {
      process.stdout.write(block.text);
      resultText += block.text;
    }
  }
  process.stdout.write("\n");

  const passed = !resultText.toUpperCase().includes("FAIL");
  return passed;
}

// ── Main ──────────────────────────────────────────────────────────────────────

async function main() {
  // Validate required env vars
  const missing: string[] = [];
  if (!process.env.ANTHROPIC_API_KEY) missing.push("ANTHROPIC_API_KEY");
  if (!NC_USER) missing.push("NEXTCLOUD_USER");
  if (!NC_PASS) missing.push("NEXTCLOUD_PASSWORD");
  if (missing.length > 0) {
    log(`ERROR: Missing required env vars: ${missing.join(", ")}`);
    process.exit(1);
  }

  log("\nAIquila MCP-Connector Test");
  log(`MCP server : ${BASE_URL}`);
  log(`NC user    : ${NC_USER}`);

  // Pre-flight: verify the server is reachable and OAuth is enabled
  sep("Pre-flight — Server Reachability");
  try {
    const preflight = await fetch(
      `${BASE_URL}/.well-known/oauth-authorization-server`,
      { signal: AbortSignal.timeout(5000) }
    );
    if (preflight.status === 404) {
      log(
        "ERROR: OAuth metadata endpoint returned 404 — is MCP_AUTH_ENABLED=true?"
      );
      log(
        "The MCP-Connector test requires OAuth enabled on the server (MCP_AUTH_ENABLED=true)."
      );
      process.exit(1);
    }
    if (!preflight.ok) {
      throw new Error(`HTTP ${preflight.status}`);
    }
    log("Server reachable, OAuth enabled ✓");
  } catch (err) {
    if ((err as Error).message?.includes("404")) throw err;
    log(`ERROR: Server not reachable at ${BASE_URL}: ${err}`);
    log("Is the MCP server running with OAuth enabled? (make up)");
    process.exit(1);
  }

  // OAuth PKCE flow
  const accessToken = await getAccessToken();

  // MCP-Connector test via Messages API
  const passed = await testWithConnector(accessToken);

  // Summary
  sep("Summary");
  if (passed) {
    log("  ✓ PASS — MCP-Connector integration verified");
    process.exit(0);
  } else {
    log("  ✗ FAIL — see output above for details");
    process.exit(1);
  }
}

main().catch((err: Error) => {
  log(`\nFatal error: ${err.message ?? String(err)}`);
  process.exit(1);
});
