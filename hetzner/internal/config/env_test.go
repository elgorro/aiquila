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
