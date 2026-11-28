// SPDX-License-Identifier: MIT

package config

import (
	"strings"
	"testing"
)

func TestExistingCredentialKey(t *testing.T) {
	key := strings.Repeat("ab", 64)
	cases := map[string]string{
		"MCP_AUTH_SECRET=x\nMCP_CREDENTIAL_KEY=" + key + "\n": key,
		"MCP_CREDENTIAL_KEY=\"" + key + "\"":                  key,
		"MCP_CREDENTIAL_KEY=" + strings.Repeat("ab", 16):      "", // too short
		"MCP_CREDENTIAL_KEY=not-hex":                          "",
		"MCP_AUTH_SECRET=x\n":                                 "",
		"":                                                    "",
	}
	for in, want := range cases {
		if got := ExistingCredentialKey(in); got != want {
			t.Errorf("ExistingCredentialKey(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestGenerateCredentialKeyIs512Bits(t *testing.T) {
	env, err := Generate("https://nc.example.com", "", "", "mcp.example.com", "a@example.com")
	if err != nil {
		t.Fatal(err)
	}
	if len(env.CredentialKey) != 128 {
		t.Fatalf("credential key has %d hex chars, want 128", len(env.CredentialKey))
	}
	out := env.Render()
	if !strings.Contains(out, "MCP_CREDENTIAL_KEY="+env.CredentialKey) {
		t.Error("rendered .env lacks MCP_CREDENTIAL_KEY")
	}
	if strings.Contains(out, "NEXTCLOUD_USER=") {
		t.Error("rendered .env writes NEXTCLOUD_USER although none was given")
	}
}

// The compose files pin MCP_TRUST_PROXY to 1 (Traefik is one hop) and the
// server no longer reads MCP_CLIENT_SECRET, so neither belongs in a generated
// .env: a value there would suggest that editing it changes something.
func TestRenderOmitsVariablesTheStackIgnores(t *testing.T) {
	mcp, err := Generate("https://nc.example.com", "", "", "mcp.example.com", "a@example.com")
	if err != nil {
		t.Fatal(err)
	}
	full, err := GenerateFull("nc.example.com", "mcp.example.com", "admin", "pw", "mcp", "pw", "a@example.com")
	if err != nil {
		t.Fatal(err)
	}
	for name, out := range map[string]string{"mcp": mcp.Render(), "full": full.Render()} {
		for _, v := range []string{"MCP_TRUST_PROXY", "MCP_CLIENT_SECRET"} {
			if strings.Contains(out, v) {
				t.Errorf("%s stack .env contains %s", name, v)
			}
		}
	}
}

// parseEnv reads KEY=value lines, ignoring comments and blank lines.
func parseEnv(t *testing.T, s string) map[string]string {
	t.Helper()
	out := map[string]string{}
	for _, line := range strings.Split(s, "\n") {
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		k, v, ok := strings.Cut(line, "=")
		if !ok {
			t.Fatalf("line without '=': %q", line)
		}
		if _, dup := out[k]; dup {
			t.Fatalf("%s set twice", k)
		}
		out[k] = v
	}
	return out
}

func isHex(s string, n int) bool {
	if len(s) != n {
		return false
	}
	for _, c := range s {
		if !strings.ContainsRune("0123456789abcdef", c) {
			return false
		}
	}
	return true
}

func TestGenerateNCRendersFreshSecrets(t *testing.T) {
	a, err := GenerateNC("cloud.example.com", "admin", "pw", "ops@example.com")
	if err != nil {
		t.Fatal(err)
	}
	b, err := GenerateNC("cloud.example.com", "admin", "pw", "ops@example.com")
	if err != nil {
		t.Fatal(err)
	}
	env := parseEnv(t, a.Render())

	for k, want := range map[string]string{
		"NC_DOMAIN": "cloud.example.com", "ACME_EMAIL": "ops@example.com",
		"NC_ADMIN_USER": "admin", "NC_ADMIN_PASSWORD": "pw",
	} {
		if env[k] != want {
			t.Errorf("%s = %q, want %q", k, env[k], want)
		}
	}
	for k, n := range map[string]int{"POSTGRES_PASSWORD": 64, "CROWDSEC_BOUNCER_KEY": 64, "NC_METRICS_TOKEN": 32} {
		if !isHex(env[k], n) {
			t.Errorf("%s = %q, want %d hex chars", k, env[k], n)
		}
	}
	if a.PostgresPassword == b.PostgresPassword || a.NCMetricsToken == b.NCMetricsToken {
		t.Error("two runs generated the same secret")
	}
}

func TestGenerateMCPRendersAGrafanaPassword(t *testing.T) {
	e, err := Generate("https://nc.example.com", "mcp", "app-pw", "mcp.example.com", "ops@example.com")
	if err != nil {
		t.Fatal(err)
	}
	env := parseEnv(t, e.Render())
	if env["GRAFANA_PASSWORD"] == "" || env["GRAFANA_PASSWORD"] != e.GrafanaPassword {
		t.Errorf("GRAFANA_PASSWORD = %q", env["GRAFANA_PASSWORD"])
	}
	if env["NEXTCLOUD_USER"] != "mcp" || env["MCP_DOMAIN"] != "mcp.example.com" {
		t.Errorf("env = %v", env)
	}
}

func TestGenerateFullSharesTheMetricsTokenAndIssuer(t *testing.T) {
	e, err := GenerateFull("cloud.example.com", "mcp.example.com", "admin", "pw", "mcp", "app-pw", "ops@example.com")
	if err != nil {
		t.Fatal(err)
	}
	env := parseEnv(t, e.Render())
	if env["MCP_AUTH_ISSUER"] != "https://mcp.example.com" {
		t.Errorf("MCP_AUTH_ISSUER = %q", env["MCP_AUTH_ISSUER"])
	}
	if !isHex(env["NC_METRICS_TOKEN"], 32) || env["NC_METRICS_TOKEN"] != e.NCMetricsToken {
		t.Errorf("NC_METRICS_TOKEN = %q", env["NC_METRICS_TOKEN"])
	}
	if !isHex(env["MCP_CREDENTIAL_KEY"], 128) {
		t.Errorf("MCP_CREDENTIAL_KEY = %q", env["MCP_CREDENTIAL_KEY"])
	}
}
