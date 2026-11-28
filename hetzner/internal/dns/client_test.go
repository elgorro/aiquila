// SPDX-License-Identifier: MIT

package dns

import (
	"context"
	"net/http"
	"strings"
	"testing"

	"github.com/elgorro/aiquila/hetzner/internal/hcloudtest"
)

func zone() map[string]any {
	return map[string]any{"zone": map[string]any{"id": 7, "name": "example.com", "mode": "primary"}}
}

func rrset(name, typ, value string) map[string]any {
	return map[string]any{"rrset": map[string]any{
		"id": name + "/" + typ, "name": name, "type": typ, "ttl": 300,
		"records": []any{map[string]any{"value": value}},
		"zone":    7,
	}}
}

func TestEnsureRecordCreatesAMissingRecord(t *testing.T) {
	api := hcloudtest.New(t)
	api.JSON("GET /zones/example.com", zone())
	api.NotFound("GET /zones/7/rrsets/mcp/A")
	api.Handle("POST /zones/7/rrsets", func(r hcloudtest.Request) (int, any) {
		return http.StatusCreated, map[string]any{"rrset": rrset("mcp", "A", "1.2.3.4")["rrset"], "action": hcloudtest.Action(1, "create_rrset")}
	})

	if err := EnsureRecord(context.Background(), api.Client(), "example.com", "mcp", "1.2.3.4", "A"); err != nil {
		t.Fatal(err)
	}

	body := api.Last("POST /zones/7/rrsets").Body
	if body["name"] != "mcp" || body["type"] != "A" || body["ttl"] != float64(defaultTTL) {
		t.Errorf("create body = %v", body)
	}
	records, _ := body["records"].([]any)
	if len(records) != 1 || records[0].(map[string]any)["value"] != "1.2.3.4" {
		t.Errorf("records = %v", body["records"])
	}
}

func TestEnsureRecordLeavesAnUpToDateRecordAlone(t *testing.T) {
	api := hcloudtest.New(t)
	api.JSON("GET /zones/example.com", zone())
	api.JSON("GET /zones/7/rrsets/mcp/AAAA", rrset("mcp", "AAAA", "2001:db8::1"))

	if err := EnsureRecord(context.Background(), api.Client(), "example.com", "mcp", "2001:db8::1", "AAAA"); err != nil {
		t.Fatal(err)
	}
	if n := len(api.Requests()); n != 2 {
		t.Errorf("made %d requests, want only the two lookups: %v", n, api.Requests())
	}
}

func TestEnsureRecordReplacesAChangedValue(t *testing.T) {
	api := hcloudtest.New(t)
	api.JSON("GET /zones/example.com", zone())
	api.JSON("GET /zones/7/rrsets/mcp/A", rrset("mcp", "A", "9.9.9.9"))
	api.JSON("POST /zones/7/rrsets/mcp/A/actions/set_records", map[string]any{"action": hcloudtest.Action(2, "set_records")})

	if err := EnsureRecord(context.Background(), api.Client(), "example.com", "mcp", "1.2.3.4", "A"); err != nil {
		t.Fatal(err)
	}
	records, _ := api.Last("POST /zones/7/rrsets/mcp/A/actions/set_records").Body["records"].([]any)
	if len(records) != 1 || records[0].(map[string]any)["value"] != "1.2.3.4" {
		t.Errorf("set_records = %v", records)
	}
}

func TestEnsureRecordNamesAMissingZone(t *testing.T) {
	api := hcloudtest.New(t)
	api.NotFound("GET /zones/example.com")

	err := EnsureRecord(context.Background(), api.Client(), "example.com", "mcp", "1.2.3.4", "A")
	if err == nil || !strings.Contains(err.Error(), `DNS zone "example.com" not found`) {
		t.Fatalf("err = %v", err)
	}
}

func TestDeleteRecordsSkipsTypesThatDoNotExist(t *testing.T) {
	api := hcloudtest.New(t)
	api.JSON("GET /zones/example.com", zone())
	api.JSON("GET /zones/7/rrsets/mcp/A", rrset("mcp", "A", "1.2.3.4"))
	api.NotFound("GET /zones/7/rrsets/mcp/AAAA")
	api.JSON("DELETE /zones/7/rrsets/mcp/A", map[string]any{"action": hcloudtest.Action(3, "delete_rrset")})

	if err := DeleteRecords(context.Background(), api.Client(), "example.com", "mcp"); err != nil {
		t.Fatal(err)
	}
	if api.Called("DELETE /zones/7/rrsets/mcp/A") != 1 || api.Called("DELETE /zones/7/rrsets/mcp/AAAA") != 0 {
		t.Errorf("requests = %v", api.Requests())
	}
}
