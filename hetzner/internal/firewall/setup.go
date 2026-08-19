// SPDX-License-Identifier: MIT

package firewall

import (
	"context"
	"fmt"
	"net"
	"os"
	"strings"

	"github.com/hetznercloud/hcloud-go/v2/hcloud"
)

// Setup creates a Hetzner firewall that allows inbound TCP 22/80/443, UDP 443
// (HTTP/3 / QUIC), and attaches it to the given server. It is idempotent: if a
// firewall with the same name already exists, it is reused.
//
// sshCIDR restricts SSH access to the given CIDR (e.g. "203.0.113.0/24").
// Pass "" to allow SSH from anywhere (0.0.0.0/0 and ::/0) — callers should
// resolve a concrete CIDR up front rather than relying on that default.
func Setup(ctx context.Context, client *hcloud.Client, name string, server *hcloud.Server, labels map[string]string, sshCIDR string) (*hcloud.Firewall, error) {
	existing, _, err := client.Firewall.GetByName(ctx, name)
	if err != nil {
		return nil, fmt.Errorf("look up firewall %q: %w", name, err)
	}
	sshSources, err := ParseSSHSources(sshCIDR)
	if err != nil {
		return nil, err
	}

	if existing != nil {
		fmt.Printf("  Reusing existing firewall %q (id=%d)\n", name, existing.ID)
		warnSSHRuleMismatch(existing, sshSources)
		if err := attach(ctx, client, existing, server); err != nil {
			return nil, err
		}
		return existing, nil
	}

	rules := []hcloud.FirewallRule{
		tcpRuleWithSources(22, "SSH", sshSources),
		tcpRule(80, "HTTP"),
		tcpRule(443, "HTTPS"),
		udpRule(443, "HTTPS/QUIC"),
	}

	result, _, err := client.Firewall.Create(ctx, hcloud.FirewallCreateOpts{
		Name:   name,
		Rules:  rules,
		Labels: labels,
		ApplyTo: []hcloud.FirewallResource{
			{
				Type:   hcloud.FirewallResourceTypeServer,
				Server: &hcloud.FirewallResourceServer{ID: server.ID},
			},
		},
	})
	if err != nil {
		return nil, fmt.Errorf("create firewall: %w", err)
	}
	fmt.Printf("  Created firewall %q (id=%d)\n", name, result.Firewall.ID)
	return result.Firewall, nil
}

func udpRule(port int, description string) hcloud.FirewallRule {
	p := fmt.Sprintf("%d", port)
	return hcloud.FirewallRule{
		Direction:   hcloud.FirewallRuleDirectionIn,
		Protocol:    hcloud.FirewallRuleProtocolUDP,
		Port:        &p,
		SourceIPs:   []net.IPNet{{IP: net.IPv4zero, Mask: net.CIDRMask(0, 32)}, {IP: net.IPv6zero, Mask: net.CIDRMask(0, 128)}},
		Description: hcloud.Ptr(description),
	}
}

func tcpRule(port int, description string) hcloud.FirewallRule {
	return tcpRuleWithSources(port, description, []net.IPNet{
		{IP: net.IPv4zero, Mask: net.CIDRMask(0, 32)},
		{IP: net.IPv6zero, Mask: net.CIDRMask(0, 128)},
	})
}

func tcpRuleWithSources(port int, description string, sources []net.IPNet) hcloud.FirewallRule {
	p := fmt.Sprintf("%d", port)
	return hcloud.FirewallRule{
		Direction:   hcloud.FirewallRuleDirectionIn,
		Protocol:    hcloud.FirewallRuleProtocolTCP,
		Port:        &p,
		SourceIPs:   sources,
		Description: hcloud.Ptr(description),
	}
}

// warnSSHRuleMismatch reports when an existing firewall we are about to reuse
// permits SSH from somewhere other than what was requested. The firewall may
// have been tuned by hand, so it is never modified here.
func warnSSHRuleMismatch(fw *hcloud.Firewall, want []net.IPNet) {
	for _, rule := range fw.Rules {
		if rule.Direction != hcloud.FirewallRuleDirectionIn ||
			rule.Protocol != hcloud.FirewallRuleProtocolTCP ||
			rule.Port == nil || *rule.Port != "22" {
			continue
		}
		if sameSources(rule.SourceIPs, want) {
			return
		}
		fmt.Fprintf(os.Stderr,
			"WARNING: existing firewall %q allows SSH from %s, not %s — not modified; "+
				"edit it in the Hetzner console or delete it to have it recreated\n",
			fw.Name, joinNets(rule.SourceIPs), joinNets(want))
		return
	}
	fmt.Fprintf(os.Stderr,
		"WARNING: existing firewall %q has no inbound TCP 22 rule — SSH may be unreachable\n", fw.Name)
}

func sameSources(a, b []net.IPNet) bool {
	if len(a) != len(b) {
		return false
	}
	seen := make(map[string]int, len(a))
	for _, n := range a {
		seen[n.String()]++
	}
	for _, n := range b {
		key := n.String()
		if seen[key] == 0 {
			return false
		}
		seen[key]--
	}
	return true
}

func joinNets(nets []net.IPNet) string {
	parts := make([]string, 0, len(nets))
	for _, n := range nets {
		parts = append(parts, n.String())
	}
	if len(parts) == 0 {
		return "(nothing)"
	}
	return strings.Join(parts, ", ")
}

// ParseSSHSources turns a CIDR string into firewall source networks. An empty
// string yields the world-open default (0.0.0.0/0 and ::/0).
func ParseSSHSources(sshCIDR string) ([]net.IPNet, error) {
	if sshCIDR == "" {
		return []net.IPNet{
			{IP: net.IPv4zero, Mask: net.CIDRMask(0, 32)},
			{IP: net.IPv6zero, Mask: net.CIDRMask(0, 128)},
		}, nil
	}
	_, ipNet, err := net.ParseCIDR(sshCIDR)
	if err != nil {
		return nil, fmt.Errorf("invalid --ssh-allow-cidr %q: %w", sshCIDR, err)
	}
	return []net.IPNet{*ipNet}, nil
}

func attach(ctx context.Context, client *hcloud.Client, fw *hcloud.Firewall, server *hcloud.Server) error {
	resources := []hcloud.FirewallResource{
		{
			Type:   hcloud.FirewallResourceTypeServer,
			Server: &hcloud.FirewallResourceServer{ID: server.ID},
		},
	}
	actions, _, err := client.Firewall.ApplyResources(ctx, fw, resources)
	if err != nil {
		return fmt.Errorf("attach firewall to server: %w", err)
	}
	for _, a := range actions {
		if err := client.Action.WaitForFunc(ctx, nil, a); err != nil {
			return fmt.Errorf("wait for firewall attach action: %w", err)
		}
	}
	return nil
}
