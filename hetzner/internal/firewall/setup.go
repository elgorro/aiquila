package firewall

import (
	"context"
	"fmt"
	"net"

	"github.com/hetznercloud/hcloud-go/v2/hcloud"
)

// Setup creates a Hetzner firewall that allows inbound TCP 22/80/443, UDP 443
// (HTTP/3 / QUIC), and attaches it to the given server. It is idempotent: if a
// firewall with the same name already exists, it is reused.
func Setup(ctx context.Context, client *hcloud.Client, name string, server *hcloud.Server, labels map[string]string) (*hcloud.Firewall, error) {
	existing, _, err := client.Firewall.GetByName(ctx, name)
	if err != nil {
		return nil, fmt.Errorf("look up firewall %q: %w", name, err)
	}
	if existing != nil {
		fmt.Printf("  Reusing existing firewall %q (id=%d)\n", name, existing.ID)
		if err := attach(ctx, client, existing, server); err != nil {
			return nil, err
		}
		return existing, nil
	}

	rules := []hcloud.FirewallRule{
		tcpRule(22, "SSH"),
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
	p := fmt.Sprintf("%d", port)
	return hcloud.FirewallRule{
		Direction:   hcloud.FirewallRuleDirectionIn,
		Protocol:    hcloud.FirewallRuleProtocolTCP,
		Port:        &p,
		SourceIPs:   []net.IPNet{{IP: net.IPv4zero, Mask: net.CIDRMask(0, 32)}, {IP: net.IPv6zero, Mask: net.CIDRMask(0, 128)}},
		Description: hcloud.Ptr(description),
	}
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
