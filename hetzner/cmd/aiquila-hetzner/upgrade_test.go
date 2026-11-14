// SPDX-License-Identifier: MIT

package main

import (
	"strings"
	"testing"
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
