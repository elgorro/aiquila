package server

import (
	"context"
	"fmt"
	"time"

	"github.com/hetznercloud/hcloud-go/v2/hcloud"
)

const (
	pollInterval = 10 * time.Second
	pollTimeout  = 10 * time.Minute
)

// Options contains parameters for creating a Hetzner server.
type Options struct {
	Name       string
	ServerType string
	Image      string
	Location   string
	SSHKeyName string
	UserData   string
	Labels     map[string]string
}

// Create provisions a new Hetzner server and waits until it is running.
func Create(ctx context.Context, client *hcloud.Client, opts Options) (*hcloud.Server, error) {
	serverType, _, err := client.ServerType.GetByName(ctx, opts.ServerType)
	if err != nil {
		return nil, fmt.Errorf("look up server type %q: %w", opts.ServerType, err)
	}
	if serverType == nil {
		return nil, fmt.Errorf("unknown server type: %q", opts.ServerType)
	}

	image, _, err := client.Image.GetByNameAndArchitecture(ctx, opts.Image, serverType.Architecture)
	if err != nil {
		return nil, fmt.Errorf("look up image %q: %w", opts.Image, err)
	}
	if image == nil {
		return nil, fmt.Errorf("unknown image: %q", opts.Image)
	}

	location, _, err := client.Location.GetByName(ctx, opts.Location)
	if err != nil {
		return nil, fmt.Errorf("look up location %q: %w", opts.Location, err)
	}
	if location == nil {
		return nil, fmt.Errorf("unknown location: %q", opts.Location)
	}

	createOpts := hcloud.ServerCreateOpts{
		Name:       opts.Name,
		ServerType: serverType,
		Image:      image,
		Location:   location,
		UserData:   opts.UserData,
		Labels:     opts.Labels,
	}

	if opts.SSHKeyName != "" {
		sshKey, _, err := client.SSHKey.GetByName(ctx, opts.SSHKeyName)
		if err != nil {
			return nil, fmt.Errorf("look up SSH key %q: %w", opts.SSHKeyName, err)
		}
		if sshKey != nil {
			createOpts.SSHKeys = []*hcloud.SSHKey{sshKey}
		}
	}

	fmt.Printf("  Creating server %q (type=%s image=%s location=%s)...\n",
		opts.Name, opts.ServerType, opts.Image, opts.Location)

	result, _, err := client.Server.Create(ctx, createOpts)
	if err != nil {
		return nil, fmt.Errorf("create server: %w", err)
	}

	// Wait for creation action
	if err := client.Action.WaitForFunc(ctx, nil, result.Action); err != nil {
		return nil, fmt.Errorf("wait for server create action: %w", err)
	}

	fmt.Printf("  Server %q created (id=%d), waiting for running state...\n", opts.Name, result.Server.ID)

	// Poll until running
	deadline := time.Now().Add(pollTimeout)
	for {
		srv, _, err := client.Server.GetByID(ctx, result.Server.ID)
		if err != nil {
			return nil, fmt.Errorf("poll server status: %w", err)
		}
		if srv.Status == hcloud.ServerStatusRunning {
			fmt.Printf("  Server %q is running (ip=%s)\n", srv.Name, srv.PublicNet.IPv4.IP.String())
			return srv, nil
		}
		if time.Now().After(deadline) {
			return nil, fmt.Errorf("timeout waiting for server to reach running state (current: %s)", srv.Status)
		}
		fmt.Printf("  Status: %s — retrying in %s...\n", srv.Status, pollInterval)
		time.Sleep(pollInterval)
	}
}
