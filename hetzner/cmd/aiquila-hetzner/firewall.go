package main

import (
	"context"
	"fmt"
	"net"
	"os"
	"strings"
	"text/tabwriter"

	hcloudclient "github.com/elgorro/aiquila/hetzner/internal/hcloud"
	"github.com/hetznercloud/hcloud-go/v2/hcloud"
	"github.com/spf13/cobra"
)

var (
	// firewall rules flags
	firewallRulesName  string
	firewallRulesToken string

	// firewall allow flags
	firewallAllowName  string
	firewallAllowPort  string
	firewallAllowProto string
	firewallAllowCIDRs []string
	firewallAllowDesc  string
	firewallAllowToken string

	// firewall deny flags
	firewallDenyName  string
	firewallDenyPort  string
	firewallDenyProto string
	firewallDenyToken string
)

func buildFirewallCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "firewall",
		Short: "Manage firewall rules for an AIquila deployment",
		Long: `Manage Hetzner Cloud firewall rules for a named AIquila server.

The firewall is identified by <name>-fw (the naming convention used by 'create').`,
	}
	cmd.AddCommand(buildFirewallRulesCmd())
	cmd.AddCommand(buildFirewallAllowCmd())
	cmd.AddCommand(buildFirewallDenyCmd())
	return cmd
}

// ── firewall rules ─────────────────────────────────────────────────────────────

func buildFirewallRulesCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "rules",
		Short: "List inbound rules for a firewall",
		RunE:  runFirewallRules,
	}
	cmd.Flags().StringVar(&firewallRulesName, "name", "", "Server name (firewall looked up as <name>-fw) (required)")
	cmd.Flags().StringVar(&firewallRulesToken, "token", "", "Hetzner API token (default: $HCLOUD_TOKEN)")
	_ = cmd.MarkFlagRequired("name")
	return cmd
}

func runFirewallRules(_ *cobra.Command, _ []string) error {
	ctx := context.Background()
	client, err := hcloudclient.NewClient(firewallRulesToken, globalProfile)
	if err != nil {
		return err
	}

	fwName := firewallRulesName + "-fw"
	fw, _, err := client.Firewall.GetByName(ctx, fwName)
	if err != nil {
		return fmt.Errorf("look up firewall %q: %w", fwName, err)
	}
	if fw == nil {
		return fmt.Errorf("firewall %q not found", fwName)
	}

	if len(fw.Rules) == 0 {
		fmt.Println("No rules found.")
		return nil
	}

	w := tabwriter.NewWriter(os.Stdout, 0, 0, 3, ' ', 0)
	fmt.Fprintln(w, "PROTO\tPORT\tDIRECTION\tDESCRIPTION\tSOURCE CIDRs")
	fmt.Fprintln(w, "─────\t────\t─────────\t───────────\t────────────")
	for _, r := range fw.Rules {
		port := ""
		if r.Port != nil {
			port = *r.Port
		}
		desc := ""
		if r.Description != nil {
			desc = *r.Description
		}
		fmt.Fprintf(w, "%s\t%s\t%s\t%s\t%s\n",
			r.Protocol,
			port,
			r.Direction,
			desc,
			formatCIDRs(r.SourceIPs),
		)
	}
	return w.Flush()
}

func formatCIDRs(nets []net.IPNet) string {
	parts := make([]string, len(nets))
	for i, n := range nets {
		parts[i] = n.String()
	}
	return strings.Join(parts, ", ")
}

// ── firewall allow ─────────────────────────────────────────────────────────────

func buildFirewallAllowCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "allow",
		Short: "Add an inbound allow rule to a firewall",
		Long: `Add an inbound allow rule to the named firewall.

Examples:
  # Allow PostgreSQL from a private network
  aiquila-hetzner firewall allow --name myserver --port 5432 --proto tcp --cidr 10.0.0.0/8

  # Allow a port range from anywhere
  aiquila-hetzner firewall allow --name myserver --port 8000-8080 --description "App"`,
		RunE: runFirewallAllow,
	}
	cmd.Flags().StringVar(&firewallAllowName, "name", "", "Server name (firewall looked up as <name>-fw) (required)")
	cmd.Flags().StringVar(&firewallAllowPort, "port", "", "Port or port range (e.g. 5432 or 5432-5440) (required)")
	cmd.Flags().StringVar(&firewallAllowProto, "proto", "tcp", "Protocol: tcp or udp")
	cmd.Flags().StringArrayVar(&firewallAllowCIDRs, "cidr", nil, "Source CIDR (repeatable; default: 0.0.0.0/0 and ::/0)")
	cmd.Flags().StringVar(&firewallAllowDesc, "description", "", "Rule description")
	cmd.Flags().StringVar(&firewallAllowToken, "token", "", "Hetzner API token (default: $HCLOUD_TOKEN)")
	_ = cmd.MarkFlagRequired("name")
	_ = cmd.MarkFlagRequired("port")
	return cmd
}

func runFirewallAllow(_ *cobra.Command, _ []string) error {
	if firewallAllowProto != "tcp" && firewallAllowProto != "udp" {
		return fmt.Errorf("--proto must be tcp or udp, got %q", firewallAllowProto)
	}

	// Parse source CIDRs (default: open to the world)
	var sourceIPs []net.IPNet
	if len(firewallAllowCIDRs) == 0 {
		sourceIPs = []net.IPNet{
			{IP: net.IPv4zero, Mask: net.CIDRMask(0, 32)},
			{IP: net.IPv6zero, Mask: net.CIDRMask(0, 128)},
		}
	} else {
		for _, cidr := range firewallAllowCIDRs {
			_, ipNet, err := net.ParseCIDR(cidr)
			if err != nil {
				return fmt.Errorf("invalid CIDR %q: %w", cidr, err)
			}
			sourceIPs = append(sourceIPs, *ipNet)
		}
	}

	ctx := context.Background()
	client, err := hcloudclient.NewClient(firewallAllowToken, globalProfile)
	if err != nil {
		return err
	}

	fwName := firewallAllowName + "-fw"
	fw, _, err := client.Firewall.GetByName(ctx, fwName)
	if err != nil {
		return fmt.Errorf("look up firewall %q: %w", fwName, err)
	}
	if fw == nil {
		return fmt.Errorf("firewall %q not found", fwName)
	}

	proto := hcloud.FirewallRuleProtocol(firewallAllowProto)

	// Check for duplicate
	for _, r := range fw.Rules {
		if r.Protocol == proto && r.Port != nil && *r.Port == firewallAllowPort && r.Direction == hcloud.FirewallRuleDirectionIn {
			return fmt.Errorf("rule for %s port %s already exists", proto, firewallAllowPort)
		}
	}

	newRule := hcloud.FirewallRule{
		Direction: hcloud.FirewallRuleDirectionIn,
		Protocol:  proto,
		Port:      hcloud.Ptr(firewallAllowPort),
		SourceIPs: sourceIPs,
	}
	if firewallAllowDesc != "" {
		newRule.Description = hcloud.Ptr(firewallAllowDesc)
	}

	rules := append(fw.Rules, newRule)
	actions, _, err := client.Firewall.SetRules(ctx, fw, hcloud.FirewallSetRulesOpts{Rules: rules})
	if err != nil {
		return fmt.Errorf("set firewall rules: %w", err)
	}
	for _, a := range actions {
		if err := client.Action.WaitForFunc(ctx, nil, a); err != nil {
			return fmt.Errorf("wait for firewall update: %w", err)
		}
	}

	label := firewallAllowDesc
	if label == "" {
		label = fmt.Sprintf("%s/%s", proto, firewallAllowPort)
	}
	fmt.Printf("  Added rule: %s port %s (%s) from %s\n",
		proto, firewallAllowPort, label, formatCIDRs(sourceIPs))
	return nil
}

// ── firewall deny ──────────────────────────────────────────────────────────────

func buildFirewallDenyCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "deny",
		Short: "Remove an inbound allow rule from a firewall",
		Long: `Remove matching inbound rules from the named firewall.

If --proto is omitted, rules for both tcp and udp on that port are removed.

Examples:
  # Remove the PostgreSQL rule (all protocols)
  aiquila-hetzner firewall deny --name myserver --port 5432

  # Remove only the UDP rule
  aiquila-hetzner firewall deny --name myserver --port 443 --proto udp`,
		RunE: runFirewallDeny,
	}
	cmd.Flags().StringVar(&firewallDenyName, "name", "", "Server name (firewall looked up as <name>-fw) (required)")
	cmd.Flags().StringVar(&firewallDenyPort, "port", "", "Port or port range to remove (required)")
	cmd.Flags().StringVar(&firewallDenyProto, "proto", "", "Protocol: tcp or udp (default: remove both)")
	cmd.Flags().StringVar(&firewallDenyToken, "token", "", "Hetzner API token (default: $HCLOUD_TOKEN)")
	_ = cmd.MarkFlagRequired("name")
	_ = cmd.MarkFlagRequired("port")
	return cmd
}

func runFirewallDeny(_ *cobra.Command, _ []string) error {
	if firewallDenyProto != "" && firewallDenyProto != "tcp" && firewallDenyProto != "udp" {
		return fmt.Errorf("--proto must be tcp or udp, got %q", firewallDenyProto)
	}

	ctx := context.Background()
	client, err := hcloudclient.NewClient(firewallDenyToken, globalProfile)
	if err != nil {
		return err
	}

	fwName := firewallDenyName + "-fw"
	fw, _, err := client.Firewall.GetByName(ctx, fwName)
	if err != nil {
		return fmt.Errorf("look up firewall %q: %w", fwName, err)
	}
	if fw == nil {
		return fmt.Errorf("firewall %q not found", fwName)
	}

	var kept []hcloud.FirewallRule
	var removed []string
	for _, r := range fw.Rules {
		port := ""
		if r.Port != nil {
			port = *r.Port
		}
		matchPort := port == firewallDenyPort
		matchProto := firewallDenyProto == "" || string(r.Protocol) == firewallDenyProto
		if matchPort && matchProto {
			label := fmt.Sprintf("%s/%s", r.Protocol, port)
			if r.Description != nil && *r.Description != "" {
				label += " (" + *r.Description + ")"
			}
			removed = append(removed, label)
		} else {
			kept = append(kept, r)
		}
	}

	if len(removed) == 0 {
		msg := fmt.Sprintf("WARNING: no matching rules found for port %s", firewallDenyPort)
		if firewallDenyProto != "" {
			msg += " proto " + firewallDenyProto
		}
		fmt.Fprintln(os.Stderr, msg)
		return nil
	}

	actions, _, err := client.Firewall.SetRules(ctx, fw, hcloud.FirewallSetRulesOpts{Rules: kept})
	if err != nil {
		return fmt.Errorf("set firewall rules: %w", err)
	}
	for _, a := range actions {
		if err := client.Action.WaitForFunc(ctx, nil, a); err != nil {
			return fmt.Errorf("wait for firewall update: %w", err)
		}
	}

	for _, r := range removed {
		fmt.Printf("  Removed rule: %s\n", r)
	}
	return nil
}
