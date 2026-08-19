// SPDX-License-Identifier: MIT

package firewall

import (
	"net"
	"testing"

	"github.com/hetznercloud/hcloud-go/v2/hcloud"
)

func TestParseSSHSources(t *testing.T) {
	cases := []struct {
		name    string
		cidr    string
		want    []string
		wantErr bool
	}{
		{name: "empty is world-open", cidr: "", want: []string{"0.0.0.0/0", "::/0"}},
		{name: "ipv4 network", cidr: "203.0.113.0/24", want: []string{"203.0.113.0/24"}},
		{name: "ipv4 single host", cidr: "203.0.113.7/32", want: []string{"203.0.113.7/32"}},
		{name: "ipv6 host", cidr: "2001:db8::1/128", want: []string{"2001:db8::1/128"}},
		{name: "host bits are masked off", cidr: "203.0.113.7/24", want: []string{"203.0.113.0/24"}},
		{name: "address without prefix", cidr: "203.0.113.7", wantErr: true},
		{name: "garbage", cidr: "not-a-cidr", wantErr: true},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			got, err := ParseSSHSources(tc.cidr)
			if tc.wantErr {
				if err == nil {
					t.Fatalf("ParseSSHSources(%q) = %v, want error", tc.cidr, got)
				}
				return
			}
			if err != nil {
				t.Fatalf("ParseSSHSources(%q): %v", tc.cidr, err)
			}
			if len(got) != len(tc.want) {
				t.Fatalf("got %d sources (%v), want %d", len(got), got, len(tc.want))
			}
			for i, want := range tc.want {
				if got[i].String() != want {
					t.Errorf("source %d = %s, want %s", i, got[i].String(), want)
				}
			}
		})
	}
}

func mustCIDR(t *testing.T, s string) net.IPNet {
	t.Helper()
	_, n, err := net.ParseCIDR(s)
	if err != nil {
		t.Fatalf("parse %q: %v", s, err)
	}
	return *n
}

func TestSameSources(t *testing.T) {
	a := []net.IPNet{mustCIDR(t, "0.0.0.0/0"), mustCIDR(t, "::/0")}
	reordered := []net.IPNet{mustCIDR(t, "::/0"), mustCIDR(t, "0.0.0.0/0")}
	other := []net.IPNet{mustCIDR(t, "203.0.113.7/32")}

	if !sameSources(a, reordered) {
		t.Error("sameSources should ignore ordering")
	}
	if sameSources(a, other) {
		t.Error("different sources reported as equal")
	}
	if sameSources(a, other[:0]) {
		t.Error("different lengths reported as equal")
	}
}

func TestWarnSSHRuleMismatchFindsRule(t *testing.T) {
	port := "22"
	fw := &hcloud.Firewall{
		Name: "example-fw",
		Rules: []hcloud.FirewallRule{{
			Direction: hcloud.FirewallRuleDirectionIn,
			Protocol:  hcloud.FirewallRuleProtocolTCP,
			Port:      &port,
			SourceIPs: []net.IPNet{mustCIDR(t, "203.0.113.7/32")},
		}},
	}

	// Matching sources must not be reported; this only asserts the lookup and
	// comparison agree — the warning itself goes to stderr.
	want, err := ParseSSHSources("203.0.113.7/32")
	if err != nil {
		t.Fatal(err)
	}
	if !sameSources(fw.Rules[0].SourceIPs, want) {
		t.Error("expected the TCP/22 rule to match the requested source")
	}
	warnSSHRuleMismatch(fw, want)
}

func TestJoinNets(t *testing.T) {
	if got := joinNets(nil); got != "(nothing)" {
		t.Errorf("joinNets(nil) = %q", got)
	}
	got := joinNets([]net.IPNet{mustCIDR(t, "0.0.0.0/0"), mustCIDR(t, "::/0")})
	if got != "0.0.0.0/0, ::/0" {
		t.Errorf("joinNets = %q", got)
	}
}
