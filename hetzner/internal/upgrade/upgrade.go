// SPDX-License-Identifier: MIT

// Package upgrade holds the parts of `aiquila-hetzner upgrade` that need no
// server: recognising a deployed stack, migrating its .env and compose file
// to what this CLI version ships, and pinning the MCP image.
package upgrade

import (
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"regexp"
	"sort"
	"strings"

	templates "github.com/elgorro/aiquila/hetzner/docker"
)

// Stack is the kind of deployment found under /opt/aiquila.
type Stack string

const (
	StackMCP       Stack = "mcp"
	StackNextcloud Stack = "nextcloud"
	StackFull      Stack = "full"
)

// HasMCP and HasNextcloud say which components the stack runs.
func (s Stack) HasMCP() bool       { return s == StackMCP || s == StackFull }
func (s Stack) HasNextcloud() bool { return s == StackNextcloud || s == StackFull }

// DetectStack recognises a deployed docker-compose.yml by its container names,
// which every stack the CLI provisions has used.
func DetectStack(compose string) (Stack, error) {
	mcp := strings.Contains(compose, "container_name: aiq-mcp")
	nc := strings.Contains(compose, "container_name: aiq-nc\n") || strings.Contains(compose, "container_name: aiq-nc\r\n")
	switch {
	case mcp && nc:
		return StackFull, nil
	case mcp:
		return StackMCP, nil
	case nc:
		return StackNextcloud, nil
	}
	return "", fmt.Errorf("no aiq-mcp or aiq-nc service in docker-compose.yml — not a stack provisioned by aiquila-hetzner")
}

// Template is the compose file this CLI version ships for the stack.
func Template(s Stack) string {
	switch s {
	case StackMCP:
		return templates.MCPDockerCompose
	case StackNextcloud:
		return templates.NCDockerCompose
	default:
		return templates.FullDockerCompose
	}
}

const mcpImage = "ghcr.io/elgorro/aiquila-mcp"

var mcpImageRe = regexp.MustCompile(`(?m)^(\s*image:\s*)` + regexp.QuoteMeta(mcpImage) + `:\S+`)

// RenderCompose returns the stack's compose template with the MCP image pinned
// to tag. Pinning makes the deployed file say which release runs, and a
// rollback a matter of restoring the previous file.
func RenderCompose(s Stack, tag string) string {
	return mcpImageRe.ReplaceAllString(Template(s), "${1}"+mcpImage+":"+tag)
}

// MCPImageTag is the tag of the MCP image a compose file names, or "".
func MCPImageTag(compose string) string {
	m := regexp.MustCompile(regexp.QuoteMeta(mcpImage) + `:(\S+)`).FindStringSubmatch(compose)
	if m == nil {
		return ""
	}
	return m[1]
}

var requiredRe = regexp.MustCompile(`\$\{([A-Z0-9_]+):\?`)

// RequiredVars lists the variables a compose file guards with `${VAR:?…}`:
// Compose refuses to start any service while one of them is unset or empty.
func RequiredVars(compose string) []string {
	seen := map[string]bool{}
	for _, m := range requiredRe.FindAllStringSubmatch(compose, -1) {
		seen[m[1]] = true
	}
	out := make([]string, 0, len(seen))
	for v := range seen {
		out = append(out, v)
	}
	sort.Strings(out)
	return out
}

// Step is one release's configuration change. Steps only add what is missing
// and never overwrite a value, so they are safe to apply to a deployment that
// already has them — which is what makes skipping releases (0.4.17 → 0.5.0)
// and running upgrade twice both work.
type Step struct {
	Version string
	Stacks  []Stack
	Key     string
	Comment string
	// Generate returns the value for a deployment that lacks Key.
	Generate func() (string, error)
}

// Steps are the configuration changes of every release, oldest first.
var Steps = []Step{
	{
		Version: "0.5.0",
		Stacks:  []Stack{StackMCP, StackFull},
		Key:     "MCP_CREDENTIAL_KEY",
		Comment: "Encrypts the Nextcloud app passwords of signed-in users at rest. Keep it stable:\n# a new key signs everyone out.",
		// 512 bits of key material (HKDF-SHA-512 → AES-256-GCM).
		Generate: func() (string, error) { return randomHex(64) },
	},
}

// Migration is what upgrading a deployment's .env involves.
type Migration struct {
	// Env is the migrated .env content; equal to the input when nothing changed.
	Env string
	// Added names the keys that were added, in order. Values are never reported.
	Added []string
}

// MigrateEnv applies every step for the stack to env, then checks that the
// target compose file finds each variable it requires. A required variable no
// step can generate is an error: compose would refuse to start, so upgrade
// must stop before changing anything.
func MigrateEnv(s Stack, env, targetCompose string) (Migration, error) {
	f := ParseEnv(env)
	var added []string
	for _, st := range Steps {
		if !st.appliesTo(s) || f.Get(st.Key) != "" {
			continue
		}
		v, err := st.Generate()
		if err != nil {
			return Migration{}, fmt.Errorf("generate %s: %w", st.Key, err)
		}
		f.Set(st.Key, v, fmt.Sprintf("Added by aiquila-hetzner upgrade (%s). %s", st.Version, st.Comment))
		added = append(added, st.Key)
	}
	var missing []string
	for _, k := range RequiredVars(targetCompose) {
		if f.Get(k) == "" {
			missing = append(missing, k)
		}
	}
	if len(missing) > 0 {
		return Migration{}, fmt.Errorf("the .env lacks %s, which the new compose file requires and upgrade cannot generate — add it to /opt/aiquila/.env and run upgrade again", strings.Join(missing, ", "))
	}
	return Migration{Env: f.String(), Added: added}, nil
}

func (st Step) appliesTo(s Stack) bool {
	for _, x := range st.Stacks {
		if x == s {
			return true
		}
	}
	return false
}

func randomHex(n int) (string, error) {
	b := make([]byte, n)
	if _, err := rand.Read(b); err != nil {
		return "", err
	}
	return hex.EncodeToString(b), nil
}

// TargetTag is the MCP image tag and app version to upgrade to: the requested
// version, else this CLI's own version when it is a release (the CLI and the
// components share version numbers), else "latest".
func TargetTag(requested, cliVersion string) (string, error) {
	v := strings.TrimPrefix(requested, "v")
	if v == "" {
		v = strings.TrimPrefix(cliVersion, "v")
		if !semverRe.MatchString(v) {
			return "latest", nil
		}
	}
	if !semverRe.MatchString(v) {
		return "", fmt.Errorf("--version %q is not a release version like 0.5.0", requested)
	}
	return v, nil
}

var semverRe = regexp.MustCompile(`^\d+\.\d+\.\d+$`)
