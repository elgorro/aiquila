// SPDX-License-Identifier: MIT

// Package hcloudtest fakes the Hetzner Cloud API for tests: an httptest server
// on loopback that answers the routes a test registers and fails the test on
// any other request. Pointing the real hcloud-go client at it tests the
// requests the code actually sends, with no credentials or network.
package hcloudtest

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"sync"
	"testing"
	"time"

	"github.com/hetznercloud/hcloud-go/v2/hcloud"
)

// Request is a request the fake received.
type Request struct {
	Method string
	Path   string
	Query  url.Values
	Header http.Header
	Body   map[string]any
}

// Handler answers a request with a status and a body that is encoded as JSON.
type Handler func(r Request) (status int, body any)

// API is the fake. Register routes with Handle before the code under test runs.
type API struct {
	t      testing.TB
	server *httptest.Server

	mu       sync.Mutex
	routes   map[string]Handler
	requests []Request
}

// New starts the fake and stops it when the test ends.
func New(t testing.TB) *API {
	t.Helper()
	a := &API{t: t, routes: map[string]Handler{}}
	a.server = httptest.NewServer(http.HandlerFunc(a.serve))
	t.Cleanup(a.server.Close)
	return a
}

// URL is the endpoint to pass to hcloud.WithEndpoint (or HCLOUD_ENDPOINT).
func (a *API) URL() string { return a.server.URL }

// Client returns a hcloud-go client for the fake that polls without delay.
func (a *API) Client() *hcloud.Client {
	return hcloud.NewClient(a.ClientOptions()...)
}

// ClientOptions are the options Client uses.
func (a *API) ClientOptions() []hcloud.ClientOption {
	return []hcloud.ClientOption{
		hcloud.WithEndpoint(a.server.URL),
		hcloud.WithToken("test-token"),
		hcloud.WithPollOpts(hcloud.PollOpts{BackoffFunc: hcloud.ConstantBackoff(time.Millisecond)}),
		hcloud.WithRetryOpts(hcloud.RetryOpts{BackoffFunc: hcloud.ConstantBackoff(0), MaxRetries: 0}),
	}
}

// Handle registers h for "METHOD /path" (the path without the query string).
func (a *API) Handle(route string, h Handler) {
	a.mu.Lock()
	defer a.mu.Unlock()
	a.routes[route] = h
}

// JSON registers a route that always answers 200 with body.
func (a *API) JSON(route string, body any) {
	a.Handle(route, func(Request) (int, any) { return http.StatusOK, body })
}

// NotFound registers a route that answers with the API's not_found error.
func (a *API) NotFound(route string) {
	a.Handle(route, func(Request) (int, any) { return http.StatusNotFound, ErrorBody("not_found", "not found") })
}

// Requests returns the requests received so far, in order.
func (a *API) Requests() []Request {
	a.mu.Lock()
	defer a.mu.Unlock()
	return append([]Request(nil), a.requests...)
}

// Called reports how many requests matched route.
func (a *API) Called(route string) int {
	n := 0
	for _, r := range a.Requests() {
		if r.Method+" "+r.Path == route {
			n++
		}
	}
	return n
}

// Last returns the last request for route, failing the test if there was none.
func (a *API) Last(route string) Request {
	a.t.Helper()
	reqs := a.Requests()
	for i := len(reqs) - 1; i >= 0; i-- {
		if reqs[i].Method+" "+reqs[i].Path == route {
			return reqs[i]
		}
	}
	a.t.Fatalf("no request for %s; got %v", route, reqs)
	return Request{}
}

// ErrorBody is the API's error envelope.
func ErrorBody(code, message string) map[string]any {
	return map[string]any{"error": map[string]any{"code": code, "message": message}}
}

// Action is a finished action, so hcloud-go's waiters return without polling.
func Action(id int64, command string) map[string]any {
	return map[string]any{
		"id": id, "command": command, "status": "success", "progress": 100,
		"started": "2026-01-01T00:00:00Z", "finished": "2026-01-01T00:00:01Z",
		"resources": []any{}, "error": nil,
	}
}

func (a *API) serve(w http.ResponseWriter, r *http.Request) {
	req := Request{Method: r.Method, Path: r.URL.Path, Query: r.URL.Query(), Header: r.Header.Clone()}
	if raw, _ := io.ReadAll(r.Body); len(raw) > 0 {
		_ = json.Unmarshal(raw, &req.Body)
	}

	a.mu.Lock()
	a.requests = append(a.requests, req)
	h, ok := a.routes[r.Method+" "+r.URL.Path]
	a.mu.Unlock()

	status, body := http.StatusNotFound, any(ErrorBody("not_found", "no route"))
	if ok {
		status, body = h(req)
	} else {
		a.t.Errorf("hcloudtest: unexpected request %s %s?%s", r.Method, r.URL.Path, r.URL.RawQuery)
	}
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(body)
}
