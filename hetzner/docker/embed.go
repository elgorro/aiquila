// Package templates embeds the production docker stack template files.
// These are read at build time and bundled into the aiquila-hetzner binary,
// keeping hetzner/docker/{mcp,nextcloud,full}/ as the single source of truth
// for both manual deployments and the CLI provisioner.
package templates

import _ "embed"

// ── MCP-only stack (hetzner/docker/mcp/) ─────────────────────────────────────

// MCPDockerCompose is the docker-compose.yml template for the MCP-only stack.
//
//go:embed mcp/docker-compose.yml
var MCPDockerCompose string

// MCPTraefik is the traefik.yml static configuration template for the MCP stack.
//
//go:embed mcp/traefik.yml
var MCPTraefik string

// MCPCrowdSecAcquis is the CrowdSec acquisition configuration for the MCP stack.
//
//go:embed mcp/crowdsec/acquis.yml
var MCPCrowdSecAcquis string

// MCPPrometheus is the Prometheus scrape configuration for the MCP stack.
//
//go:embed mcp/monitoring/prometheus.yml
var MCPPrometheus string

// ── Nextcloud-only stack (hetzner/docker/nextcloud/) ─────────────────────────

// NCDockerCompose is the docker-compose.yml template for the Nextcloud-only stack.
//
//go:embed nextcloud/docker-compose.yml
var NCDockerCompose string

// NCTraefik is the traefik.yml static configuration template for the NC stack.
//
//go:embed nextcloud/traefik.yml
var NCTraefik string

// NCCrowdSecAcquis is the CrowdSec acquisition configuration for the NC stack.
//
//go:embed nextcloud/crowdsec/acquis.yml
var NCCrowdSecAcquis string

// ── Full stack (hetzner/docker/full/) ────────────────────────────────────────

// FullDockerCompose is the docker-compose.yml template for the full (NC+MCP) stack.
//
//go:embed full/docker-compose.yml
var FullDockerCompose string

// FullTraefik is the traefik.yml static configuration template for the full stack.
//
//go:embed full/traefik.yml
var FullTraefik string

// FullCrowdSecAcquis is the CrowdSec acquisition configuration for the full stack.
//
//go:embed full/crowdsec/acquis.yml
var FullCrowdSecAcquis string

