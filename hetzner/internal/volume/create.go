package volume

import (
	"context"
	"fmt"

	"github.com/elgorro/aiquila/hetzner/internal/provision"
	"github.com/hetznercloud/hcloud-go/v2/hcloud"
	xssh "golang.org/x/crypto/ssh"
)

// Create creates a Hetzner Cloud Volume in the same location as srv,
// attaches it to the server, and returns the volume and its device path.
func Create(ctx context.Context, client *hcloud.Client, srv *hcloud.Server, name string, sizeGB int) (*hcloud.Volume, string, error) {
	fmt.Printf("  Creating volume %q (%d GB) in %s\n", name, sizeGB, srv.Datacenter.Location.Name)

	result, _, err := client.Volume.Create(ctx, hcloud.VolumeCreateOpts{
		Name:     name,
		Size:     sizeGB,
		Location: srv.Datacenter.Location,
	})
	if err != nil {
		return nil, "", fmt.Errorf("create volume: %w", err)
	}
	vol := result.Volume
	fmt.Printf("  Created volume %q (id=%d)\n", vol.Name, vol.ID)

	if result.Action != nil {
		if err := client.Action.WaitForFunc(ctx, nil, result.Action); err != nil {
			return nil, "", fmt.Errorf("wait for volume create: %w", err)
		}
	}

	fmt.Printf("  Attaching volume to server %q\n", srv.Name)
	attachAction, _, err := client.Volume.Attach(ctx, vol, srv)
	if err != nil {
		return nil, "", fmt.Errorf("attach volume: %w", err)
	}
	if err := client.Action.WaitForFunc(ctx, nil, attachAction); err != nil {
		return nil, "", fmt.Errorf("wait for volume attach: %w", err)
	}
	fmt.Println("  Volume attached.")

	devicePath := fmt.Sprintf("/dev/disk/by-id/scsi-0HC_Volume_%d", vol.ID)
	return vol, devicePath, nil
}

// FormatAndMount formats the device as ext4 and mounts it at /opt/aiquila.
// Adds a persistent fstab entry using the by-id device path.
func FormatAndMount(sshClient *xssh.Client, devicePath string) error {
	fmt.Println("  Formatting volume as ext4...")

	cmds := []struct {
		desc string
		cmd  string
	}{
		{"mkfs.ext4", fmt.Sprintf("mkfs.ext4 -F %s", devicePath)},
		{"mount", fmt.Sprintf("mount %s /opt/aiquila", devicePath)},
		{"fstab", fmt.Sprintf(
			"echo '%s /opt/aiquila ext4 defaults,nofail 0 2' >> /etc/fstab",
			devicePath,
		)},
	}

	for _, step := range cmds {
		out, err := provision.RunCommand(sshClient, step.cmd)
		if err != nil {
			return fmt.Errorf("volume setup (%s): %w\noutput: %s", step.desc, err, out)
		}
	}

	fmt.Println("  Volume mounted at /opt/aiquila.")
	return nil
}

// SetupLUKS encrypts the device with LUKS, formats as ext4, and mounts at /opt/aiquila.
//
// EXPERIMENTAL: The LUKS key is stored at /root/.luks/aiquila.key on the unencrypted
// root disk. This protects against Hetzner volume snapshots/transfer but NOT against
// root disk access. See the printed warning for details.
func SetupLUKS(sshClient *xssh.Client, devicePath string) error {
	fmt.Println("  [EXPERIMENTAL] Setting up LUKS encryption...")

	const (
		keyFile     = "/root/.luks/aiquila.key"
		mapperName  = "aiquila_data"
		mapperDev   = "/dev/mapper/aiquila_data"
		mountTarget = "/opt/aiquila"
	)

	cmds := []struct {
		desc string
		cmd  string
	}{
		{
			"create key dir",
			"mkdir -p /root/.luks && chmod 700 /root/.luks",
		},
		{
			"generate LUKS key",
			fmt.Sprintf("openssl rand -base64 4096 > %s && chmod 600 %s", keyFile, keyFile),
		},
		{
			"luksFormat",
			fmt.Sprintf("cryptsetup luksFormat %s %s --batch-mode", devicePath, keyFile),
		},
		{
			"luksOpen",
			fmt.Sprintf("cryptsetup luksOpen %s %s --key-file %s", devicePath, mapperName, keyFile),
		},
		{
			"mkfs.ext4",
			fmt.Sprintf("mkfs.ext4 -F %s", mapperDev),
		},
		{
			"mount",
			fmt.Sprintf("mount %s %s", mapperDev, mountTarget),
		},
		{
			"crypttab",
			fmt.Sprintf(
				"echo '%s %s %s luks,nofail' >> /etc/crypttab",
				mapperName, devicePath, keyFile,
			),
		},
		{
			"fstab",
			fmt.Sprintf(
				"echo '%s %s ext4 defaults,nofail 0 2' >> /etc/fstab",
				mapperDev, mountTarget,
			),
		},
	}

	for _, step := range cmds {
		fmt.Printf("    → %s\n", step.desc)
		out, err := provision.RunCommand(sshClient, step.cmd)
		if err != nil {
			return fmt.Errorf("LUKS setup (%s): %w\noutput: %s", step.desc, err, out)
		}
	}

	fmt.Println("  LUKS volume mounted at /opt/aiquila.")
	fmt.Println()
	fmt.Println("  WARNING [EXPERIMENTAL — LUKS]:")
	fmt.Println("    Key stored at /root/.luks/aiquila.key on the ROOT disk (unencrypted).")
	fmt.Println("    This protects against Hetzner volume snapshots/transfer,")
	fmt.Println("    but NOT against root disk access or server compromise.")
	return nil
}
