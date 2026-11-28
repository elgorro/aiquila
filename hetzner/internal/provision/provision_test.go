// SPDX-License-Identifier: MIT

package provision

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/elgorro/aiquila/hetzner/internal/sshtest"
	xssh "golang.org/x/crypto/ssh"
)

func TestRunCommandReturnsCombinedOutput(t *testing.T) {
	srv := sshtest.New(t)
	srv.Handle = func(cmd string) sshtest.Result { return sshtest.Result{Output: "hello\n"} }

	out, err := RunCommand(srv.Client(), "echo hello")
	if err != nil || out != "hello\n" {
		t.Fatalf("out %q, err %v", out, err)
	}
	if got := srv.Commands(); len(got) != 1 || got[0] != "echo hello" {
		t.Errorf("commands = %v", got)
	}
}

func TestRunCommandReportsAFailureWithItsOutput(t *testing.T) {
	srv := sshtest.New(t)
	srv.Handle = func(string) sshtest.Result { return sshtest.Result{Output: "no such file", Exit: 2} }

	out, err := RunCommand(srv.Client(), "cat /missing")
	if err == nil || out != "no such file" {
		t.Fatalf("out %q, err %v", out, err)
	}
	if !strings.Contains(err.Error(), `"cat /missing"`) || !strings.Contains(err.Error(), "no such file") {
		t.Errorf("err = %v", err)
	}
}

func TestStreamCommandTreatsCtrlCAsACleanExit(t *testing.T) {
	srv := sshtest.New(t)
	srv.Handle = func(cmd string) sshtest.Result {
		if strings.Contains(cmd, "logs") {
			return sshtest.Result{Exit: 130}
		}
		return sshtest.Result{Exit: 1}
	}
	client := srv.Client()

	if err := StreamCommand(client, "docker compose logs -f"); err != nil {
		t.Errorf("exit 130: err = %v", err)
	}
	if err := StreamCommand(client, "false"); err == nil {
		t.Error("exit 1: no error")
	}
}

func TestWaitCloudInitChecksTheSentinel(t *testing.T) {
	srv := sshtest.New(t)

	if err := WaitCloudInit(srv.Client()); err != nil {
		t.Fatal(err)
	}
	if got := srv.Commands(); len(got) != 1 || got[0] != "test -f /var/lib/cloud/instance/boot-finished && echo ok" {
		t.Errorf("commands = %v", got)
	}
}

// keyPair generates a key into a fresh home and returns its paths.
func keyPair(t *testing.T) *KeyPair {
	t.Helper()
	t.Setenv("HOME", t.TempDir())
	kp, err := GenerateKeyPair()
	if err != nil {
		t.Fatal(err)
	}
	return kp
}

func TestGenerateKeyPairWritesAnEd25519PairPrivately(t *testing.T) {
	kp := keyPair(t)

	if filepath.Base(kp.PrivatePath) != "aiquila_ed25519" || kp.PublicPath != kp.PrivatePath+".pub" {
		t.Errorf("paths = %+v", kp)
	}
	info, err := os.Stat(kp.PrivatePath)
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() != 0o600 {
		t.Errorf("private key mode = %o", info.Mode().Perm())
	}
	if !strings.HasPrefix(kp.PublicKey, "ssh-ed25519 ") {
		t.Errorf("public key = %q", kp.PublicKey)
	}
	pub, err := LoadPublicKey(kp.PublicPath)
	if err != nil || pub != kp.PublicKey {
		t.Errorf("LoadPublicKey = %q, %v", pub, err)
	}
	if _, err := PrivateKeyAuth(kp.PrivatePath); err != nil {
		t.Errorf("PrivateKeyAuth: %v", err)
	}
}

// answer feeds s to the prompt GenerateKeyPair shows for an existing key.
func answer(t *testing.T, s string) {
	t.Helper()
	r, w, err := os.Pipe()
	if err != nil {
		t.Fatal(err)
	}
	_, _ = w.WriteString(s)
	_ = w.Close()
	old := os.Stdin
	os.Stdin = r
	t.Cleanup(func() { os.Stdin = old })
}

func TestGenerateKeyPairReusesAnExistingKeyByDefault(t *testing.T) {
	first := keyPair(t)
	answer(t, "\n")

	again, err := GenerateKeyPair()
	if err != nil {
		t.Fatal(err)
	}
	if again.PublicKey != first.PublicKey {
		t.Error("the default answer replaced the existing key")
	}
}

func TestGenerateKeyPairReplacesTheKeyWhenToldTo(t *testing.T) {
	first := keyPair(t)
	answer(t, "n\n")

	again, err := GenerateKeyPair()
	if err != nil {
		t.Fatal(err)
	}
	if again.PublicKey == first.PublicKey {
		t.Error("answering n kept the old key")
	}
}

func TestKeyFilesThatCannotBeReadFail(t *testing.T) {
	dir := t.TempDir()
	if _, err := LoadPublicKey(filepath.Join(dir, "missing.pub")); err == nil {
		t.Error("LoadPublicKey: no error for a missing file")
	}
	if _, err := PrivateKeyAuth(filepath.Join(dir, "missing")); err == nil {
		t.Error("PrivateKeyAuth: no error for a missing file")
	}
	bad := filepath.Join(dir, "bad")
	_ = os.WriteFile(bad, []byte("not a key"), 0o600)
	if _, err := PrivateKeyAuth(bad); err == nil {
		t.Error("PrivateKeyAuth: no error for an unparsable key")
	}
}

func TestWaitAndDialConnectsAsRootWithTheKey(t *testing.T) {
	kp := keyPair(t)
	srv := sshtest.New(t)
	var gotAddr, gotUser string
	old := Dial
	Dial = func(network, addr string, cfg *xssh.ClientConfig) (*xssh.Client, error) {
		gotAddr, gotUser = addr, cfg.User
		return srv.Dial(network, addr, cfg)
	}
	t.Cleanup(func() { Dial = old })

	client, err := WaitAndDial("2001:db8::5", kp.PrivatePath)
	if err != nil {
		t.Fatal(err)
	}
	defer client.Close()
	if gotAddr != "[2001:db8::5]:22" || gotUser != "root" {
		t.Errorf("dialled %s as %s", gotAddr, gotUser)
	}
}

func TestUploaderCreatesParentsAndSetsModes(t *testing.T) {
	srv := sshtest.New(t)
	up, err := NewUploader(srv.Client())
	if err != nil {
		t.Fatal(err)
	}
	defer up.Close()

	if err := up.WriteFile("/opt/aiquila/monitoring/prometheus.yml", "scrape_configs: []\n"); err != nil {
		t.Fatal(err)
	}
	if err := up.Chmod("/opt/aiquila/monitoring/prometheus.yml", 0o640); err != nil {
		t.Fatal(err)
	}

	got, err := srv.ReadFile("/opt/aiquila/monitoring/prometheus.yml")
	if err != nil || got != "scrape_configs: []\n" {
		t.Errorf("content %q, err %v", got, err)
	}
	if mode, ok := srv.Mode("/opt/aiquila/monitoring/prometheus.yml"); !ok || mode != 0o640 {
		t.Errorf("mode = %o (set: %v)", mode, ok)
	}
}
