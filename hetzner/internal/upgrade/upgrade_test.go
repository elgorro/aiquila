package upgrade

import (
	"strings"
	"testing"

	"github.com/elgorro/aiquila/hetzner/internal/config"
)

func TestDetectStack(t *testing.T) {
	for _, s := range []Stack{StackMCP, StackNextcloud, StackFull} {
		got, err := DetectStack(Template(s))
		if err != nil || got != s {
			t.Errorf("DetectStack(template %s) = %q, %v", s, got, err)
		}
	}
	if _, err := DetectStack("services:\n  web:\n    image: nginx\n"); err == nil {
		t.Error("a foreign compose file was accepted")
	}
}

func TestRenderComposePinsTheMCPImage(t *testing.T) {
	for _, s := range []Stack{StackMCP, StackFull} {
		out := RenderCompose(s, "0.5.0")
		if got := MCPImageTag(out); got != "0.5.0" {
			t.Errorf("%s: MCP image tag = %q, want 0.5.0", s, got)
		}
		if strings.Contains(out, "aiquila-mcp:latest") {
			t.Errorf("%s: still names :latest", s)
		}
	}
	if RenderCompose(StackNextcloud, "0.5.0") != Template(StackNextcloud) {
		t.Error("the Nextcloud-only template changed although it has no MCP image")
	}
}

func TestEnvFileKeepsLinesAndAppends(t *testing.T) {
	in := "# header\nA=1\n\nexport B=\"two\"\n# trailing comment\n"
	f := ParseEnv(in)
	if f.Get("A") != "1" || f.Get("B") != "two" || f.Get("C") != "" {
		t.Fatalf("Get: A=%q B=%q", f.Get("A"), f.Get("B"))
	}
	if f.String() != in {
		t.Fatalf("round trip changed the file:\n%s", f.String())
	}
	f.Set("A", "9", "")
	f.Set("C", "3", "line one\n# line two")
	want := "# header\nA=9\n\nexport B=\"two\"\n# trailing comment\n\n# line one\n# line two\nC=3\n"
	if f.String() != want {
		t.Fatalf("got:\n%s\nwant:\n%s", f.String(), want)
	}
}

// A 0.4.x .env: what GenerateFull rendered before MCP_CREDENTIAL_KEY existed.
func old04FullEnv(t *testing.T) string {
	e, err := config.GenerateFull("nc.example.com", "mcp.example.com", "admin", "pw", "aiquila-mcp", "app", "")
	if err != nil {
		t.Fatal(err)
	}
	var keep []string
	for _, l := range strings.Split(e.Render(), "\n") {
		if !strings.HasPrefix(l, "MCP_CREDENTIAL_KEY=") {
			keep = append(keep, l)
		}
	}
	return strings.Join(keep, "\n")
}

func TestMigrateEnvAddsTheCredentialKeyOnce(t *testing.T) {
	env := old04FullEnv(t)
	target := RenderCompose(StackFull, "0.5.0")
	m, err := MigrateEnv(StackFull, env, target)
	if err != nil {
		t.Fatal(err)
	}
	if len(m.Added) != 1 || m.Added[0] != "MCP_CREDENTIAL_KEY" {
		t.Fatalf("Added = %v", m.Added)
	}
	if k := ParseEnv(m.Env).Get("MCP_CREDENTIAL_KEY"); len(k) != 128 {
		t.Fatalf("credential key has %d hex chars, want 128", len(k))
	}
	if !strings.HasPrefix(m.Env, strings.TrimSuffix(env, "\n")) {
		t.Error("existing lines were not kept as they were")
	}

	again, err := MigrateEnv(StackFull, m.Env, target)
	if err != nil {
		t.Fatal(err)
	}
	if len(again.Added) != 0 || again.Env != m.Env {
		t.Errorf("second run changed the .env: added %v", again.Added)
	}
}

func TestMigrateEnvKeepsAnExistingKey(t *testing.T) {
	env := old04FullEnv(t) + "\nMCP_CREDENTIAL_KEY=" + strings.Repeat("ab", 64) + "\n"
	m, err := MigrateEnv(StackFull, env, RenderCompose(StackFull, "0.5.0"))
	if err != nil {
		t.Fatal(err)
	}
	if len(m.Added) != 0 || ParseEnv(m.Env).Get("MCP_CREDENTIAL_KEY") != strings.Repeat("ab", 64) {
		t.Errorf("an existing credential key was replaced (added %v)", m.Added)
	}
}

func TestMigrateEnvStopsOnAVariableItCannotGenerate(t *testing.T) {
	env := strings.ReplaceAll(old04FullEnv(t), "POSTGRES_PASSWORD=", "#POSTGRES_PASSWORD=")
	_, err := MigrateEnv(StackFull, env, RenderCompose(StackFull, "0.5.0"))
	if err == nil || !strings.Contains(err.Error(), "POSTGRES_PASSWORD") {
		t.Fatalf("err = %v, want one naming POSTGRES_PASSWORD", err)
	}
}

func TestEveryTemplateIsSatisfiedByAFreshEnv(t *testing.T) {
	mcp, _ := config.Generate("https://nc.example.com", "", "", "mcp.example.com", "")
	nc, _ := config.GenerateNC("nc.example.com", "admin", "pw", "")
	full, _ := config.GenerateFull("nc.example.com", "mcp.example.com", "admin", "pw", "aiquila-mcp", "app", "")
	for s, env := range map[Stack]string{StackMCP: mcp.Render(), StackNextcloud: nc.Render(), StackFull: full.Render()} {
		m, err := MigrateEnv(s, env, RenderCompose(s, "0.5.0"))
		if err != nil {
			t.Errorf("%s: %v", s, err)
		} else if len(m.Added) != 0 {
			t.Errorf("%s: a freshly created .env needed %v", s, m.Added)
		}
	}
}

func TestTargetTag(t *testing.T) {
	cases := []struct{ req, cli, want string }{
		{"", "0.5.0", "0.5.0"},
		{"", "dev", "latest"},
		{"v0.5.1", "0.5.0", "0.5.1"},
		{"0.4.21", "dev", "0.4.21"},
	}
	for _, c := range cases {
		if got, err := TargetTag(c.req, c.cli); err != nil || got != c.want {
			t.Errorf("TargetTag(%q, %q) = %q, %v; want %q", c.req, c.cli, got, err, c.want)
		}
	}
	if _, err := TargetTag("latest-ish", "0.5.0"); err == nil {
		t.Error("a malformed --version was accepted")
	}
}
