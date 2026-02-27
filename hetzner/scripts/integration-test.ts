import { query } from "@anthropic-ai/claude-code-sdk";

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

8. Run OAuth test:
   cd /workspace && bash docker/standalone/scripts/test-oauth.sh https://MCP_DOMAIN

9. Run tools test:
   cd /workspace && bash docker/standalone/scripts/test-tools.sh https://MCP_DOMAIN

10. Run MCP-Connector test:
    cd /workspace/mcp-server/scripts && npm install --silent
    MCP_URL=https://MCP_DOMAIN tsx test-mcp-connector.ts
    (ANTHROPIC_API_KEY is available in the environment)

11. Verify Traefik TLS on MCP:
    curl -sI https://MCP_DOMAIN/mcp → HTTP 4xx with valid TLS (no cert error)

12. Verify CrowdSec running on MCP server:
    SSH into MCP server and run: docker ps | grep aiq-crowdsec → confirm Up

13. Destroy both servers (ALWAYS run this, even if tests fail):
    /tmp/aiquila-hetzner destroy --name "${NC_NAME}" --dns-zone DNS_ZONE
    /tmp/aiquila-hetzner destroy --name "${MCP_NAME}" --dns-zone DNS_ZONE

CRITICAL: Always destroy both servers at the end, even if tests fail.
Report PASS/FAIL for each step and a final summary.
`;

// Substitute env vars into the prompt at runtime.
const prompt = PROMPT
  .replaceAll("NC_DOMAIN", process.env.NC_DOMAIN ?? "")
  .replaceAll("MCP_DOMAIN", process.env.MCP_DOMAIN ?? "")
  .replaceAll("DNS_ZONE", process.env.DNS_ZONE ?? "")
  .replaceAll("NC_SERVER_TYPE", process.env.NC_SERVER_TYPE ?? "cpx21")
  .replaceAll("MCP_SERVER_TYPE", process.env.MCP_SERVER_TYPE ?? "cpx11");

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
        process.stderr.write(block.text);
      }
    }
  } else if (message.type === "result") {
    process.stderr.write(`\n--- Agent result: ${message.subtype} ---\n`);
    process.stderr.write(`Turns: ${message.num_turns}, Cost: $${message.total_cost_usd?.toFixed(4)}\n`);

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
