package network

import (
	"context"
	"fmt"
	"net"

	"github.com/hetznercloud/hcloud-go/v2/hcloud"
)

// Create is idempotent: if a network with the given name already exists it is
// returned unchanged. Otherwise a new network is created with a single "cloud"
// subnet covering the full CIDR.
func Create(ctx context.Context, client *hcloud.Client, name, cidr string, zone hcloud.NetworkZone, labels map[string]string) (*hcloud.Network, error) {
	existing, _, err := client.Network.GetByName(ctx, name)
	if err != nil {
		return nil, fmt.Errorf("look up network %q: %w", name, err)
	}
	if existing != nil {
		fmt.Printf("  Reusing existing network %q (id=%d)\n", name, existing.ID)
		return existing, nil
	}

	_, ipNet, err := net.ParseCIDR(cidr)
	if err != nil {
		return nil, fmt.Errorf("invalid CIDR %q: %w", cidr, err)
	}

	network, _, err := client.Network.Create(ctx, hcloud.NetworkCreateOpts{
		Name:    name,
		IPRange: ipNet,
		Labels:  labels,
		Subnets: []hcloud.NetworkSubnet{
			{
				Type:        hcloud.NetworkSubnetTypeCloud,
				IPRange:     ipNet,
				NetworkZone: zone,
			},
		},
	})
	if err != nil {
		return nil, fmt.Errorf("create network: %w", err)
	}

	fmt.Printf("  Created network %q (id=%d, cidr=%s)\n", name, network.ID, cidr)
	return network, nil
}

// Attach attaches server to network. Returns the private IP assigned.
// If the server is already attached, the existing IP is returned immediately.
func Attach(ctx context.Context, client *hcloud.Client, server *hcloud.Server, network *hcloud.Network) (net.IP, error) {
	// Check if already attached
	for _, pn := range server.PrivateNet {
		if pn.Network.ID == network.ID {
			fmt.Printf("  Server %q already attached to network %q (ip=%s)\n", server.Name, network.Name, pn.IP)
			return pn.IP, nil
		}
	}

	action, _, err := client.Server.AttachToNetwork(ctx, server, hcloud.ServerAttachToNetworkOpts{
		Network: network,
	})
	if err != nil {
		return nil, fmt.Errorf("attach server to network: %w", err)
	}
	if err := client.Action.WaitForFunc(ctx, nil, action); err != nil {
		return nil, fmt.Errorf("wait for attach: %w", err)
	}

	// Re-fetch server to get assigned private IP
	updated, _, err := client.Server.GetByID(ctx, server.ID)
	if err != nil {
		return nil, fmt.Errorf("re-fetch server after attach: %w", err)
	}
	for _, pn := range updated.PrivateNet {
		if pn.Network.ID == network.ID {
			fmt.Printf("  Server %q attached to network %q (ip=%s)\n", server.Name, network.Name, pn.IP)
			return pn.IP, nil
		}
	}

	return nil, fmt.Errorf("attached but could not find private IP for network %q", network.Name)
}

// Detach detaches server from network.
func Detach(ctx context.Context, client *hcloud.Client, server *hcloud.Server, network *hcloud.Network) error {
	action, _, err := client.Server.DetachFromNetwork(ctx, server, hcloud.ServerDetachFromNetworkOpts{
		Network: network,
	})
	if err != nil {
		return fmt.Errorf("detach server from network: %w", err)
	}
	if err := client.Action.WaitForFunc(ctx, nil, action); err != nil {
		return fmt.Errorf("wait for detach: %w", err)
	}
	fmt.Printf("  Server %q detached from network %q\n", server.Name, network.Name)
	return nil
}
