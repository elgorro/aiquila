// Package dns provides a minimal client for the Hetzner DNS API.
// API base: https://dns.hetzner.com/api/v1
//
// Token resolution order for helpers:
//  1. flagToken argument (--dns-token)
//  2. HETZNER_DNS_TOKEN environment variable
//  3. HCLOUD_TOKEN environment variable (same token works for both APIs)
//  4. dns_token field in the active profile
package dns

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"

	"github.com/elgorro/aiquila/hetzner/internal/profile"
)

const baseURL = "https://dns.hetzner.com/api/v1"

// ── API types ─────────────────────────────────────────────────────────────────

type zone struct {
	ID   string `json:"id"`
	Name string `json:"name"`
}

type record struct {
	ID     string `json:"id"`
	ZoneID string `json:"zone_id"`
	Type   string `json:"type"`
	Name   string `json:"name"`
	Value  string `json:"value"`
	TTL    int    `json:"ttl"`
}

type zonesResponse struct {
	Zones []zone `json:"zones"`
}

type recordsResponse struct {
	Records []record `json:"records"`
}

type upsertRecordRequest struct {
	ZoneID string `json:"zone_id"`
	Type   string `json:"type"`
	Name   string `json:"name"`
	Value  string `json:"value"`
	TTL    int    `json:"ttl"`
}

type apiError struct {
	Message string `json:"message"`
}

// ── Public API ────────────────────────────────────────────────────────────────

// EnsureRecord creates or updates an A or AAAA record.
// zone is the apex domain (e.g. "example.com"), name is the subdomain
// (e.g. "mcp" → "mcp.example.com"), recordType is "A" or "AAAA".
// If a matching record already exists with the same value it is a no-op.
func EnsureRecord(ctx context.Context, token, zoneName, name, ip, recordType string) error {
	zoneID, err := lookupZoneID(ctx, token, zoneName)
	if err != nil {
		return err
	}

	existing, err := findRecord(ctx, token, zoneID, name, recordType)
	if err != nil {
		return err
	}

	if existing != nil {
		if existing.Value == ip {
			fmt.Printf("  DNS %s %s.%s → %s already up to date\n", recordType, name, zoneName, ip)
			return nil
		}
		fmt.Printf("  Updating DNS %s %s.%s → %s\n", recordType, name, zoneName, ip)
		return updateRecord(ctx, token, existing.ID, zoneID, recordType, name, ip, existing.TTL)
	}

	fmt.Printf("  Creating DNS %s %s.%s → %s\n", recordType, name, zoneName, ip)
	return createRecord(ctx, token, zoneID, recordType, name, ip)
}

// DeleteRecords removes A and AAAA records for name in zone.
// Missing records are silently skipped.
func DeleteRecords(ctx context.Context, token, zoneName, name string) error {
	zoneID, err := lookupZoneID(ctx, token, zoneName)
	if err != nil {
		return err
	}

	for _, t := range []string{"A", "AAAA"} {
		rec, err := findRecord(ctx, token, zoneID, name, t)
		if err != nil {
			return err
		}
		if rec == nil {
			continue
		}
		if err := deleteRecord(ctx, token, rec.ID); err != nil {
			return fmt.Errorf("delete DNS %s %s.%s: %w", t, name, zoneName, err)
		}
		fmt.Printf("  Deleted DNS %s %s.%s (id=%s)\n", t, name, zoneName, rec.ID)
	}
	return nil
}

// ResolveToken resolves the DNS API token.
// Order: flagToken → $HETZNER_DNS_TOKEN → $HCLOUD_TOKEN → profile dns_token.
func ResolveToken(flagToken, profileName string) (string, error) {
	if flagToken != "" {
		return flagToken, nil
	}
	if t := os.Getenv("HETZNER_DNS_TOKEN"); t != "" {
		return t, nil
	}
	if t := os.Getenv("HCLOUD_TOKEN"); t != "" {
		return t, nil
	}
	cfg, err := profile.Load()
	if err == nil {
		if p, _, ok := cfg.Active(profileName); ok && p.DNSToken != "" {
			return p.DNSToken, nil
		}
	}
	return "", fmt.Errorf(
		"no DNS API token: use --dns-token, $HETZNER_DNS_TOKEN, $HCLOUD_TOKEN, or configure a profile with 'aiquila-hetzner profile add --dns-token'",
	)
}

// ── Internal helpers ──────────────────────────────────────────────────────────

func lookupZoneID(ctx context.Context, token, zoneName string) (string, error) {
	resp, err := doGet(ctx, token, "/zones?name="+url.QueryEscape(zoneName))
	if err != nil {
		return "", fmt.Errorf("DNS: look up zone %q: %w", zoneName, err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return "", fmt.Errorf("DNS: look up zone %q: HTTP %d", zoneName, resp.StatusCode)
	}

	var result zonesResponse
	if err := json.NewDecoder(resp.Body).Decode(&result); err != nil {
		return "", fmt.Errorf("DNS: parse zone response: %w", err)
	}
	if len(result.Zones) == 0 {
		return "", fmt.Errorf("DNS zone %q not found — does the zone exist in your Hetzner DNS account?", zoneName)
	}
	return result.Zones[0].ID, nil
}

func findRecord(ctx context.Context, token, zoneID, name, recordType string) (*record, error) {
	path := fmt.Sprintf("/records?zone_id=%s", url.QueryEscape(zoneID))
	resp, err := doGet(ctx, token, path)
	if err != nil {
		return nil, fmt.Errorf("DNS: list records: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("DNS: list records: HTTP %d", resp.StatusCode)
	}

	var result recordsResponse
	if err := json.NewDecoder(resp.Body).Decode(&result); err != nil {
		return nil, fmt.Errorf("DNS: parse records response: %w", err)
	}
	for i, r := range result.Records {
		if r.Name == name && r.Type == recordType {
			return &result.Records[i], nil
		}
	}
	return nil, nil
}

func createRecord(ctx context.Context, token, zoneID, recordType, name, ip string) error {
	body := upsertRecordRequest{
		ZoneID: zoneID,
		Type:   recordType,
		Name:   name,
		Value:  ip,
		TTL:    300,
	}
	resp, err := doJSON(ctx, token, http.MethodPost, "/records", body)
	if err != nil {
		return fmt.Errorf("DNS: create %s record: %w", recordType, err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusCreated && resp.StatusCode != http.StatusOK {
		return apiErrorFrom(resp, fmt.Sprintf("DNS: create %s record", recordType))
	}
	return nil
}

func updateRecord(ctx context.Context, token, id, zoneID, recordType, name, ip string, ttl int) error {
	body := upsertRecordRequest{
		ZoneID: zoneID,
		Type:   recordType,
		Name:   name,
		Value:  ip,
		TTL:    ttl,
	}
	resp, err := doJSON(ctx, token, http.MethodPut, "/records/"+id, body)
	if err != nil {
		return fmt.Errorf("DNS: update %s record: %w", recordType, err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return apiErrorFrom(resp, fmt.Sprintf("DNS: update %s record", recordType))
	}
	return nil
}

func deleteRecord(ctx context.Context, token, id string) error {
	resp, err := doRequest(ctx, token, http.MethodDelete, "/records/"+id, nil)
	if err != nil {
		return fmt.Errorf("DNS: delete record %s: %w", id, err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK && resp.StatusCode != http.StatusNoContent {
		return apiErrorFrom(resp, "DNS: delete record")
	}
	return nil
}

// ── HTTP helpers ──────────────────────────────────────────────────────────────

func doGet(ctx context.Context, token, path string) (*http.Response, error) {
	return doRequest(ctx, token, http.MethodGet, path, nil)
}

func doJSON(ctx context.Context, token, method, path string, body interface{}) (*http.Response, error) {
	data, err := json.Marshal(body)
	if err != nil {
		return nil, err
	}
	return doRequest(ctx, token, method, path, bytes.NewReader(data))
}

func doRequest(ctx context.Context, token, method, path string, body io.Reader) (*http.Response, error) {
	req, err := http.NewRequestWithContext(ctx, method, baseURL+path, body)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Auth-API-Token", token)
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	return http.DefaultClient.Do(req)
}

func apiErrorFrom(resp *http.Response, prefix string) error {
	var ae apiError
	data, _ := io.ReadAll(resp.Body)
	if err := json.Unmarshal(data, &ae); err == nil && ae.Message != "" {
		return fmt.Errorf("%s: HTTP %d — %s", prefix, resp.StatusCode, ae.Message)
	}
	return fmt.Errorf("%s: HTTP %d", prefix, resp.StatusCode)
}
