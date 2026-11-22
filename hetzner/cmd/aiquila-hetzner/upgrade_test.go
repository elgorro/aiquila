// SPDX-License-Identifier: MIT

package main

import (
	"os"
	"strings"
	"testing"

	"github.com/elgorro/aiquila/hetzner/internal/upgrade"
)

// The state database must be copied as a snapshot, not tarred live with its
// -wal file, and the copy must stay readable by its owner only.
func TestMCPStateBackupSnapshotsTheDatabase(t *testing.T) {
	cmd := mcpStateBackupCmd("/opt/aiquila/backups/x")
	for _, want := range []string{
		"VACUUM INTO",
		"rm -f /tmp/aiq-state/state.db /tmp/aiq-state/state.db-wal /tmp/aiq-state/state.db-shm",
		"chmodSync(d,0o600)",
		"tar -C /tmp/aiq-state -czf - . > /opt/aiquila/backups/x/mcp-state.tar.gz",
	} {
		if !strings.Contains(cmd, want) {
			t.Errorf("backup command lacks %q:\n%s", want, cmd)
		}
	}
	if strings.Contains(cmd, "tar -C /app/state") {
		t.Errorf("backup command tars the live state directory:\n%s", cmd)
	}
}

// The volume is named in the compose file; a default-named one would be a new,
// empty volume, and a restore into it would leave the real state behind.
func TestMCPStateVolumeComesFromTheComposeFile(t *testing.T) {
	for _, c := range []struct {
		file  string
		stack upgrade.Stack
		want  string
	}{
		{"../../docker/mcp/docker-compose.yml", upgrade.StackMCP, "aiq-mcp_state"},
		{"../../docker/full/docker-compose.yml", upgrade.StackFull, "aiq-full_mcp_state"},
	} {
		compose, err := os.ReadFile(c.file)
		if err != nil {
			t.Fatal(err)
		}
		if got := mcpStateVolume(string(compose), c.stack); got != c.want {
			t.Errorf("%s: volume %q, want %q", c.file, got, c.want)
		}
	}
	custom := "volumes:\n  mcp_state:\n    driver: local\n    name: \"my-state\"\n"
	if got := mcpStateVolume(custom, upgrade.StackMCP); got != "my-state" {
		t.Errorf("custom compose: volume %q, want my-state", got)
	}
	if got := mcpStateVolume("", upgrade.StackFull); got != "aiq-full_mcp_state" {
		t.Errorf("no compose: volume %q, want the template default", got)
	}
}

// A rollback must never hand the previous release the state from before the
// upgrade: refresh tokens rotated or revoked since would come back as valid.
// Once the new version has run, the old one starts on an empty volume.
func TestRollbackEmptiesTheStateInsteadOfRestoringIt(t *testing.T) {
	p := &upgradePlan{stack: upgrade.StackMCP, mcpImageID: "sha256:old", mcpStarted: true}
	out := rollbackSteps(p, "/opt/aiquila/backups/x")
	for _, want := range []string{
		"docker compose stop mcp",
		"docker run --rm -v aiq-mcp_state:/s alpine find /s -mindepth 1 -delete",
		"occ user:auth-tokens:delete",
	} {
		if !strings.Contains(out, want) {
			t.Errorf("rollback lacks %q:\n%s", want, out)
		}
	}
	if strings.Contains(out, "mcp-state.tar.gz") {
		t.Errorf("rollback restores the MCP state backup:\n%s", out)
	}
}

// When the new MCP container never started, the state is as the old version
// left it, and the rollback leaves it alone.
func TestRollbackKeepsTheStateTheNewVersionNeverTouched(t *testing.T) {
	p := &upgradePlan{stack: upgrade.StackMCP, mcpImageID: "sha256:old"}
	out := rollbackSteps(p, "/opt/aiquila/backups/x")
	if strings.Contains(out, "-delete") || strings.Contains(out, "mcp-state.tar.gz") {
		t.Errorf("rollback touches untouched state:\n%s", out)
	}
	if !strings.Contains(out, "up -d mcp") {
		t.Errorf("rollback does not restart mcp on the restored .env:\n%s", out)
	}
}

func imagePlanFixture(apply bool) *upgradePlan {
	return &upgradePlan{
		stack:       upgrade.StackFull,
		applyImages: apply,
		running:     map[string]bool{"traefik": true, "nc": true},
		images: []upgrade.ImageChange{
			{Service: "grafana", From: "grafana/grafana:13.0.0", To: "grafana/grafana:13.3.1"},
			{Service: "nc", From: "nextcloud:33-apache", To: "nextcloud:35-apache", Held: "Nextcloud core is upgraded on its own"},
			{Service: "traefik", From: "traefik:v3", To: "traefik:v3.7.19"},
		},
	}
}

func TestImagePlanWithoutImagesKeepsEverything(t *testing.T) {
	out := imagePlan(imagePlanFixture(false))
	for _, want := range []string{
		"traefik: traefik:v3 → traefik:v3.7.19 (kept (--images applies it))",
		"nc: nextcloud:33-apache → nextcloud:35-apache (kept: Nextcloud core is upgraded on its own)",
		"Run again with --images",
	} {
		if !strings.Contains(out, want) {
			t.Errorf("plan lacks %q:\n%s", want, out)
		}
	}
}

func TestImagePlanWithImagesRecreatesOnlyRunningServices(t *testing.T) {
	out := imagePlan(imagePlanFixture(true))
	for _, want := range []string{
		"traefik: traefik:v3 → traefik:v3.7.19 (recreated)",
		"grafana: grafana/grafana:13.0.0 → grafana/grafana:13.3.1 (pinned; the service is not running)",
		"(kept: Nextcloud core is upgraded on its own)",
	} {
		if !strings.Contains(out, want) {
			t.Errorf("plan lacks %q:\n%s", want, out)
		}
	}
	if strings.Contains(out, "Run again with --images") {
		t.Errorf("plan suggests --images although it was given:\n%s", out)
	}
}

func TestRollbackRecreatesTheChangedServices(t *testing.T) {
	p := imagePlanFixture(true)
	p.recreate = []string{"traefik"}
	if out := rollbackSteps(p, "/opt/aiquila/backups/x"); !strings.Contains(out, "docker compose up -d --no-deps traefik") {
		t.Errorf("rollback does not recreate traefik:\n%s", out)
	}
}

func TestPendingServices(t *testing.T) {
	ps := "traefik running \ncrowdsec running healthy\nnc-redis running starting\n"
	got := pendingServices(ps, []string{"traefik", "crowdsec", "nc-redis", "socket-proxy"})
	if strings.Join(got, ",") != "nc-redis,socket-proxy" {
		t.Errorf("pending = %v", got)
	}
}

// Without --images the rendered file names exactly the deployed third-party
// images; with it, the appliable ones move and only running ones are recreated.
func TestSelectImages(t *testing.T) {
	tmpl := upgrade.RenderCompose(upgrade.StackFull, "0.5.5")
	tmplImages, _ := upgrade.ServiceImages(tmpl)
	old := strings.Replace(tmpl, "image: "+tmplImages["traefik"], "image: traefik:v3", 1)
	old = strings.Replace(old, "image: "+tmplImages["nc"]+"\n", "image: nextcloud:33-apache\n", 1)
	old = strings.Replace(old, "image: "+tmplImages["nc-db"], "image: postgres:16", 1)
	old = strings.Replace(old, "image: "+tmplImages["crowdsec"], "image: crowdsecurity/crowdsec:v1.7.0", 1)

	for _, apply := range []bool{false, true} {
		images, err := upgrade.ImageDrift(old, tmpl)
		if err != nil {
			t.Fatal(err)
		}
		p := &upgradePlan{applyImages: apply, images: images, running: map[string]bool{"traefik": true, "nc": true, "nc-db": true}}
		pull, err := p.selectImages(tmpl)
		if err != nil {
			t.Fatal(err)
		}
		got, _ := upgrade.ServiceImages(p.newCompose)
		if got["nc"] != "nextcloud:33-apache" || got["nc-db"] != "postgres:16" {
			t.Errorf("apply=%v: held images moved: nc=%s nc-db=%s", apply, got["nc"], got["nc-db"])
		}
		if !apply {
			if got["traefik"] != "traefik:v3" || got["crowdsec"] != "crowdsecurity/crowdsec:v1.7.0" || len(p.recreate)+len(pull) != 0 {
				t.Errorf("without --images: traefik=%s crowdsec=%s recreate=%v pull=%v", got["traefik"], got["crowdsec"], p.recreate, pull)
			}
			continue
		}
		if got["traefik"] != tmplImages["traefik"] || got["crowdsec"] != tmplImages["crowdsec"] {
			t.Errorf("with --images: traefik=%s crowdsec=%s", got["traefik"], got["crowdsec"])
		}
		if strings.Join(p.recreate, ",") != "traefik" || strings.Join(pull, ",") != tmplImages["traefik"] {
			t.Errorf("with --images: recreate=%v pull=%v (crowdsec is not running)", p.recreate, pull)
		}
	}
}
