package main

import (
	"context"
	"fmt"
	"net"

	"github.com/elgorro/aiquila/hetzner/internal/dns"
	"github.com/spf13/cobra"
)

var (
	// dns create flags
	dnsCreateName     string
	dnsCreateZone     string
	dnsCreateIP       string
	dnsCreateIPv6     string
	dnsCreateDNSToken string

	// dns delete flags
	dnsDeleteName     string
	dnsDeleteZone     string
	dnsDeleteDNSToken string
)

// buildDNSCmd returns the 'dns' parent command with create/delete subcommands.
func buildDNSCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "dns",
		Short: "Manage Hetzner DNS records for an AIquila deployment",
		Long: `Manage DNS records via the Hetzner DNS API (dns.hetzner.com).

Token resolution order (first non-empty wins):
  1. --dns-token flag
  2. $HETZNER_DNS_TOKEN
  3. $HCLOUD_TOKEN
  4. dns_token field in the active profile`,
	}
	cmd.AddCommand(buildDNSCreateCmd())
	cmd.AddCommand(buildDNSDeleteCmd())
	return cmd
}

// ── dns create ────────────────────────────────────────────────────────────────

func buildDNSCreateCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "create",
		Short: "Create or update A (and optionally AAAA) records",
		Long: `Create or update DNS records for an AIquila server.

Examples:
  # IPv4 only
  aiquila-hetzner dns create --name mcp --zone example.com --ip 1.2.3.4

  # IPv4 + IPv6
  aiquila-hetzner dns create --name mcp --zone example.com --ip 1.2.3.4 --ipv6 2a01::1`,
		RunE: runDNSCreate,
	}
	cmd.Flags().StringVar(&dnsCreateName, "name", "", "DNS record name / subdomain (e.g. mcp → mcp.example.com) (required)")
	cmd.Flags().StringVar(&dnsCreateZone, "zone", "", "DNS zone / apex domain (e.g. example.com) (required)")
	cmd.Flags().StringVar(&dnsCreateIP, "ip", "", "IPv4 address for the A record (required)")
	cmd.Flags().StringVar(&dnsCreateIPv6, "ipv6", "", "IPv6 address for the AAAA record (optional)")
	cmd.Flags().StringVar(&dnsCreateDNSToken, "dns-token", "", "Hetzner DNS API token (default: $HETZNER_DNS_TOKEN or $HCLOUD_TOKEN)")
	_ = cmd.MarkFlagRequired("name")
	_ = cmd.MarkFlagRequired("zone")
	_ = cmd.MarkFlagRequired("ip")
	return cmd
}

func runDNSCreate(_ *cobra.Command, _ []string) error {
	if net.ParseIP(dnsCreateIP) == nil {
		return fmt.Errorf("--ip %q is not a valid IP address", dnsCreateIP)
	}
	if dnsCreateIPv6 != "" && net.ParseIP(dnsCreateIPv6) == nil {
		return fmt.Errorf("--ipv6 %q is not a valid IP address", dnsCreateIPv6)
	}

	token, err := dns.ResolveToken(dnsCreateDNSToken, globalProfile)
	if err != nil {
		return err
	}

	ctx := context.Background()
	fmt.Printf("==> Creating DNS records for %s.%s\n", dnsCreateName, dnsCreateZone)

	if err := dns.EnsureRecord(ctx, token, dnsCreateZone, dnsCreateName, dnsCreateIP, "A"); err != nil {
		return err
	}

	if dnsCreateIPv6 != "" {
		if err := dns.EnsureRecord(ctx, token, dnsCreateZone, dnsCreateName, dnsCreateIPv6, "AAAA"); err != nil {
			return err
		}
	}

	fmt.Println("Done.")
	return nil
}

// ── dns delete ────────────────────────────────────────────────────────────────

func buildDNSDeleteCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "delete",
		Short: "Delete A and AAAA records for a name",
		Long: `Delete all A and AAAA records for a given subdomain in a zone.

Example:
  aiquila-hetzner dns delete --name mcp --zone example.com`,
		RunE: runDNSDelete,
	}
	cmd.Flags().StringVar(&dnsDeleteName, "name", "", "DNS record name / subdomain (required)")
	cmd.Flags().StringVar(&dnsDeleteZone, "zone", "", "DNS zone / apex domain (required)")
	cmd.Flags().StringVar(&dnsDeleteDNSToken, "dns-token", "", "Hetzner DNS API token (default: $HETZNER_DNS_TOKEN or $HCLOUD_TOKEN)")
	_ = cmd.MarkFlagRequired("name")
	_ = cmd.MarkFlagRequired("zone")
	return cmd
}

func runDNSDelete(_ *cobra.Command, _ []string) error {
	token, err := dns.ResolveToken(dnsDeleteDNSToken, globalProfile)
	if err != nil {
		return err
	}

	ctx := context.Background()
	fmt.Printf("==> Deleting DNS records for %s.%s\n", dnsDeleteName, dnsDeleteZone)

	if err := dns.DeleteRecords(ctx, token, dnsDeleteZone, dnsDeleteName); err != nil {
		return err
	}

	fmt.Println("Done.")
	return nil
}
