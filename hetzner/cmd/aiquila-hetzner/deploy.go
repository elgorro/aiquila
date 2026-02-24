package main

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strings"

	hcloudclient "github.com/elgorro/aiquila/hetzner/internal/hcloud"
	"github.com/elgorro/aiquila/hetzner/internal/provision"
	"github.com/spf13/cobra"
)

var (
	// deploy flags
	deployName    string
	deployToken   string
	deploySSHKey  string

	// logs flags
	logsName    string
	logsToken   string
	logsSSHKey  string
	logsService string
	logsTail    int
)

// ── deploy ────────────────────────────────────────────────────────────────────

func buildDeployCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "deploy",
		Short: "Pull latest image and restart services on a running server",
		Long: `Pull the latest aiquila-mcp image and restart all services in-place.

Monitoring services (Prometheus, Grafana) are auto-detected: if they are
already running they will be included in the pull and restart.`,
		RunE: runDeploy,
	}
	cmd.Flags().StringVar(&deployName, "name", "", "Server name (required)")
	cmd.Flags().StringVar(&deployToken, "token", "", "Hetzner API token (default: $HCLOUD_TOKEN)")
	cmd.Flags().StringVar(&deploySSHKey, "ssh-key", "", "SSH private key path (default: ~/.ssh/aiquila_ed25519)")
	_ = cmd.MarkFlagRequired("name")
	return cmd
}

func runDeploy(_ *cobra.Command, _ []string) error {
	ctx := context.Background()

	if deploySSHKey == "" {
		home, err := os.UserHomeDir()
		if err != nil {
			return fmt.Errorf("home dir: %w", err)
		}
		deploySSHKey = filepath.Join(home, ".ssh", "aiquila_ed25519")
	}

	client, err := hcloudclient.NewClient(deployToken, globalProfile)
	if err != nil {
		return err
	}

	srv, err := lookupServer(ctx, client, deployName)
	if err != nil {
		return err
	}
	serverIP := srv.PublicNet.IPv4.IP.String()

	fmt.Printf("==> Deploying to %q (%s)\n", srv.Name, serverIP)

	sshClient, err := provision.WaitAndDial(serverIP, deploySSHKey)
	if err != nil {
		return err
	}
	defer sshClient.Close()

	// Auto-detect whether monitoring profile is active.
	profileFlag := ""
	monCheck, _ := provision.RunCommand(sshClient,
		"docker ps -q --filter name=aiquila-grafana 2>/dev/null")
	if strings.TrimSpace(monCheck) != "" {
		profileFlag = "--profile monitoring"
		fmt.Println("  Monitoring profile detected — including in deploy.")
	}

	fmt.Println("\n── Pulling latest images")
	pullCmd := fmt.Sprintf("cd /opt/aiquila && docker compose %s pull 2>&1", profileFlag)
	out, err := provision.RunCommand(sshClient, pullCmd)
	if err != nil {
		fmt.Fprintln(os.Stderr, out)
		return fmt.Errorf("docker compose pull: %w", err)
	}
	fmt.Print(out)

	fmt.Println("\n── Restarting services")
	upCmd := fmt.Sprintf("cd /opt/aiquila && docker compose %s up -d 2>&1", profileFlag)
	out, err = provision.RunCommand(sshClient, upCmd)
	if err != nil {
		fmt.Fprintln(os.Stderr, out)
		return fmt.Errorf("docker compose up: %w", err)
	}
	fmt.Print(out)

	fmt.Printf("\n  Deploy complete on %q.\n", srv.Name)
	return nil
}

// ── logs ──────────────────────────────────────────────────────────────────────

func buildLogsCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "logs",
		Short: "Stream docker compose logs from a server (Ctrl+C to stop)",
		RunE:  runLogs,
	}
	cmd.Flags().StringVar(&logsName, "name", "", "Server name (required)")
	cmd.Flags().StringVar(&logsToken, "token", "", "Hetzner API token (default: $HCLOUD_TOKEN)")
	cmd.Flags().StringVar(&logsSSHKey, "ssh-key", "", "SSH private key path (default: ~/.ssh/aiquila_ed25519)")
	cmd.Flags().StringVar(&logsService, "service", "", "Service to tail (default: all services)")
	cmd.Flags().IntVar(&logsTail, "tail", 100, "Number of recent lines to show before streaming")
	_ = cmd.MarkFlagRequired("name")
	return cmd
}

func runLogs(_ *cobra.Command, _ []string) error {
	ctx := context.Background()

	if logsSSHKey == "" {
		home, err := os.UserHomeDir()
		if err != nil {
			return fmt.Errorf("home dir: %w", err)
		}
		logsSSHKey = filepath.Join(home, ".ssh", "aiquila_ed25519")
	}

	client, err := hcloudclient.NewClient(logsToken, globalProfile)
	if err != nil {
		return err
	}

	srv, err := lookupServer(ctx, client, logsName)
	if err != nil {
		return err
	}
	serverIP := srv.PublicNet.IPv4.IP.String()

	fmt.Printf("==> Streaming logs from %q (%s) — Ctrl+C to stop\n\n", srv.Name, serverIP)

	sshClient, err := provision.WaitAndDial(serverIP, logsSSHKey)
	if err != nil {
		return err
	}
	defer sshClient.Close()

	logsCmd := fmt.Sprintf(
		"cd /opt/aiquila && docker compose logs -f --tail=%d %s",
		logsTail, logsService,
	)
	return provision.StreamCommand(sshClient, logsCmd)
}
