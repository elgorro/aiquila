package storagebox

import (
	"fmt"

	"github.com/elgorro/aiquila/hetzner/internal/provision"
	xssh "golang.org/x/crypto/ssh"
)

const (
	mountPoint = "/mnt/storagebox"
	credFile   = "/root/.aiquila-storagebox.cred"
)

// Mount installs cifs-utils, writes a credentials file, mounts the storage
// box share via CIFS, and appends a persistent fstab entry.
func Mount(sshClient *xssh.Client, host, login, password string) error {
	shareUNC := fmt.Sprintf("//%s/backup", host)
	mountOpts := fmt.Sprintf(
		"credentials=%s,uid=0,gid=0,file_mode=0755,dir_mode=0755,vers=3.0",
		credFile,
	)
	fstabOpts := fmt.Sprintf(
		"credentials=%s,uid=0,gid=0,file_mode=0755,dir_mode=0755,vers=3.0,nofail,_netdev",
		credFile,
	)

	cmds := []struct {
		desc string
		cmd  string
	}{
		{
			"install cifs-utils",
			"dnf install -y cifs-utils 2>/dev/null || apt-get install -y -q cifs-utils 2>/dev/null || true",
		},
		{
			"create mountpoint",
			fmt.Sprintf("mkdir -p %s", mountPoint),
		},
		{
			"write credentials",
			fmt.Sprintf(`printf 'username=%s\npassword=%s\n' > %s && chmod 600 %s`,
				login, password, credFile, credFile),
		},
		{
			"mount CIFS",
			fmt.Sprintf("mount -t cifs %s %s -o %s", shareUNC, mountPoint, mountOpts),
		},
		{
			"fstab entry",
			fmt.Sprintf("echo '%s %s cifs %s 0 0' >> /etc/fstab",
				shareUNC, mountPoint, fstabOpts),
		},
	}

	for _, step := range cmds {
		fmt.Printf("    → %s\n", step.desc)
		out, err := provision.RunCommand(sshClient, step.cmd)
		if err != nil {
			return fmt.Errorf("storage box mount (%s): %w\noutput: %s", step.desc, err, out)
		}
	}

	fmt.Printf("  Storage Box mounted at %s.\n", mountPoint)
	return nil
}
