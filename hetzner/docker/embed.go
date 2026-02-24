// Package templates embeds the production docker/standalone template files.
// These are read at build time and bundled into the aiquila-hetzner binary,
// keeping hetzner/docker/standalone/ as the single source of truth for both
// manual deployments and the CLI provisioner.
package templates

import _ "embed"

// DockerCompose is the docker-compose.yml template for Hetzner production.
//
//go:embed standalone/docker-compose.yml
var DockerCompose string

// Traefik is the traefik.yml static configuration template.
//
//go:embed standalone/traefik.yml
var Traefik string

// CrowdSecAcquis is the CrowdSec acquisition configuration template.
//
//go:embed standalone/crowdsec/acquis.yml
var CrowdSecAcquis string

// Prometheus is the Prometheus scrape configuration template.
//
//go:embed standalone/monitoring/prometheus.yml
var Prometheus string
