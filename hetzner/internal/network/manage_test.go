// SPDX-License-Identifier: MIT

package network

import (
	"context"
	"net"
	"net/http"
	"strings"
	"testing"

	"github.com/elgorro/aiquila/hetzner/internal/hcloudtest"
	"github.com/hetznercloud/hcloud-go/v2/hcloud"
)

func networkJSON(id int64, name string) map[string]any {
	return map[string]any{"id": id, "name": name, "ip_range": "10.0.0.0/16", "subnets": []any{}, "routes": []any{}, "servers": []any{}, "labels": map[string]any{}, "created": "2026-01-01T00:00:00Z"}
}

func TestCreateReusesAnExistingNetwork(t *testing.T) {
	api := hcloudtest.New(t)
	api.JSON("GET /networks", map[string]any{"networks": []any{networkJSON(4, "aiq-net")}})

	got, err := Create(context.Background(), api.Client(), "aiq-net", "10.0.0.0/16", hcloud.NetworkZoneEUCentral, nil)
	if err != nil {
		t.Fatal(err)
	}
	if got.ID != 4 || api.Called("POST /networks") != 0 {
		t.Errorf("got id %d, requests %v", got.ID, api.Requests())
	}
	if q := api.Last("GET /networks").Query.Get("name"); q != "aiq-net" {
		t.Errorf("looked up name %q", q)
	}
}

func TestCreateMakesOneCloudSubnetOverTheWholeRange(t *testing.T) {
	api := hcloudtest.New(t)
	api.JSON("GET /networks", map[string]any{"networks": []any{}})
	api.Handle("POST /networks", func(hcloudtest.Request) (int, any) {
		return http.StatusCreated, map[string]any{"network": networkJSON(9, "aiq-net")}
	})

	got, err := Create(context.Background(), api.Client(), "aiq-net", "10.1.0.0/16", hcloud.NetworkZoneEUCentral, map[string]string{"app": "aiquila"})
	if err != nil {
		t.Fatal(err)
	}
	if got.ID != 9 {
		t.Errorf("id = %d", got.ID)
	}
	body := api.Last("POST /networks").Body
	subnets, _ := body["subnets"].([]any)
	if body["ip_range"] != "10.1.0.0/16" || len(subnets) != 1 {
		t.Fatalf("body = %v", body)
	}
	sub := subnets[0].(map[string]any)
	if sub["type"] != "cloud" || sub["ip_range"] != "10.1.0.0/16" || sub["network_zone"] != "eu-central" {
		t.Errorf("subnet = %v", sub)
	}
	if body["labels"].(map[string]any)["app"] != "aiquila" {
		t.Errorf("labels = %v", body["labels"])
	}
}

func TestCreateRejectsAnInvalidCIDRBeforeCallingTheAPI(t *testing.T) {
	api := hcloudtest.New(t)
	api.JSON("GET /networks", map[string]any{"networks": []any{}})

	_, err := Create(context.Background(), api.Client(), "aiq-net", "10.0.0.0/99", hcloud.NetworkZoneEUCentral, nil)
	if err == nil || !strings.Contains(err.Error(), "invalid CIDR") {
		t.Fatalf("err = %v", err)
	}
	if api.Called("POST /networks") != 0 {
		t.Error("created a network from an invalid CIDR")
	}
}

func TestAttachReturnsTheExistingIPWithoutAnAPICall(t *testing.T) {
	api := hcloudtest.New(t)
	srv := &hcloud.Server{ID: 1, Name: "s", PrivateNet: []hcloud.ServerPrivateNet{{Network: &hcloud.Network{ID: 4}, IP: net.ParseIP("10.0.0.2")}}}

	ip, err := Attach(context.Background(), api.Client(), srv, &hcloud.Network{ID: 4, Name: "aiq-net"})
	if err != nil {
		t.Fatal(err)
	}
	if ip.String() != "10.0.0.2" || len(api.Requests()) != 0 {
		t.Errorf("ip %s, requests %v", ip, api.Requests())
	}
}

func serverJSON(privateNets ...any) map[string]any {
	return map[string]any{"server": map[string]any{
		"id": 1, "name": "s", "status": "running", "created": "2026-01-01T00:00:00Z",
		"public_net":  map[string]any{"ipv4": map[string]any{"ip": "1.2.3.4"}, "ipv6": map[string]any{"ip": "2001:db8::/64"}, "floating_ips": []any{}},
		"private_net": privateNets,
		"labels":      map[string]any{},
	}}
}

func TestAttachReadsTheAssignedIPBack(t *testing.T) {
	api := hcloudtest.New(t)
	api.JSON("POST /servers/1/actions/attach_to_network", map[string]any{"action": hcloudtest.Action(5, "attach_to_network")})
	api.JSON("GET /servers/1", serverJSON(map[string]any{"network": 4, "ip": "10.0.0.7", "alias_ips": []any{}}))

	ip, err := Attach(context.Background(), api.Client(), &hcloud.Server{ID: 1, Name: "s"}, &hcloud.Network{ID: 4, Name: "aiq-net"})
	if err != nil {
		t.Fatal(err)
	}
	if ip.String() != "10.0.0.7" {
		t.Errorf("ip = %s", ip)
	}
	if n := api.Last("POST /servers/1/actions/attach_to_network").Body["network"]; n != float64(4) {
		t.Errorf("attach body network = %v", n)
	}
}

func TestAttachFailsWhenTheServerShowsNoAddressOnTheNetwork(t *testing.T) {
	api := hcloudtest.New(t)
	api.JSON("POST /servers/1/actions/attach_to_network", map[string]any{"action": hcloudtest.Action(5, "attach_to_network")})
	api.JSON("GET /servers/1", serverJSON())

	_, err := Attach(context.Background(), api.Client(), &hcloud.Server{ID: 1, Name: "s"}, &hcloud.Network{ID: 4, Name: "aiq-net"})
	if err == nil || !strings.Contains(err.Error(), "could not find private IP") {
		t.Fatalf("err = %v", err)
	}
}

func TestDetach(t *testing.T) {
	api := hcloudtest.New(t)
	api.JSON("POST /servers/1/actions/detach_from_network", map[string]any{"action": hcloudtest.Action(6, "detach_from_network")})

	if err := Detach(context.Background(), api.Client(), &hcloud.Server{ID: 1, Name: "s"}, &hcloud.Network{ID: 4, Name: "aiq-net"}); err != nil {
		t.Fatal(err)
	}
	if n := api.Last("POST /servers/1/actions/detach_from_network").Body["network"]; n != float64(4) {
		t.Errorf("detach body network = %v", n)
	}
}
