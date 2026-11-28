// SPDX-License-Identifier: MIT

package storagebox

import (
	"strings"
	"testing"

	"github.com/elgorro/aiquila/hetzner/internal/sshtest"
)

func TestMountWritesPrivateCredentialsAndAPersistentEntry(t *testing.T) {
	srv := sshtest.New(t)

	if err := Mount(srv.Client(), "u123.your-storagebox.de", "u123", "s3cret"); err != nil {
		t.Fatal(err)
	}

	cmds := srv.Commands()
	if len(cmds) != 5 {
		t.Fatalf("commands = %q", cmds)
	}
	if want := `printf 'username=u123\npassword=s3cret\n' > /root/.aiquila-storagebox.cred && chmod 600 /root/.aiquila-storagebox.cred`; cmds[2] != want {
		t.Errorf("credentials step = %q", cmds[2])
	}
	if !strings.HasPrefix(cmds[3], "mount -t cifs //u123.your-storagebox.de/backup /mnt/storagebox -o credentials=/root/.aiquila-storagebox.cred,") {
		t.Errorf("mount step = %q", cmds[3])
	}
	// The password must reach only the credentials file, never a mount line.
	for _, c := range []string{cmds[3], cmds[4]} {
		if strings.Contains(c, "s3cret") {
			t.Errorf("password in %q", c)
		}
	}
	// A missing share must not stop the server from booting.
	if !strings.Contains(cmds[4], ",nofail,_netdev 0 0' >> /etc/fstab") {
		t.Errorf("fstab step = %q", cmds[4])
	}
}

func TestMountStopsAtTheFirstFailingStep(t *testing.T) {
	srv := sshtest.New(t)
	srv.Handle = func(cmd string) sshtest.Result {
		if strings.HasPrefix(cmd, "mount ") {
			return sshtest.Result{Output: "mount error(13): Permission denied", Exit: 32}
		}
		return sshtest.Result{}
	}

	err := Mount(srv.Client(), "h", "u", "p")
	if err == nil || !strings.Contains(err.Error(), "mount CIFS") || !strings.Contains(err.Error(), "Permission denied") {
		t.Fatalf("err = %v", err)
	}
	for _, c := range srv.Commands() {
		if strings.Contains(c, "/etc/fstab") {
			t.Error("wrote an fstab entry after the mount failed")
		}
	}
}
