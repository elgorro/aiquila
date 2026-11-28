// SPDX-License-Identifier: MIT

package volume

import (
	"context"
	"net/http"
	"strings"
	"testing"

	"github.com/elgorro/aiquila/hetzner/internal/hcloudtest"
	"github.com/elgorro/aiquila/hetzner/internal/sshtest"
	"github.com/hetznercloud/hcloud-go/v2/hcloud"
)

func TestCreateAttachesTheVolumeInTheServersLocation(t *testing.T) {
	api := hcloudtest.New(t)
	api.Handle("POST /volumes", func(hcloudtest.Request) (int, any) {
		return http.StatusCreated, map[string]any{
			"volume": map[string]any{"id": 42, "name": "aiq-vol", "size": 20, "created": "2026-01-01T00:00:00Z", "labels": map[string]any{}, "status": "available", "location": map[string]any{"id": 1, "name": "nbg1"}},
			"action": hcloudtest.Action(1, "create_volume"), "next_actions": []any{},
		}
	})
	api.JSON("POST /volumes/42/actions/attach", map[string]any{"action": hcloudtest.Action(2, "attach_volume")})

	srv := &hcloud.Server{ID: 7, Name: "aiq", Location: &hcloud.Location{ID: 1, Name: "nbg1"}}
	vol, device, err := Create(context.Background(), api.Client(), srv, "aiq-vol", 20, map[string]string{"app": "aiquila"})
	if err != nil {
		t.Fatal(err)
	}
	if vol.ID != 42 || device != "/dev/disk/by-id/scsi-0HC_Volume_42" {
		t.Errorf("vol %d, device %q", vol.ID, device)
	}
	body := api.Last("POST /volumes").Body
	if body["name"] != "aiq-vol" || body["size"] != float64(20) || body["location"] != float64(1) {
		t.Errorf("create body = %v", body)
	}
	if s := api.Last("POST /volumes/42/actions/attach").Body["server"]; s != float64(7) {
		t.Errorf("attached to server %v", s)
	}
}

func TestFormatAndMountUsesTheStableDevicePath(t *testing.T) {
	srv := sshtest.New(t)

	if err := FormatAndMount(srv.Client(), "/dev/disk/by-id/scsi-0HC_Volume_42"); err != nil {
		t.Fatal(err)
	}
	want := []string{
		"mkfs.ext4 -F /dev/disk/by-id/scsi-0HC_Volume_42",
		"mount /dev/disk/by-id/scsi-0HC_Volume_42 /opt/aiquila",
		"echo '/dev/disk/by-id/scsi-0HC_Volume_42 /opt/aiquila ext4 defaults,nofail 0 2' >> /etc/fstab",
	}
	if got := srv.Commands(); strings.Join(got, "\n") != strings.Join(want, "\n") {
		t.Errorf("commands =\n%s", strings.Join(got, "\n"))
	}
}

func TestSetupLUKSOpensTheMapperBeforeFormatting(t *testing.T) {
	srv := sshtest.New(t)

	if err := SetupLUKS(srv.Client(), "/dev/sdb"); err != nil {
		t.Fatal(err)
	}
	cmds := strings.Join(srv.Commands(), "\n")
	order := []string{
		"chmod 700 /root/.luks",
		"chmod 600 /root/.luks/aiquila.key",
		"cryptsetup luksFormat /dev/sdb /root/.luks/aiquila.key --batch-mode",
		"cryptsetup luksOpen /dev/sdb aiquila_data --key-file /root/.luks/aiquila.key",
		"mkfs.ext4 -F /dev/mapper/aiquila_data",
		"mount /dev/mapper/aiquila_data /opt/aiquila",
		"echo 'aiquila_data /dev/sdb /root/.luks/aiquila.key luks,nofail' >> /etc/crypttab",
		"echo '/dev/mapper/aiquila_data /opt/aiquila ext4 defaults,nofail 0 2' >> /etc/fstab",
	}
	last := -1
	for _, step := range order {
		i := strings.Index(cmds, step)
		if i <= last {
			t.Fatalf("%q missing or out of order in\n%s", step, cmds)
		}
		last = i
	}
	if strings.Contains(cmds, "mkfs.ext4 -F /dev/sdb") {
		t.Error("formatted the raw device instead of the LUKS mapper")
	}
}

func TestSetupLUKSStopsWhenFormattingFails(t *testing.T) {
	srv := sshtest.New(t)
	srv.Handle = func(cmd string) sshtest.Result {
		if strings.Contains(cmd, "luksFormat") {
			return sshtest.Result{Output: "Device /dev/sdb is in use.", Exit: 5}
		}
		return sshtest.Result{}
	}

	err := SetupLUKS(srv.Client(), "/dev/sdb")
	if err == nil || !strings.Contains(err.Error(), "luksFormat") {
		t.Fatalf("err = %v", err)
	}
	if strings.Contains(strings.Join(srv.Commands(), "\n"), "mkfs") {
		t.Error("ran mkfs after luksFormat failed")
	}
}
