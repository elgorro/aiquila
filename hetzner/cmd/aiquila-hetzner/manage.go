package main

import (
	"context"
	"fmt"
	"os"
	"text/tabwriter"

	"github.com/elgorro/aiquila/hetzner/internal/dns"
	hcloudclient "github.com/elgorro/aiquila/hetzner/internal/hcloud"
	"github.com/hetznercloud/hcloud-go/v2/hcloud"
	"github.com/spf13/cobra"
)

var (
	// list flags
	listToken string
	listLabel string

	// stop flags
	stopName  string
	stopToken string
	stopHard  bool

	// start flags
	startName  string
	startToken string

	// restart flags
	restartName  string
	restartToken string
	restartHard  bool

	// delete flags
	deleteName     string
	deleteToken    string
	deleteDNSZone  string
	deleteDNSToken string
)

// ── list ──────────────────────────────────────────────────────────────────────

func buildListCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "list",
		Short: "List all servers in the Hetzner project",
		RunE:  runList,
	}
	cmd.Flags().StringVar(&listToken, "token", "", "Hetzner API token (default: $HCLOUD_TOKEN)")
	cmd.Flags().StringVar(&listLabel, "label", "", "Filter servers by label selector (e.g. env=prod)")
	return cmd
}

func runList(_ *cobra.Command, _ []string) error {
	ctx := context.Background()

	client, err := hcloudclient.NewClient(listToken, globalProfile)
	if err != nil {
		return err
	}

	var servers []*hcloud.Server
	if listLabel != "" {
		servers, err = client.Server.AllWithOpts(ctx, hcloud.ServerListOpts{
			ListOpts: hcloud.ListOpts{LabelSelector: listLabel},
		})
	} else {
		servers, err = client.Server.All(ctx)
	}
	if err != nil {
		return fmt.Errorf("list servers: %w", err)
	}

	if len(servers) == 0 {
		fmt.Println("No servers found.")
		return nil
	}

	w := tabwriter.NewWriter(os.Stdout, 0, 0, 3, ' ', 0)
	fmt.Fprintln(w, "NAME\tID\tTYPE\tLOCATION\tSTATUS\tIPv4")
	fmt.Fprintln(w, "────\t──\t────\t────────\t──────\t────")
	for _, srv := range servers {
		fmt.Fprintf(w, "%s\t%d\t%s\t%s\t%s\t%s\n",
			srv.Name,
			srv.ID,
			srv.ServerType.Name,
			srv.Datacenter.Location.Name,
			serverStatusIcon(srv.Status),
			srv.PublicNet.IPv4.IP.String(),
		)
	}
	w.Flush()
	return nil
}

func serverStatusIcon(status hcloud.ServerStatus) string {
	switch status {
	case hcloud.ServerStatusRunning:
		return "● running"
	case hcloud.ServerStatusOff:
		return "○ off"
	case hcloud.ServerStatusInitializing:
		return "⚙ initializing"
	case hcloud.ServerStatusStarting:
		return "⚙ starting"
	case hcloud.ServerStatusStopping:
		return "⚙ stopping"
	case hcloud.ServerStatusRebuilding:
		return "⚙ rebuilding"
	case hcloud.ServerStatusMigrating:
		return "⚙ migrating"
	default:
		return string(status)
	}
}

// ── stop ──────────────────────────────────────────────────────────────────────

func buildStopCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "stop",
		Short: "Stop a server (graceful ACPI shutdown; --hard for immediate power-off)",
		RunE:  runStop,
	}
	cmd.Flags().StringVar(&stopName, "name", "", "Server name (required)")
	cmd.Flags().StringVar(&stopToken, "token", "", "Hetzner API token (default: $HCLOUD_TOKEN)")
	cmd.Flags().BoolVar(&stopHard, "hard", false, "Hard power-off instead of graceful shutdown")
	_ = cmd.MarkFlagRequired("name")
	return cmd
}

func runStop(_ *cobra.Command, _ []string) error {
	ctx := context.Background()

	client, err := hcloudclient.NewClient(stopToken, globalProfile)
	if err != nil {
		return err
	}

	srv, err := lookupServer(ctx, client, stopName)
	if err != nil {
		return err
	}

	if stopHard {
		fmt.Printf("==> Hard power-off %q\n", srv.Name)
		action, _, err := client.Server.Poweroff(ctx, srv)
		if err != nil {
			return fmt.Errorf("power-off: %w", err)
		}
		if err := client.Action.WaitForFunc(ctx, nil, action); err != nil {
			return fmt.Errorf("wait for poweroff: %w", err)
		}
		fmt.Printf("  Server %q is off.\n", srv.Name)
	} else {
		fmt.Printf("==> Graceful shutdown %q\n", srv.Name)
		action, _, err := client.Server.Shutdown(ctx, srv)
		if err != nil {
			return fmt.Errorf("shutdown: %w", err)
		}
		if err := client.Action.WaitForFunc(ctx, nil, action); err != nil {
			return fmt.Errorf("wait for shutdown: %w", err)
		}
		fmt.Printf("  Shutdown signal sent to %q. Use 'list' to confirm status.\n", srv.Name)
	}
	return nil
}

// ── start ─────────────────────────────────────────────────────────────────────

func buildStartCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:     "start",
		Aliases: []string{"boot"},
		Short:   "Start (power on) a stopped server",
		RunE:    runStart,
	}
	cmd.Flags().StringVar(&startName, "name", "", "Server name (required)")
	cmd.Flags().StringVar(&startToken, "token", "", "Hetzner API token (default: $HCLOUD_TOKEN)")
	_ = cmd.MarkFlagRequired("name")
	return cmd
}

func runStart(_ *cobra.Command, _ []string) error {
	ctx := context.Background()

	client, err := hcloudclient.NewClient(startToken, globalProfile)
	if err != nil {
		return err
	}

	srv, err := lookupServer(ctx, client, startName)
	if err != nil {
		return err
	}

	fmt.Printf("==> Starting %q\n", srv.Name)
	action, _, err := client.Server.Poweron(ctx, srv)
	if err != nil {
		return fmt.Errorf("poweron: %w", err)
	}
	if err := client.Action.WaitForFunc(ctx, nil, action); err != nil {
		return fmt.Errorf("wait for poweron: %w", err)
	}
	fmt.Printf("  Server %q is running.\n", srv.Name)
	return nil
}

// ── restart ───────────────────────────────────────────────────────────────────

func buildRestartCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "restart",
		Short: "Restart a server (graceful reboot; --hard for immediate reset)",
		RunE:  runRestart,
	}
	cmd.Flags().StringVar(&restartName, "name", "", "Server name (required)")
	cmd.Flags().StringVar(&restartToken, "token", "", "Hetzner API token (default: $HCLOUD_TOKEN)")
	cmd.Flags().BoolVar(&restartHard, "hard", false, "Hard reset instead of graceful reboot")
	_ = cmd.MarkFlagRequired("name")
	return cmd
}

func runRestart(_ *cobra.Command, _ []string) error {
	ctx := context.Background()

	client, err := hcloudclient.NewClient(restartToken, globalProfile)
	if err != nil {
		return err
	}

	srv, err := lookupServer(ctx, client, restartName)
	if err != nil {
		return err
	}

	if restartHard {
		fmt.Printf("==> Hard reset %q\n", srv.Name)
		action, _, err := client.Server.Reset(ctx, srv)
		if err != nil {
			return fmt.Errorf("reset: %w", err)
		}
		if err := client.Action.WaitForFunc(ctx, nil, action); err != nil {
			return fmt.Errorf("wait for reset: %w", err)
		}
	} else {
		fmt.Printf("==> Rebooting %q\n", srv.Name)
		action, _, err := client.Server.Reboot(ctx, srv)
		if err != nil {
			return fmt.Errorf("reboot: %w", err)
		}
		if err := client.Action.WaitForFunc(ctx, nil, action); err != nil {
			return fmt.Errorf("wait for reboot: %w", err)
		}
	}
	fmt.Printf("  Server %q is back online.\n", srv.Name)
	return nil
}

// ── delete ────────────────────────────────────────────────────────────────────

func buildDeleteCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "delete",
		Short: "Delete a server and its associated firewall, SSH key, and volume",
		Long: `Delete a server created by 'aiquila-hetzner create'.

Removes the server, the firewall (<name>-fw), the SSH key (<name>-key),
and the Cloud Volume (<name>-vol) if present. Equivalent to 'destroy'.`,
		RunE: runDelete,
	}
	cmd.Flags().StringVar(&deleteName, "name", "", "Server name to delete (required)")
	cmd.Flags().StringVar(&deleteToken, "token", "", "Hetzner API token (default: $HCLOUD_TOKEN)")
	cmd.Flags().StringVar(&deleteDNSZone, "dns-zone", "", "Hetzner DNS zone — deletes <name>.<zone> A/AAAA records")
	cmd.Flags().StringVar(&deleteDNSToken, "dns-token", "", "Hetzner DNS API token (default: $HETZNER_DNS_TOKEN or $HCLOUD_TOKEN)")
	_ = cmd.MarkFlagRequired("name")
	return cmd
}

func runDelete(_ *cobra.Command, _ []string) error {
	ctx := context.Background()
	client, err := hcloudclient.NewClient(deleteToken, globalProfile)
	if err != nil {
		return err
	}
	appLog.Info("start", "deleting server", "name", deleteName)
	fmt.Printf("==> Deleting %q and associated resources\n", deleteName)
	return cleanupServer(ctx, client, deleteName, deleteDNSZone, deleteDNSToken)
}

// cleanupServer deletes the server plus all resources created by 'create':
// firewall (<name>-fw), SSH key (<name>-key), and Cloud Volume (<name>-vol).
// Resources are deleted in the safest order: server first (auto-detaches the
// volume), then firewall, SSH key, and finally the volume.
// If dnsZone is non-empty, DNS A/AAAA records for <name>.<dnsZone> are deleted first.
func cleanupServer(ctx context.Context, client *hcloud.Client, name, dnsZone, dnsToken string) error {
	// 0. DNS records (before server deletion so zone lookup doesn't depend on server)
	if dnsZone != "" {
		fmt.Println("── DNS")
		tok, err := dns.ResolveToken(dnsToken, globalProfile)
		if err != nil {
			return err
		}
		if err := dns.DeleteRecords(ctx, tok, dnsZone, name); err != nil {
			return err
		}
	}

	// 1. Server
	srv, _, err := client.Server.GetByName(ctx, name)
	if err != nil {
		return fmt.Errorf("look up server: %w", err)
	}
	if srv == nil {
		fmt.Printf("  Server %q not found — skipping\n", name)
	} else {
		result, _, err := client.Server.DeleteWithResult(ctx, srv)
		if err != nil {
			return fmt.Errorf("delete server: %w", err)
		}
		if err := client.Action.WaitForFunc(ctx, nil, result.Action); err != nil {
			return fmt.Errorf("wait for server delete: %w", err)
		}
		fmt.Printf("  Deleted server %q (id=%d)\n", name, srv.ID)
	}

	// 2. Firewall, SSH key
	for _, res := range []struct{ kind, rname string }{
		{"firewall", name + "-fw"},
		{"SSH key", name + "-key"},
	} {
		if err := deleteResource(ctx, client, res.kind, res.rname); err != nil {
			return err
		}
	}

	// 3. Cloud Volume (created by --volume-size; server deletion auto-detaches it)
	volName := name + "-vol"
	vol, _, err := client.Volume.GetByName(ctx, volName)
	if err != nil {
		return fmt.Errorf("look up volume %q: %w", volName, err)
	}
	if vol == nil {
		fmt.Printf("  Volume %q not found — skipping\n", volName)
	} else {
		if _, err := client.Volume.Delete(ctx, vol); err != nil {
			return fmt.Errorf("delete volume %q: %w", volName, err)
		}
		fmt.Printf("  Deleted volume %q (id=%d)\n", volName, vol.ID)
	}

	fmt.Println("Done.")
	return nil
}

// deleteResource removes a named firewall or SSH key, silently skipping if absent.
func deleteResource(ctx context.Context, client *hcloud.Client, kind, name string) error {
	switch kind {
	case "firewall":
		fw, _, err := client.Firewall.GetByName(ctx, name)
		if err != nil {
			return fmt.Errorf("look up firewall %q: %w", name, err)
		}
		if fw == nil {
			fmt.Printf("  Firewall %q not found — skipping\n", name)
			return nil
		}
		if _, err := client.Firewall.Delete(ctx, fw); err != nil {
			return fmt.Errorf("delete firewall: %w", err)
		}
		fmt.Printf("  Deleted firewall %q (id=%d)\n", name, fw.ID)

	case "SSH key":
		key, _, err := client.SSHKey.GetByName(ctx, name)
		if err != nil {
			return fmt.Errorf("look up SSH key %q: %w", name, err)
		}
		if key == nil {
			fmt.Printf("  SSH key %q not found — skipping\n", name)
			return nil
		}
		if _, err := client.SSHKey.Delete(ctx, key); err != nil {
			return fmt.Errorf("delete SSH key: %w", err)
		}
		fmt.Printf("  Deleted SSH key %q (id=%d)\n", name, key.ID)
	}
	return nil
}

// ── shared helpers ────────────────────────────────────────────────────────────

// lookupServer retrieves a server by name and returns a clear error if not found.
func lookupServer(ctx context.Context, client *hcloud.Client, name string) (*hcloud.Server, error) {
	srv, _, err := client.Server.GetByName(ctx, name)
	if err != nil {
		return nil, fmt.Errorf("look up server %q: %w", name, err)
	}
	if srv == nil {
		return nil, fmt.Errorf("server %q not found", name)
	}
	return srv, nil
}
