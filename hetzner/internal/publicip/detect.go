// SPDX-License-Identifier: MIT

// Package publicip discovers the public IP address of the machine running the
// CLI, so firewall rules can be scoped to the operator instead of the internet.
package publicip

import (
	"context"
	"fmt"
	"io"
	"net"
	"net/http"
	"strings"
	"time"
)

// defaultEndpoints are queried in order; the first usable answer wins. Each one
// returns the caller's address as a bare string body.
var defaultEndpoints = []string{
	"https://api.ipify.org",
	"https://ifconfig.co/ip",
}

// timeout bounds the whole detection attempt per endpoint.
const timeout = 5 * time.Second

// Detect returns the public IP address of this machine.
func Detect(ctx context.Context) (net.IP, error) {
	return detect(ctx, defaultEndpoints)
}

func detect(ctx context.Context, endpoints []string) (net.IP, error) {
	client := &http.Client{Timeout: timeout}

	var lastErr error
	for _, endpoint := range endpoints {
		ip, err := query(ctx, client, endpoint)
		if err != nil {
			lastErr = err
			continue
		}
		return ip, nil
	}
	if lastErr == nil {
		lastErr = fmt.Errorf("no endpoints configured")
	}
	return nil, fmt.Errorf("detect public IP: %w", lastErr)
}

func query(ctx context.Context, client *http.Client, endpoint string) (net.IP, error) {
	reqCtx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	req, err := http.NewRequestWithContext(reqCtx, http.MethodGet, endpoint, nil)
	if err != nil {
		return nil, fmt.Errorf("%s: %w", endpoint, err)
	}
	req.Header.Set("User-Agent", "aiquila-hetzner")

	resp, err := client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("%s: %w", endpoint, err)
	}
	defer func() { _ = resp.Body.Close() }()

	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("%s: unexpected status %s", endpoint, resp.Status)
	}

	// Cap the read — these services answer with a short address, anything
	// larger is a captive portal or an error page.
	body, err := io.ReadAll(io.LimitReader(resp.Body, 128))
	if err != nil {
		return nil, fmt.Errorf("%s: read response: %w", endpoint, err)
	}

	ip := net.ParseIP(strings.TrimSpace(string(body)))
	if ip == nil {
		return nil, fmt.Errorf("%s: response is not an IP address", endpoint)
	}
	if !isPublic(ip) {
		return nil, fmt.Errorf("%s: returned non-public address %s", endpoint, ip)
	}
	return ip, nil
}

func isPublic(ip net.IP) bool {
	return !ip.IsUnspecified() &&
		!ip.IsLoopback() &&
		!ip.IsPrivate() &&
		!ip.IsLinkLocalUnicast() &&
		!ip.IsLinkLocalMulticast() &&
		!ip.IsMulticast()
}

// CIDR renders ip as a single-host CIDR (/32 for IPv4, /128 for IPv6).
func CIDR(ip net.IP) string {
	if v4 := ip.To4(); v4 != nil {
		return v4.String() + "/32"
	}
	return ip.String() + "/128"
}
