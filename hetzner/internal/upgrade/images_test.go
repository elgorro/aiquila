// SPDX-License-Identifier: MIT

package upgrade

import (
	"strings"
	"testing"
)

// A compose file as an older CLI rendered it: floating and older pins.
const oldFullCompose = `services:
  nc:
    image: nextcloud:33-apache
  nc-cron:
    image: nextcloud:33-apache
  nc-db:
    image: postgres:18.1
  nc-redis:
    image: redis:8-alpine
  mcp:
    image: ghcr.io/elgorro/aiquila-mcp:0.5.3
  socket-proxy:
    image: tecnativa/docker-socket-proxy:v0.5.0  # deprecated upstream
  traefik:
    image: traefik:v3
  crowdsec:
    image: crowdsecurity/crowdsec:v1.8.2
`

func TestImageDriftAgainstTheFullTemplate(t *testing.T) {
	drift, err := ImageDrift(oldFullCompose, RenderCompose(StackFull, "0.5.5"))
	if err != nil {
		t.Fatal(err)
	}
	got := map[string]ImageChange{}
	for _, d := range drift {
		got[d.Service] = d
	}
	tmpl, _ := ServiceImages(Template(StackFull))
	for _, svc := range []string{"traefik", "socket-proxy", "nc-redis"} {
		d, ok := got[svc]
		if !ok || d.To != tmpl[svc] || d.Held != "" {
			t.Errorf("%s: drift %+v, want an appliable change to %s", svc, d, tmpl[svc])
		}
	}
	for _, svc := range []string{"nc", "nc-cron"} {
		if d := got[svc]; d.Held == "" {
			t.Errorf("%s: Nextcloud core must be held, got %+v", svc, d)
		}
	}
	if d := got["nc-db"]; d.Held != "" {
		t.Errorf("nc-db: a PostgreSQL minor (%s → %s) is appliable, got held: %s", d.From, d.To, d.Held)
	}
	if _, ok := got["mcp"]; ok {
		t.Error("the MCP image is pinned on its own and must not be listed")
	}
	if _, ok := got["crowdsec"]; ok && tmpl["crowdsec"] == "crowdsecurity/crowdsec:v1.8.2" {
		t.Error("an unchanged image is listed")
	}
}

func TestHeldChanges(t *testing.T) {
	for _, c := range []struct {
		from, to string
		held     bool
	}{
		{"postgres:16", "postgres:18.7", true},
		{"postgres:18.1-alpine", "postgres:18.7", false},
		{"postgres:latest", "postgres:18.7", true},
		{"postgres:18.7", "mariadb:11", true},
		{"nextcloud:35-apache", "nextcloud:35.0.3-apache", true},
		{"traefik:v3", "traefik:v3.7.19", false},
		{"redis:7-alpine", "redis:8.10.3-alpine", false},
		{"tecnativa/docker-socket-proxy:v0.5.0", "tecnativa/docker-socket-proxy:3.4", false},
	} {
		if got := held(c.from, c.to) != ""; got != c.held {
			t.Errorf("held(%s → %s) = %v, want %v", c.from, c.to, got, c.held)
		}
	}
}

func TestImageRefParts(t *testing.T) {
	for _, c := range []struct{ in, repo, tag string }{
		{"traefik:v3.7.19", "traefik", "v3.7.19"},
		{"redis", "redis", "latest"},
		{"localhost:5000/app:1.2", "localhost:5000/app", "1.2"},
		{"localhost:5000/app", "localhost:5000/app", "latest"},
		{"postgres:18.7@sha256:abc", "postgres", "18.7"},
	} {
		if r, tg := imageRepo(c.in), imageTag(c.in); r != c.repo || tg != c.tag {
			t.Errorf("%s: repo %q tag %q, want %q %q", c.in, r, tg, c.repo, c.tag)
		}
	}
}

func TestPinImagesKeepsTheRestOfTheFile(t *testing.T) {
	out, err := PinImages(oldFullCompose, map[string]string{
		"socket-proxy": "tecnativa/docker-socket-proxy:3.4",
		"traefik":      "traefik:v3.7.19",
	})
	if err != nil {
		t.Fatal(err)
	}
	want := strings.Replace(oldFullCompose, "docker-socket-proxy:v0.5.0  # deprecated upstream", "docker-socket-proxy:3.4  # deprecated upstream", 1)
	want = strings.Replace(want, "image: traefik:v3\n", "image: traefik:v3.7.19\n", 1)
	if out != want {
		t.Fatalf("got:\n%s\nwant:\n%s", out, want)
	}
	if _, err := PinImages(oldFullCompose, map[string]string{"nope": "x:1"}); err == nil {
		t.Error("an unknown service was accepted")
	}
}

// Holding every drifted image puts the template back on the deployed pins,
// so an upgrade without --images changes no third-party image.
func TestHoldingEveryChangeRestoresTheDeployedImages(t *testing.T) {
	for _, s := range []Stack{StackMCP, StackNextcloud, StackFull} {
		tmpl := RenderCompose(s, "0.5.5")
		old := strings.ReplaceAll(tmpl, "image: traefik:", "image: traefik:v3 #")
		drift, err := ImageDrift(old, tmpl)
		if err != nil {
			t.Fatal(err)
		}
		keep := map[string]string{}
		for _, d := range drift {
			keep[d.Service] = d.From
		}
		out, err := PinImages(tmpl, keep)
		if err != nil {
			t.Fatal(err)
		}
		again, _ := ImageDrift(old, out)
		if len(again) != 0 {
			t.Errorf("%s: still drifts after holding: %+v", s, again)
		}
	}
}
