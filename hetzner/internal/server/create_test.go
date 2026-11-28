// SPDX-License-Identifier: MIT

package server

import (
	"context"
	"net/http"
	"strings"
	"testing"

	"github.com/elgorro/aiquila/hetzner/internal/hcloudtest"
)

func serverType(name string) map[string]any {
	return map[string]any{"id": 22, "name": name, "architecture": "x86", "cores": 2, "memory": 4, "disk": 80,
		"prices": []any{map[string]any{"location": "nbg1"}, map[string]any{"location": "fsn1"}}}
}

func serverJSON(status string) map[string]any {
	return map[string]any{"id": 99, "name": "aiq", "status": status, "created": "2026-01-01T00:00:00Z", "labels": map[string]any{},
		"public_net": map[string]any{"ipv4": map[string]any{"ip": "1.2.3.4"}, "ipv6": map[string]any{"ip": "2001:db8::/64"}, "floating_ips": []any{}}}
}

// lookups answers the server type, image, location and SSH key lookups;
// a nil list stands for "not found".
func lookups(api *hcloudtest.API, types, images, locations []any) {
	api.JSON("GET /server_types", map[string]any{"server_types": types})
	api.JSON("GET /images", map[string]any{"images": images})
	api.JSON("GET /locations", map[string]any{"locations": locations})
	api.JSON("GET /ssh_keys", map[string]any{"ssh_keys": []any{map[string]any{"id": 5, "name": "aiq-key", "fingerprint": "f", "public_key": "ssh-ed25519 AAAA", "labels": map[string]any{}, "created": "2026-01-01T00:00:00Z"}}})
}

func opts() Options {
	return Options{Name: "aiq", ServerType: "cpx21", Image: "ubuntu-24.04", Location: "nbg1", SSHKeyName: "aiq-key",
		UserData: "#cloud-config\n", Labels: map[string]string{"app": "aiquila"}}
}

func TestCreateSendsTheResolvedOptionsAndWaitsForRunning(t *testing.T) {
	api := hcloudtest.New(t)
	lookups(api,
		[]any{serverType("cpx21")},
		[]any{map[string]any{"id": 31, "name": "ubuntu-24.04", "type": "system", "architecture": "x86", "status": "available"}},
		[]any{map[string]any{"id": 1, "name": "nbg1"}})
	api.Handle("POST /servers", func(hcloudtest.Request) (int, any) {
		return http.StatusCreated, map[string]any{"server": serverJSON("initializing"), "action": hcloudtest.Action(1, "create_server"), "next_actions": []any{}}
	})
	api.JSON("GET /servers/99", map[string]any{"server": serverJSON("running")})

	srv, err := Create(context.Background(), api.Client(), opts())
	if err != nil {
		t.Fatal(err)
	}
	if srv.ID != 99 || srv.PublicNet.IPv4.IP.String() != "1.2.3.4" {
		t.Errorf("server = %+v", srv)
	}
	if arch := api.Last("GET /images").Query.Get("architecture"); arch != "x86" {
		t.Errorf("image looked up for architecture %q, want the server type's", arch)
	}
	body := api.Last("POST /servers").Body
	if body["name"] != "aiq" || body["server_type"] != float64(22) || body["image"] != float64(31) || body["location"] != "1" || body["user_data"] != "#cloud-config\n" {
		t.Errorf("create body = %#v", body)
	}
	if keys, _ := body["ssh_keys"].([]any); len(keys) != 1 || keys[0] != float64(5) {
		t.Errorf("ssh_keys = %v", body["ssh_keys"])
	}
}

func TestCreateListsTheServerTypesThatExist(t *testing.T) {
	api := hcloudtest.New(t)
	api.Handle("GET /server_types", func(r hcloudtest.Request) (int, any) {
		if r.Query.Get("name") != "" {
			return http.StatusOK, map[string]any{"server_types": []any{}}
		}
		return http.StatusOK, map[string]any{"server_types": []any{serverType("cx22"), serverType("cpx11")}}
	})

	_, err := Create(context.Background(), api.Client(), opts())
	if err == nil || !strings.Contains(err.Error(), `unknown server type: "cpx21"`) || !strings.Contains(err.Error(), "Available types: cpx11, cx22") {
		t.Fatalf("err = %v", err)
	}
}

func TestCreateListsTheImagesForTheArchitecture(t *testing.T) {
	api := hcloudtest.New(t)
	api.JSON("GET /server_types", map[string]any{"server_types": []any{serverType("cpx21")}})
	api.Handle("GET /images", func(r hcloudtest.Request) (int, any) {
		if r.Query.Get("name") != "" {
			return http.StatusOK, map[string]any{"images": []any{}}
		}
		return http.StatusOK, map[string]any{"images": []any{
			map[string]any{"id": 2, "name": "debian-13", "type": "system", "architecture": "x86"},
			map[string]any{"id": 1, "name": "alma-10", "type": "system", "architecture": "x86"},
		}}
	})

	_, err := Create(context.Background(), api.Client(), opts())
	if err == nil || !strings.Contains(err.Error(), "Available x86 images: alma-10, debian-13") {
		t.Fatalf("err = %v", err)
	}
}

func TestCreateNamesTheLocationsAServerTypeIsSoldIn(t *testing.T) {
	api := hcloudtest.New(t)
	lookups(api,
		[]any{serverType("cpx21")},
		[]any{map[string]any{"id": 31, "name": "ubuntu-24.04", "type": "system", "architecture": "x86"}},
		[]any{map[string]any{"id": 3, "name": "hel1"}})
	api.Handle("POST /servers", func(hcloudtest.Request) (int, any) {
		return http.StatusUnprocessableEntity, hcloudtest.ErrorBody("invalid_input", "unsupported location for server type")
	})

	o := opts()
	o.Location = "hel1"
	_, err := Create(context.Background(), api.Client(), o)
	if err == nil || !strings.Contains(err.Error(), "cpx21 is only available at: fsn1, nbg1") {
		t.Fatalf("err = %v", err)
	}
}
