import { query } from "@anthropic-ai/claude-code-sdk";

const testConfig = {
  oauth: process.env.RUN_OAUTH_TEST !== "false",
  tools: process.env.RUN_TOOLS_TEST !== "false",
  mcpProtocol: process.env.RUN_MCP_PROTOCOL_TEST !== "false",
  ncApp: process.env.RUN_NC_APP_TEST !== "false",
  connector: process.env.RUN_CONNECTOR_TEST === "true",
  infra: process.env.RUN_INFRA_TEST !== "false",
};

function step(enabled: boolean, instruction: string, label: string): string {
  return enabled ? instruction : `SKIP — ${label}. Mark as SKIP in summary.`;
}

const STEP_8_TEXT = `Run OAuth test:
   cd /workspace && bash docker/standalone/scripts/test-oauth.sh https://MCP_DOMAIN`;

const STEP_9_TEXT = `Run tools test:
   cd /workspace && bash docker/standalone/scripts/test-tools.sh https://MCP_DOMAIN`;

const STEP_10_TEXT = `Run MCP protocol conformance check:
   Obtain an access token via PKCE (reuse from step 8 if available, or perform a fresh flow).
   Then send two MCP JSON-RPC requests directly:

   a) POST https://MCP_DOMAIN/mcp
      Headers: Authorization: Bearer <token>, Content-Type: application/json
      Body: {"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"conformance-test","version":"1.0"}}}
      Assert (using jq or python3 -mjson.tool):
        - .result.protocolVersion == "2025-03-26"
        - .result.serverInfo.name == "aiquila"
        - .result.capabilities.tools exists (not null)
      Save mcp-session-id from response header.

   b) POST https://MCP_DOMAIN/mcp
      Headers: Authorization: Bearer <token>, Mcp-Session-Id: <session-id>, Content-Type: application/json
      Body: {"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}
      Assert:
        - .result.tools is a non-empty array
        - array contains tools named: system_status, list_files, create_folder, write_file, read_file, delete`;

const STEP_11_TEXT = `Verify AIquila Nextcloud app REST endpoints with basic auth (admin:<nc-admin-password>):

   a) GET https://NC_DOMAIN/apps/aiquila/api/settings
      Assert: HTTP 200 with JSON body (app endpoints accessible)

   b) GET https://NC_DOMAIN/ocs/v2.php/apps/aiquila
      -H "OCS-APIRequest: true"
      Assert: HTTP 200 and response contains "aiquila"`;

const STEP_12_TEXT = `Run MCP-Connector test:
   cd /workspace/mcp-server/scripts && npm install --silent
   MCP_URL=https://MCP_DOMAIN tsx test-mcp-connector.ts
   (ANTHROPIC_API_KEY is available in the environment)`;

const STEP_13_14_TEXT = `13. Verify Traefik TLS on MCP:
    curl -sI https://MCP_DOMAIN/mcp → HTTP 4xx with valid TLS (no cert error)

14. Verify CrowdSec running on MCP server:
    SSH into MCP server and run: docker ps | grep aiq-crowdsec → confirm Up`;

const PROMPT = `
You are an integration test agent for AIquila on Hetzner Cloud.

Environment variables available:
  HCLOUD_TOKEN, HETZNER_DNS_TOKEN, ANTHROPIC_API_KEY
  NC_DOMAIN, MCP_DOMAIN, DNS_ZONE
  NC_SERVER_TYPE (default: cpx21), MCP_SERVER_TYPE (default: cpx11)
  SSH_KEY_PATH (optional)

Your job:

1. Build aiquila-hetzner:
   cd /workspace/hetzner && go build -o /tmp/aiquila-hetzner ./cmd/aiquila-hetzner

2. Choose a shared timestamp suffix:
   TS=$(date +%s)
   NC_NAME="nc-inttest-${TS}"
   MCP_NAME="mcp-inttest-${TS}"

3. Provision the Nextcloud server (NC_SERVER_TYPE, default cpx21):
   /tmp/aiquila-hetzner create \\
     --stack nextcloud \\
     --nc-domain NC_DOMAIN \\
     --nc-admin-user admin \\
     --nc-admin-password <generate a strong random password> \\
     --name "${NC_NAME}" \\
     --type NC_SERVER_TYPE \\
     --dns-zone DNS_ZONE

   Save the NC admin password used — you'll need it to configure the MCP server.

4. While NC is provisioning (or after), provision the MCP server (MCP_SERVER_TYPE, default cpx11):
   /tmp/aiquila-hetzner create \\
     --stack mcp \\
     --mcp-domain MCP_DOMAIN \\
     --nc-url https://NC_DOMAIN \\
     --nc-user admin \\
     --nc-password <nc-admin-password-from-step-3> \\
     --name "${MCP_NAME}" \\
     --type MCP_SERVER_TYPE \\
     --dns-zone DNS_ZONE

   Note: The NC server creates an app password via OCC; use the Nextcloud admin password
   as the --nc-password if no separate app password was generated.

5. Wait for Nextcloud ready:
   Poll https://NC_DOMAIN/status.php up to 5 minutes until {"installed":true}

6. Verify AIquila app installed on Nextcloud:
   curl -sf -u admin:<nc-admin-password> \\
     https://NC_DOMAIN/ocs/v2.php/cloud/apps/aiquila \\
     -H "OCS-APIRequest: true" | grep -q '"aiquila"'
   → must return HTTP 200 with app data

7. Wait for MCP ready:
   Poll https://MCP_DOMAIN/.well-known/oauth-authorization-server up to 5 minutes

8. STEP_8

9. STEP_9

10. STEP_10

11. STEP_11

12. STEP_12

STEP_13_14

15. Destroy both servers (ALWAYS run this, even if tests fail):
    /tmp/aiquila-hetzner destroy --name "${NC_NAME}" --dns-zone DNS_ZONE
    /tmp/aiquila-hetzner destroy --name "${MCP_NAME}" --dns-zone DNS_ZONE

CRITICAL: Always destroy both servers at the end, even if tests fail.
Report PASS/FAIL/SKIP for each step and a final summary.
`;

// Build the prompt: insert step bodies first so domain placeholders inside
// step texts are also expanded by the subsequent replaceAll calls.
const prompt = PROMPT.replace(
  "STEP_8",
  step(testConfig.oauth, STEP_8_TEXT, "OAuth PKCE test"),
)
  .replace(
    "STEP_9",
    step(testConfig.tools, STEP_9_TEXT, "Tools functional test"),
  )
  .replace(
    "STEP_10",
    step(testConfig.mcpProtocol, STEP_10_TEXT, "MCP protocol conformance"),
  )
  .replace("STEP_11", step(testConfig.ncApp, STEP_11_TEXT, "NC app API check"))
  .replace(
    "STEP_12",
    step(testConfig.connector, STEP_12_TEXT, "MCP-Connector test"),
  )
  .replace(
    "STEP_13_14",
    step(testConfig.infra, STEP_13_14_TEXT, "Infrastructure checks"),
  )
  .replaceAll("NC_DOMAIN", process.env.NC_DOMAIN ?? "")
  .replaceAll("MCP_DOMAIN", process.env.MCP_DOMAIN ?? "")
  .replaceAll("DNS_ZONE", process.env.DNS_ZONE ?? "")
  .replaceAll("NC_SERVER_TYPE", process.env.NC_SERVER_TYPE ?? "cpx21")
  .replaceAll("MCP_SERVER_TYPE", process.env.MCP_SERVER_TYPE ?? "cpx11");

const seenIPs = new Set<string>();
const IPv4_RE = /\b(\d{1,3}\.){3}\d{1,3}\b/g;

function maskNewIPs(text: string): void {
  for (const match of text.matchAll(IPv4_RE)) {
    const ip = match[0];
    if (!seenIPs.has(ip)) {
      seenIPs.add(ip);
      process.stdout.write(`::add-mask::${ip}\n`);
    }
  }
}

let exitCode = 1; // pessimistic default

for await (const message of query({
  prompt,
  options: {
    allowedTools: ["Bash"],
    permissionMode: "bypassPermissions",
    cwd: "/workspace",
  },
})) {
  if (message.type === "assistant") {
    for (const block of message.message.content) {
      if (block.type === "text") {
        maskNewIPs(block.text);
        process.stderr.write(block.text);
      }
    }
  } else if (message.type === "result") {
    process.stderr.write(`\n--- Agent result: ${message.subtype} ---\n`);
    process.stderr.write(
      `Turns: ${message.num_turns}, Cost: $${message.total_cost_usd?.toFixed(4)}\n`,
    );

    if (!message.is_error) {
      const resultText = (message as any).result ?? "";
      if (resultText.toUpperCase().includes("FAIL")) {
        process.stderr.write("Tests reported FAIL in summary.\n");
        exitCode = 1;
      } else {
        exitCode = 0;
      }
    } else {
      process.stderr.write(`Errors: ${(message as any).errors?.join(", ")}\n`);
      exitCode = 1;
    }
  }
}

process.exit(exitCode);
