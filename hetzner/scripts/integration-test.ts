import { query } from "@anthropic-ai/claude-code-sdk";

const PROMPT = `
You are an integration test agent for AIquila on Hetzner Cloud.

Your job:
1. Build aiquila-hetzner: cd /workspace/hetzner && go build -o /tmp/aiquila-hetzner ./cmd/aiquila-hetzner
2. Provision a test server:
   /tmp/aiquila-hetzner create --domain TEST_DOMAIN --nc-url NEXTCLOUD_URL --nc-user NEXTCLOUD_USER --nc-password NEXTCLOUD_PASSWORD --name aiquila-inttest-$(date +%s) --type SERVER_TYPE --dns-zone DNS_ZONE
3. Wait for HTTPS: poll curl -sf https://TEST_DOMAIN/.well-known/oauth-authorization-server up to 5 minutes
4. Run OAuth test: cd /workspace && bash docker/standalone/scripts/test-oauth.sh https://TEST_DOMAIN
5. Run tools test: cd /workspace && bash docker/standalone/scripts/test-tools.sh https://TEST_DOMAIN
5.5. Run MCP-Connector test: cd /workspace/mcp-server/scripts && npm install --silent && MCP_URL=https://TEST_DOMAIN tsx test-mcp-connector.ts
     This validates the full MCP-Connector path: OAuth PKCE → access_token → Messages API beta (mcp-client-2025-11-20) → Claude calls AIquila tools.
     ANTHROPIC_API_KEY is available in the environment. NEXTCLOUD_USER and NEXTCLOUD_PASSWORD are also available.
6. Verify Traefik TLS: curl -sI https://TEST_DOMAIN/mcp → HTTP 4xx with valid TLS
7. Verify CrowdSec: ssh into server, docker ps | grep crowdsec → confirm Up
8. Destroy: /tmp/aiquila-hetzner destroy --name <server-name> --dns-zone DNS_ZONE

CRITICAL: Always destroy at the end, even if tests fail.
Report PASS/FAIL for each step and a final summary.

Env vars available: HCLOUD_TOKEN, HETZNER_DNS_TOKEN, NEXTCLOUD_URL, NEXTCLOUD_USER, NEXTCLOUD_PASSWORD, TEST_DOMAIN, DNS_ZONE, SERVER_TYPE, SSH_KEY_PATH
`;

// Substitute env vars into the prompt at runtime to avoid issues with
// template literals expanding process.env references at module load time.
const prompt = PROMPT
  .replaceAll("TEST_DOMAIN", process.env.TEST_DOMAIN ?? "")
  .replaceAll("NEXTCLOUD_URL", process.env.NEXTCLOUD_URL ?? "")
  .replaceAll("NEXTCLOUD_USER", process.env.NEXTCLOUD_USER ?? "")
  .replaceAll("NEXTCLOUD_PASSWORD", process.env.NEXTCLOUD_PASSWORD ?? "")
  .replaceAll("DNS_ZONE", process.env.DNS_ZONE ?? "")
  .replaceAll("SERVER_TYPE", process.env.SERVER_TYPE ?? "cpx11");

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
