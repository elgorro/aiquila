// SPDX-License-Identifier: MIT

package main

import (
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/elgorro/aiquila/hetzner/internal/hcloudtest"
	"github.com/elgorro/aiquila/hetzner/internal/provision"
	"github.com/elgorro/aiquila/hetzner/internal/sshtest"
	"github.com/hetznercloud/hcloud-go/v2/hcloud"
)

// fakeHetzner isolates a command from the machine: an empty home, a fake API
// that hcloudclient.NewClient reaches through HCLOUD_ENDPOINT, and a no-op log.
func fakeHetzner(t *testing.T) *hcloudtest.API {
	t.Helper()
	t.Setenv("HOME", t.TempDir())
	t.Setenv("HCLOUD_TOKEN", "test-token")
	api := hcloudtest.New(t)
	t.Setenv("HCLOUD_ENDPOINT", api.URL())
	old := appLog
	appLog, _ = openLog("", "test")
	t.Cleanup(func() { appLog = old })
	return api
}

// fakeSSH answers provision.WaitAndDial with an in-process server and gives
// the command a key to dial with.
func fakeSSH(t *testing.T) (*sshtest.Server, string) {
	t.Helper()
	srv := sshtest.New(t)
	old := provision.Dial
	provision.Dial = srv.Dial
	t.Cleanup(func() { provision.Dial = old })
	kp, err := provision.GenerateKeyPair()
	if err != nil {
		t.Fatal(err)
	}
	return srv, kp.PrivatePath
}

// stdout captures what f prints.
func stdout(t *testing.T, f func() error) (string, error) {
	t.Helper()
	r, w, err := os.Pipe()
	if err != nil {
		t.Fatal(err)
	}
	old := os.Stdout
	os.Stdout = w
	done := make(chan string)
	go func() {
		b, _ := io.ReadAll(r)
		done <- string(b)
	}()
	runErr := f()
	_ = w.Close()
	os.Stdout = old
	return <-done, runErr
}

func serverJSON(id int64, name, status string) map[string]any {
	return map[string]any{"id": id, "name": name, "status": status, "created": "2026-01-01T00:00:00Z", "labels": map[string]any{},
		"server_type": map[string]any{"id": 1, "name": "cpx21"}, "location": map[string]any{"id": 1, "name": "nbg1"},
		"public_net": map[string]any{"ipv4": map[string]any{"ip": "203.0.113.10"}, "ipv6": map[string]any{"ip": "2001:db8::/64"}, "floating_ips": []any{}}}
}

// servers answers GET /servers?name=… with srvs whose name matches.
func servers(api *hcloudtest.API, srvs ...map[string]any) {
	api.Handle("GET /servers", func(r hcloudtest.Request) (int, any) {
		out := []any{}
		for _, s := range srvs {
			if name := r.Query.Get("name"); name == "" || s["name"] == name {
				out = append(out, s)
			}
		}
		return http.StatusOK, map[string]any{"servers": out}
	})
}

// ── pure helpers ──────────────────────────────────────────────────────────────

func TestServerStatusIcon(t *testing.T) {
	for status, want := range map[hcloud.ServerStatus]string{
		hcloud.ServerStatusRunning:  "● running",
		hcloud.ServerStatusOff:      "○ off",
		hcloud.ServerStatusStarting: "⚙ starting",
		"deleting":                  "deleting",
	} {
		if got := serverStatusIcon(status); got != want {
			t.Errorf("serverStatusIcon(%q) = %q, want %q", status, got, want)
		}
	}
}

func TestParseNetworkZone(t *testing.T) {
	if z, err := parseNetworkZone("us-west"); err != nil || z != hcloud.NetworkZoneUSWest {
		t.Errorf("us-west: %q, %v", z, err)
	}
	if _, err := parseNetworkZone("eu-west"); err == nil || !strings.Contains(err.Error(), "valid: eu-central") {
		t.Errorf("eu-west: err = %v", err)
	}
}

func TestMergeStringPrefersTheFlag(t *testing.T) {
	if mergeString("flag", "file") != "flag" || mergeString("", "file") != "file" {
		t.Error("mergeString does not prefer a set flag over the file")
	}
}

func TestExpandHome(t *testing.T) {
	t.Setenv("HOME", "/home/op")
	for in, want := range map[string]string{
		"~/.ssh/key": "/home/op/.ssh/key",
		"/etc/key":   "/etc/key",
		"relative/k": "relative/k",
	} {
		if got := expandHome(in); got != want {
			t.Errorf("expandHome(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestLoadDeployConfigReadsYAMLAndJSON(t *testing.T) {
	dir := t.TempDir()
	yml := filepath.Join(dir, "deploy.yaml")
	_ = os.WriteFile(yml, []byte("name: aiq\ndomain: mcp.example.com\nmonitoring: true\nlabels: [env=prod]\n"), 0o600)
	js := filepath.Join(dir, "deploy.JSON")
	_ = os.WriteFile(js, []byte(`{"name":"aiq","nc_url":"https://cloud.example.com","volume_size":20}`), 0o600)

	y, err := loadDeployConfig(yml)
	if err != nil {
		t.Fatal(err)
	}
	if y.Name != "aiq" || y.Domain != "mcp.example.com" || !y.Monitoring || len(y.Labels) != 1 {
		t.Errorf("yaml = %+v", y)
	}
	j, err := loadDeployConfig(js)
	if err != nil {
		t.Fatal(err)
	}
	if j.NCURL != "https://cloud.example.com" || j.VolumeSize != 20 {
		t.Errorf("json = %+v", j)
	}

	bad := filepath.Join(dir, "bad.json")
	_ = os.WriteFile(bad, []byte("name: not json"), 0o600)
	if _, err := loadDeployConfig(bad); err == nil {
		t.Error("parsed YAML in a .json file")
	}
	if _, err := loadDeployConfig(filepath.Join(dir, "missing.yaml")); err == nil {
		t.Error("no error for a missing file")
	}
}

// ── commands that only use the API ────────────────────────────────────────────

func TestListPrintsEachServer(t *testing.T) {
	api := fakeHetzner(t)
	servers(api, serverJSON(1, "aiq-a", "running"), serverJSON(2, "aiq-b", "off"))
	listToken, listLabel = "", ""

	out, err := stdout(t, func() error { return runList(nil, nil) })
	if err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{"aiq-a", "● running", "aiq-b", "○ off", "203.0.113.10", "cpx21", "nbg1"} {
		if !strings.Contains(out, want) {
			t.Errorf("output lacks %q:\n%s", want, out)
		}
	}
}

func TestListPassesTheLabelSelector(t *testing.T) {
	api := fakeHetzner(t)
	servers(api)
	listToken, listLabel = "", "env=prod"
	t.Cleanup(func() { listLabel = "" })

	out, err := stdout(t, func() error { return runList(nil, nil) })
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(out, "No servers found.") {
		t.Errorf("output = %q", out)
	}
	if sel := api.Last("GET /servers").Query.Get("label_selector"); sel != "env=prod" {
		t.Errorf("label_selector = %q", sel)
	}
}

func TestStopShutsDownGracefullyUnlessHard(t *testing.T) {
	api := fakeHetzner(t)
	servers(api, serverJSON(1, "aiq", "running"))
	api.JSON("POST /servers/1/actions/shutdown", map[string]any{"action": hcloudtest.Action(1, "shutdown_server")})
	api.JSON("POST /servers/1/actions/poweroff", map[string]any{"action": hcloudtest.Action(2, "stop_server")})
	stopName, stopToken = "aiq", ""
	t.Cleanup(func() { stopHard = false })

	stopHard = false
	if _, err := stdout(t, func() error { return runStop(nil, nil) }); err != nil {
		t.Fatal(err)
	}
	stopHard = true
	if _, err := stdout(t, func() error { return runStop(nil, nil) }); err != nil {
		t.Fatal(err)
	}
	if api.Called("POST /servers/1/actions/shutdown") != 1 || api.Called("POST /servers/1/actions/poweroff") != 1 {
		t.Errorf("requests = %v", api.Requests())
	}
}

func TestStartAndRestartFailOnAnUnknownServer(t *testing.T) {
	fakeHetzner(t).JSON("GET /servers", map[string]any{"servers": []any{}})
	startName, restartName = "ghost", "ghost"

	for name, run := range map[string]func() error{
		"start":   func() error { return runStart(nil, nil) },
		"restart": func() error { return runRestart(nil, nil) },
	} {
		_, err := stdout(t, run)
		if err == nil || !strings.Contains(err.Error(), `server "ghost" not found`) {
			t.Errorf("%s: err = %v", name, err)
		}
	}
}

func TestDeleteRemovesTheServerAndEverythingNamedAfterIt(t *testing.T) {
	api := fakeHetzner(t)
	servers(api, serverJSON(1, "aiq", "running"))
	api.JSON("DELETE /servers/1", map[string]any{"action": hcloudtest.Action(1, "delete_server")})
	api.JSON("GET /firewalls", map[string]any{"firewalls": []any{map[string]any{"id": 3, "name": "aiq-fw", "rules": []any{}, "applied_to": []any{}, "labels": map[string]any{}, "created": "2026-01-01T00:00:00Z"}}})
	api.Handle("DELETE /firewalls/3", func(hcloudtest.Request) (int, any) { return http.StatusNoContent, nil })
	api.JSON("GET /ssh_keys", map[string]any{"ssh_keys": []any{}})
	api.JSON("GET /volumes", map[string]any{"volumes": []any{map[string]any{"id": 8, "name": "aiq-vol", "size": 20, "labels": map[string]any{}, "created": "2026-01-01T00:00:00Z", "status": "available", "location": map[string]any{"id": 1, "name": "nbg1"}}}})
	api.Handle("DELETE /volumes/8", func(hcloudtest.Request) (int, any) { return http.StatusNoContent, nil })
	api.JSON("GET /zones/example.com", map[string]any{"zone": map[string]any{"id": 5, "name": "example.com"}})
	api.NotFound("GET /zones/5/rrsets/aiq/A")
	api.NotFound("GET /zones/5/rrsets/aiq/AAAA")
	deleteName, deleteToken, deleteDNSZone = "aiq", "", "example.com"
	t.Cleanup(func() { deleteDNSZone = "" })

	out, err := stdout(t, func() error { return runDelete(nil, nil) })
	if err != nil {
		t.Fatal(err)
	}
	for _, route := range []string{"DELETE /servers/1", "DELETE /firewalls/3", "DELETE /volumes/8"} {
		if api.Called(route) != 1 {
			t.Errorf("%s not called; requests %v", route, api.Requests())
		}
	}
	if q := api.Last("GET /volumes").Query.Get("name"); q != "aiq-vol" {
		t.Errorf("looked up volume %q", q)
	}
	if !strings.Contains(out, `SSH key "aiq-key" not found — skipping`) {
		t.Errorf("output = %s", out)
	}
}

func TestNetworkDeleteOfAMissingNetworkIsNotAnError(t *testing.T) {
	fakeHetzner(t).JSON("GET /networks", map[string]any{"networks": []any{}})
	netDeleteName, netDeleteToken = "aiq-net", ""

	out, err := stdout(t, func() error { return runNetworkDelete(nil, nil) })
	if err != nil || !strings.Contains(out, "nothing to delete") {
		t.Fatalf("out %q, err %v", out, err)
	}
}

func TestSnapshotListFiltersByServer(t *testing.T) {
	api := fakeHetzner(t)
	api.JSON("GET /images", map[string]any{"images": []any{
		map[string]any{"id": 1, "type": "snapshot", "description": "keep", "image_size": 2.5, "created": "2026-01-01T00:00:00Z", "created_from": map[string]any{"id": 1, "name": "aiq"}},
		map[string]any{"id": 2, "type": "snapshot", "description": "other", "image_size": 1.0, "created": "2026-01-01T00:00:00Z", "created_from": map[string]any{"id": 9, "name": "else"}},
	}})
	snapListName, snapListToken = "aiq", ""
	t.Cleanup(func() { snapListName = "" })

	out, err := stdout(t, func() error { return runSnapshotList(nil, nil) })
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(out, "keep") || strings.Contains(out, "other") {
		t.Errorf("output =\n%s", out)
	}
	if typ := api.Last("GET /images").Query.Get("type"); typ != "snapshot" {
		t.Errorf("type = %q", typ)
	}
}

// ── commands that also use SSH ────────────────────────────────────────────────

func TestDeployIncludesMonitoringWhenItRuns(t *testing.T) {
	api := fakeHetzner(t)
	servers(api, serverJSON(1, "aiq", "running"))
	ssh, key := fakeSSH(t)
	ssh.Handle = func(cmd string) sshtest.Result {
		if strings.HasPrefix(cmd, "docker ps -q --filter name=aiq-grafana") {
			return sshtest.Result{Output: "abc123\n"}
		}
		return sshtest.Result{}
	}
	deployName, deployToken, deploySSHKey = "aiq", "", key

	if _, err := stdout(t, func() error { return runDeploy(nil, nil) }); err != nil {
		t.Fatal(err)
	}
	cmds := ssh.Commands()
	want := []string{
		"docker ps -q --filter name=aiq-grafana 2>/dev/null",
		"cd /opt/aiquila && docker compose --profile monitoring pull 2>&1",
		"cd /opt/aiquila && docker compose --profile monitoring up -d 2>&1",
	}
	if strings.Join(cmds, "\n") != strings.Join(want, "\n") {
		t.Errorf("commands =\n%s", strings.Join(cmds, "\n"))
	}
}

func TestDeployStopsWhenThePullFails(t *testing.T) {
	api := fakeHetzner(t)
	servers(api, serverJSON(1, "aiq", "running"))
	ssh, key := fakeSSH(t)
	ssh.Handle = func(cmd string) sshtest.Result {
		if strings.Contains(cmd, " pull ") {
			return sshtest.Result{Output: "manifest unknown", Exit: 1}
		}
		return sshtest.Result{}
	}
	deployName, deployToken, deploySSHKey = "aiq", "", key

	_, err := stdout(t, func() error { return runDeploy(nil, nil) })
	if err == nil || !strings.Contains(err.Error(), "docker compose pull") {
		t.Fatalf("err = %v", err)
	}
	for _, c := range ssh.Commands() {
		if strings.Contains(c, " up -d") {
			t.Error("restarted the stack after the pull failed")
		}
	}
}

func TestRebuildKeepsTheCredentialKeyAndLocksDownTheEnvFile(t *testing.T) {
	api := fakeHetzner(t)
	servers(api, serverJSON(1, "aiq", "running"))
	ssh, key := fakeSSH(t)
	oldKey := strings.Repeat("cd", 64)
	ssh.Handle = func(cmd string) sshtest.Result {
		if strings.HasPrefix(cmd, "cat /opt/aiquila/.env") {
			return sshtest.Result{Output: "MCP_AUTH_SECRET=x\nMCP_CREDENTIAL_KEY=" + oldKey + "\n"}
		}
		return sshtest.Result{}
	}
	// Building the command resets the flag variables, so build it first.
	cmd := buildRebuildCmd()
	rebuildName, rebuildDomain, rebuildNCURL, rebuildSSHKey = "aiq", "mcp.example.com", "https://cloud.example.com", key
	rebuildConfig, rebuildToken, rebuildNCUser, rebuildNCPassword, rebuildAcmeEmail = "", "", "", "", "ops@example.com"
	t.Cleanup(func() { rebuildName, rebuildDomain, rebuildNCURL, rebuildSSHKey, rebuildAcmeEmail = "", "", "", "", "" })

	if _, err := stdout(t, func() error { return runRebuild(cmd, nil) }); err != nil {
		t.Fatal(err)
	}

	env, err := ssh.ReadFile("/opt/aiquila/.env")
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(env, "MCP_CREDENTIAL_KEY="+oldKey+"\n") {
		t.Error("rebuild replaced MCP_CREDENTIAL_KEY; the stored app passwords could no longer be decrypted")
	}
	if !strings.Contains(env, "MCP_DOMAIN=mcp.example.com\n") {
		t.Errorf(".env =\n%s", env)
	}
	if mode, ok := ssh.Mode("/opt/aiquila/.env"); !ok || mode != 0o600 {
		t.Errorf(".env mode = %o (set: %v)", mode, ok)
	}
	if traefik, _ := ssh.ReadFile("/opt/aiquila/traefik.yml"); !strings.Contains(traefik, "ops@example.com") || strings.Contains(traefik, "${ACME_EMAIL}") {
		t.Error("traefik.yml does not carry the ACME email")
	}
	if _, err := ssh.ReadFile("/opt/aiquila/monitoring/prometheus.yml"); err == nil {
		t.Error("uploaded the Prometheus config without --monitoring")
	}
	cmds := ssh.Commands()
	if last := cmds[len(cmds)-1]; last != "cd /opt/aiquila && docker compose pull && docker compose up -d --remove-orphans 2>&1" {
		t.Errorf("last command = %q", last)
	}
}

func TestRebuildNamesEveryMissingValue(t *testing.T) {
	fakeHetzner(t)
	cmd := buildRebuildCmd()
	t.Setenv("NEXTCLOUD_URL", "")

	err := runRebuild(cmd, nil)
	if err == nil {
		t.Fatal("no error")
	}
	for _, want := range []string{"--name", "--domain", "--nc-url", "--ssh-key"} {
		if !strings.Contains(err.Error(), want) {
			t.Errorf("error lacks %s: %v", want, err)
		}
	}
}
