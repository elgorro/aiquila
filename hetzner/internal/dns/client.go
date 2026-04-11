// SPDX-License-Identifier: MIT

// Package dns manages DNS records via the hcloud-go Zone API.
package dns

import (
	"context"
	"fmt"

	"github.com/hetznercloud/hcloud-go/v2/hcloud"
)

const defaultTTL = 300

// EnsureRecord creates or updates an A or AAAA record.
// zoneName is the apex domain (e.g. "example.com"), name is the subdomain
// (e.g. "mcp" → "mcp.example.com"), recordType is "A" or "AAAA".
// If a matching record already exists with the same value it is a no-op.
func EnsureRecord(ctx context.Context, client *hcloud.Client, zoneName, name, ip, recordType string) error {
	zone, _, err := client.Zone.GetByName(ctx, zoneName)
	if err != nil {
		return fmt.Errorf("DNS: look up zone %q: %w", zoneName, err)
	}
	if zone == nil {
		return fmt.Errorf("DNS zone %q not found — does the zone exist in your Hetzner account?", zoneName)
	}

	rrsetType := hcloud.ZoneRRSetType(recordType)

	existing, _, err := client.Zone.GetRRSetByNameAndType(ctx, zone, name, rrsetType)
	if err != nil {
		return fmt.Errorf("DNS: look up %s record %s.%s: %w", recordType, name, zoneName, err)
	}

	if existing != nil {
		if len(existing.Records) == 1 && existing.Records[0].Value == ip {
			fmt.Printf("  DNS %s %s.%s → %s already up to date\n", recordType, name, zoneName, ip)
			return nil
		}
		fmt.Printf("  Updating DNS %s %s.%s → %s\n", recordType, name, zoneName, ip)
		_, _, err := client.Zone.SetRRSetRecords(ctx, existing, hcloud.ZoneRRSetSetRecordsOpts{
			Records: []hcloud.ZoneRRSetRecord{{Value: ip}},
		})
		if err != nil {
			return fmt.Errorf("DNS: update %s record %s.%s: %w", recordType, name, zoneName, err)
		}
		return nil
	}

	fmt.Printf("  Creating DNS %s %s.%s → %s\n", recordType, name, zoneName, ip)
	ttl := defaultTTL
	_, _, err = client.Zone.CreateRRSet(ctx, zone, hcloud.ZoneRRSetCreateOpts{
		Name:    name,
		Type:    rrsetType,
		TTL:     &ttl,
		Records: []hcloud.ZoneRRSetRecord{{Value: ip}},
	})
	if err != nil {
		return fmt.Errorf("DNS: create %s record %s.%s: %w", recordType, name, zoneName, err)
	}
	return nil
}

// DeleteRecords removes A and AAAA RRSets for name in zone.
// Missing records are silently skipped.
func DeleteRecords(ctx context.Context, client *hcloud.Client, zoneName, name string) error {
	zone, _, err := client.Zone.GetByName(ctx, zoneName)
	if err != nil {
		return fmt.Errorf("DNS: look up zone %q: %w", zoneName, err)
	}
	if zone == nil {
		return fmt.Errorf("DNS zone %q not found — does the zone exist in your Hetzner account?", zoneName)
	}

	for _, t := range []hcloud.ZoneRRSetType{hcloud.ZoneRRSetTypeA, hcloud.ZoneRRSetTypeAAAA} {
		rrset, _, err := client.Zone.GetRRSetByNameAndType(ctx, zone, name, t)
		if err != nil {
			return fmt.Errorf("DNS: look up %s record %s.%s: %w", t, name, zoneName, err)
		}
		if rrset == nil {
			continue
		}
		if _, _, err := client.Zone.DeleteRRSet(ctx, rrset); err != nil {
			return fmt.Errorf("DNS: delete %s %s.%s: %w", t, name, zoneName, err)
		}
		fmt.Printf("  Deleted DNS %s %s.%s\n", t, name, zoneName)
	}
	return nil
}
