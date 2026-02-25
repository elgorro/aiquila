package main

import (
	"context"
	"fmt"

	hcloudclient "github.com/elgorro/aiquila/hetzner/internal/hcloud"
	networkpkg "github.com/elgorro/aiquila/hetzner/internal/network"
	"github.com/hetznercloud/hcloud-go/v2/hcloud"
	"github.com/spf13/cobra"
)

var (
	// network create flags
	netCreateName   string
	netCreateCIDR   string
	netCreateZone   string
	netCreateLabels []string
	netCreateToken  string

	// network attach/detach flags
	netAttachServer  string
	netAttachNetwork string
	netAttachToken   string

	netDetachServer  string
	netDetachNetwork string
	netDetachToken   string

	// network delete flags
	netDeleteName  string
	netDeleteToken string
)

func buildNetworkCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "network",
		Short: "Manage Hetzner private networks",
	}

	cmd.AddCommand(buildNetworkCreateCmd())
	cmd.AddCommand(buildNetworkAttachCmd())
	cmd.AddCommand(buildNetworkDetachCmd())
	cmd.AddCommand(buildNetworkDeleteCmd())

	return cmd
}

// ── network create ─────────────────────────────────────────────────────────────

func buildNetworkCreateCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "create",
		Short: "Create a private network (idempotent)",
		RunE:  runNetworkCreate,
	}
	cmd.Flags().StringVar(&netCreateName, "name", "", "Network name (required)")
	cmd.Flags().StringVar(&netCreateCIDR, "cidr", "10.0.0.0/16", "Network CIDR (e.g. 10.0.0.0/16)")
	cmd.Flags().StringVar(&netCreateZone, "zone", "eu-central", "Network zone (eu-central|us-east|us-west|ap-southeast)")
	cmd.Flags().StringArrayVar(&netCreateLabels, "label", nil, "Label key=value (repeatable)")
	cmd.Flags().StringVar(&netCreateToken, "token", "", "Hetzner API token (default: $HCLOUD_TOKEN)")
	_ = cmd.MarkFlagRequired("name")
	return cmd
}

func runNetworkCreate(_ *cobra.Command, _ []string) error {
	zone, err := parseNetworkZone(netCreateZone)
	if err != nil {
		return err
	}

	labels, err := parseLabels(netCreateLabels)
	if err != nil {
		return err
	}

	ctx := context.Background()
	client, err := hcloudclient.NewClient(netCreateToken, globalProfile)
	if err != nil {
		return err
	}

	fmt.Printf("==> Creating network %q\n", netCreateName)
	net, err := networkpkg.Create(ctx, client, netCreateName, netCreateCIDR, zone, labels)
	if err != nil {
		return err
	}
	fmt.Printf("  id=%d  cidr=%s  zone=%s\n", net.ID, net.IPRange, netCreateZone)
	return nil
}

// ── network attach ─────────────────────────────────────────────────────────────

func buildNetworkAttachCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "attach",
		Short: "Attach a server to a private network",
		RunE:  runNetworkAttach,
	}
	cmd.Flags().StringVar(&netAttachServer, "server", "", "Server name (required)")
	cmd.Flags().StringVar(&netAttachNetwork, "network", "", "Network name (required)")
	cmd.Flags().StringVar(&netAttachToken, "token", "", "Hetzner API token (default: $HCLOUD_TOKEN)")
	_ = cmd.MarkFlagRequired("server")
	_ = cmd.MarkFlagRequired("network")
	return cmd
}

func runNetworkAttach(_ *cobra.Command, _ []string) error {
	ctx := context.Background()
	client, err := hcloudclient.NewClient(netAttachToken, globalProfile)
	if err != nil {
		return err
	}

	srv, err := lookupServer(ctx, client, netAttachServer)
	if err != nil {
		return err
	}

	net, err := lookupNetwork(ctx, client, netAttachNetwork)
	if err != nil {
		return err
	}

	fmt.Printf("==> Attaching server %q to network %q\n", netAttachServer, netAttachNetwork)
	ip, err := networkpkg.Attach(ctx, client, srv, net)
	if err != nil {
		return err
	}
	fmt.Printf("  Private IP: %s\n", ip)
	return nil
}

// ── network detach ─────────────────────────────────────────────────────────────

func buildNetworkDetachCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "detach",
		Short: "Detach a server from a private network",
		RunE:  runNetworkDetach,
	}
	cmd.Flags().StringVar(&netDetachServer, "server", "", "Server name (required)")
	cmd.Flags().StringVar(&netDetachNetwork, "network", "", "Network name (required)")
	cmd.Flags().StringVar(&netDetachToken, "token", "", "Hetzner API token (default: $HCLOUD_TOKEN)")
	_ = cmd.MarkFlagRequired("server")
	_ = cmd.MarkFlagRequired("network")
	return cmd
}

func runNetworkDetach(_ *cobra.Command, _ []string) error {
	ctx := context.Background()
	client, err := hcloudclient.NewClient(netDetachToken, globalProfile)
	if err != nil {
		return err
	}

	srv, err := lookupServer(ctx, client, netDetachServer)
	if err != nil {
		return err
	}

	net, err := lookupNetwork(ctx, client, netDetachNetwork)
	if err != nil {
		return err
	}

	fmt.Printf("==> Detaching server %q from network %q\n", netDetachServer, netDetachNetwork)
	return networkpkg.Detach(ctx, client, srv, net)
}

// ── network delete ─────────────────────────────────────────────────────────────

func buildNetworkDeleteCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "delete",
		Short: "Delete a private network",
		RunE:  runNetworkDelete,
	}
	cmd.Flags().StringVar(&netDeleteName, "name", "", "Network name (required)")
	cmd.Flags().StringVar(&netDeleteToken, "token", "", "Hetzner API token (default: $HCLOUD_TOKEN)")
	_ = cmd.MarkFlagRequired("name")
	return cmd
}

func runNetworkDelete(_ *cobra.Command, _ []string) error {
	ctx := context.Background()
	client, err := hcloudclient.NewClient(netDeleteToken, globalProfile)
	if err != nil {
		return err
	}

	net, _, err := client.Network.GetByName(ctx, netDeleteName)
	if err != nil {
		return fmt.Errorf("look up network %q: %w", netDeleteName, err)
	}
	if net == nil {
		fmt.Printf("  Network %q not found — nothing to delete\n", netDeleteName)
		return nil
	}

	if _, err := client.Network.Delete(ctx, net); err != nil {
		return fmt.Errorf("delete network %q: %w", netDeleteName, err)
	}
	fmt.Printf("  Deleted network %q (id=%d)\n", netDeleteName, net.ID)
	return nil
}

// ── helpers ────────────────────────────────────────────────────────────────────

func parseNetworkZone(zone string) (hcloud.NetworkZone, error) {
	switch zone {
	case "eu-central", "us-east", "us-west", "ap-southeast":
		return hcloud.NetworkZone(zone), nil
	default:
		return "", fmt.Errorf("unknown network zone %q (valid: eu-central, us-east, us-west, ap-southeast)", zone)
	}
}

func lookupNetwork(ctx context.Context, client *hcloud.Client, name string) (*hcloud.Network, error) {
	net, _, err := client.Network.GetByName(ctx, name)
	if err != nil {
		return nil, fmt.Errorf("look up network %q: %w", name, err)
	}
	if net == nil {
		return nil, fmt.Errorf("network %q not found", name)
	}
	return net, nil
}
