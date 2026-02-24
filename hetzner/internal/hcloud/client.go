package hcloud

import (
	"fmt"
	"os"

	"github.com/hetznercloud/hcloud-go/v2/hcloud"
)

// NewClient initialises a hcloud API client.
// token is taken from the --token flag; if empty, HCLOUD_TOKEN env var is used.
func NewClient(token string) (*hcloud.Client, error) {
	if token == "" {
		token = os.Getenv("HCLOUD_TOKEN")
	}
	if token == "" {
		return nil, fmt.Errorf("Hetzner API token is required: set HCLOUD_TOKEN or pass --token")
	}
	return hcloud.NewClient(hcloud.WithToken(token)), nil
}
