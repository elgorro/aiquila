// SPDX-License-Identifier: MIT

package publicip

import (
	"context"
	"net"
	"net/http"
	"net/http/httptest"
	"testing"
)

func serve(t *testing.T, status int, body string) string {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(status)
		_, _ = w.Write([]byte(body))
	}))
	t.Cleanup(srv.Close)
	return srv.URL
}

func TestDetect(t *testing.T) {
	ok := serve(t, http.StatusOK, "203.0.113.7\n")
	bad := serve(t, http.StatusOK, "<html>captive portal</html>")
	private := serve(t, http.StatusOK, "10.0.0.1")
	boom := serve(t, http.StatusInternalServerError, "nope")

	cases := []struct {
		name      string
		endpoints []string
		want      string
		wantErr   bool
	}{
		{name: "first endpoint answers", endpoints: []string{ok, bad}, want: "203.0.113.7"},
		{name: "falls through non-IP body", endpoints: []string{bad, ok}, want: "203.0.113.7"},
		{name: "falls through private address", endpoints: []string{private, ok}, want: "203.0.113.7"},
		{name: "falls through error status", endpoints: []string{boom, ok}, want: "203.0.113.7"},
		{name: "all endpoints fail", endpoints: []string{bad, boom}, wantErr: true},
		{name: "no endpoints", endpoints: nil, wantErr: true},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			got, err := detect(context.Background(), tc.endpoints)
			if tc.wantErr {
				if err == nil {
					t.Fatalf("detect = %v, want error", got)
				}
				return
			}
			if err != nil {
				t.Fatalf("detect: %v", err)
			}
			if got.String() != tc.want {
				t.Errorf("detect = %s, want %s", got, tc.want)
			}
		})
	}
}

func TestCIDR(t *testing.T) {
	if got := CIDR(net.ParseIP("203.0.113.7")); got != "203.0.113.7/32" {
		t.Errorf("CIDR(v4) = %q", got)
	}
	if got := CIDR(net.ParseIP("2001:db8::1")); got != "2001:db8::1/128" {
		t.Errorf("CIDR(v6) = %q", got)
	}
}
