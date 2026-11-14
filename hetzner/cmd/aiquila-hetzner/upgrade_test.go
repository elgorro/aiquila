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
