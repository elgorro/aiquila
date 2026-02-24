package hcloud

import (
	"fmt"
	"os"

	"github.com/elgorro/aiquila/hetzner/internal/profile"
	"github.com/hetznercloud/hcloud-go/v2/hcloud"
)

// NewClient initialises a hcloud API client.
// Token resolution order (first non-empty wins):
//  1. flagToken argument (from --token flag)
//  2. HCLOUD_TOKEN environment variable
//  3. Token stored in the named profile (profileName)
//  4. Token stored in the current/default profile
func NewClient(flagToken, profileName string) (*hcloud.Client, error) {
	token := flagToken
	if token == "" {
		token = os.Getenv("HCLOUD_TOKEN")
	}
	if token == "" {
		cfg, err := profile.Load()
		if err == nil {
			if p, _, ok := cfg.Active(profileName); ok && p.Token != "" {
				token = p.Token
			}
		}
	}
	if token == "" {
		return nil, fmt.Errorf(
			"no Hetzner API token: use --token, $HCLOUD_TOKEN, or configure a profile with 'aiquila-hetzner profile add'",
		)
	}
	return hcloud.NewClient(hcloud.WithToken(token)), nil
}
