// SPDX-License-Identifier: MIT

package hcloud

import (
	"context"
	"strings"
	"testing"

	"github.com/elgorro/aiquila/hetzner/internal/hcloudtest"
	"github.com/elgorro/aiquila/hetzner/internal/profile"
)

// isolate gives the test an empty home (so no real profile is read) and
// points NewClient at a fake API through HCLOUD_ENDPOINT.
func isolate(t *testing.T) *hcloudtest.API {
	t.Helper()
	t.Setenv("HOME", t.TempDir())
	t.Setenv("HCLOUD_TOKEN", "")
	api := hcloudtest.New(t)
	api.JSON("GET /locations", map[string]any{"locations": []any{}})
	t.Setenv("HCLOUD_ENDPOINT", api.URL())
	return api
}

// tokenSent makes one call through NewClient and returns the bearer token the
// API received.
func tokenSent(t *testing.T, api *hcloudtest.API, flagToken, profileName string) string {
	t.Helper()
	client, err := NewClient(flagToken, profileName)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := client.Location.All(context.Background()); err != nil {
		t.Fatal(err)
	}
	return strings.TrimPrefix(api.Last("GET /locations").Header.Get("Authorization"), "Bearer ")
}

func saveProfiles(t *testing.T, cfg *profile.Config) {
	t.Helper()
	if err := profile.Save(cfg); err != nil {
		t.Fatal(err)
	}
}

func TestNewClientPrefersTheFlagToken(t *testing.T) {
	api := isolate(t)
	t.Setenv("HCLOUD_TOKEN", "env-token")
	saveProfiles(t, &profile.Config{Current: "p", Profiles: map[string]profile.Profile{"p": {Token: "profile-token"}}})

	if got := tokenSent(t, api, "flag-token", ""); got != "flag-token" {
		t.Errorf("token = %q", got)
	}
}

func TestNewClientFallsBackToTheEnvironment(t *testing.T) {
	api := isolate(t)
	t.Setenv("HCLOUD_TOKEN", "env-token")
	saveProfiles(t, &profile.Config{Current: "p", Profiles: map[string]profile.Profile{"p": {Token: "profile-token"}}})

	if got := tokenSent(t, api, "", ""); got != "env-token" {
		t.Errorf("token = %q", got)
	}
}

func TestNewClientUsesTheNamedProfileOverTheCurrentOne(t *testing.T) {
	api := isolate(t)
	saveProfiles(t, &profile.Config{Current: "default", Profiles: map[string]profile.Profile{
		"default": {Token: "default-token"},
		"staging": {Token: "staging-token"},
	}})

	if got := tokenSent(t, api, "", "staging"); got != "staging-token" {
		t.Errorf("named profile: token = %q", got)
	}
	if got := tokenSent(t, api, "", ""); got != "default-token" {
		t.Errorf("current profile: token = %q", got)
	}
}

func TestNewClientWithoutAnyTokenExplainsWhereToSetOne(t *testing.T) {
	isolate(t)

	_, err := NewClient("", "")
	if err == nil || !strings.Contains(err.Error(), "--token, $HCLOUD_TOKEN, or configure a profile") {
		t.Fatalf("err = %v", err)
	}
}
