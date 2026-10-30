// SPDX-License-Identifier: MIT

package main

import (
	"bufio"
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	hcloudclient "github.com/elgorro/aiquila/hetzner/internal/hcloud"
	"github.com/elgorro/aiquila/hetzner/internal/provision"
	"github.com/elgorro/aiquila/hetzner/internal/upgrade"
	"github.com/spf13/cobra"
	xssh "golang.org/x/crypto/ssh"
)

var (
	upgradeName        string
	upgradeToken       string
	upgradeSSHKey      string
	upgradeVersion     string
	upgradeKeepBackups int
	upgradeDryRun      bool
	upgradeNoConfirm   bool
)

const (
	stackDir = "/opt/aiquila"
	// Free space the backup and the new images need, per filesystem.
	minFreeKB = 2 * 1024 * 1024
)

func buildUpgradeCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "upgrade",
		Short: "Upgrade the MCP server and the AIquila app of an existing deployment",
		Long: `Bring a deployment to a new AIquila release without editing files by hand.

Over SSH it checks the server, backs up the database, the Nextcloud config and
app, the MCP state and the .env / docker-compose.yml, adds the configuration
the release needs (keeping every existing value), then upgrades only the MCP
server and the AIquila app. Nextcloud core, PostgreSQL, Redis and the proxy
containers are left running as they are.

Running it again when the deployment is current changes nothing.`,
		RunE: runUpgrade,
	}
	cmd.Flags().StringVar(&upgradeName, "name", "", "Server name (required)")
	cmd.Flags().StringVar(&upgradeVersion, "version", "", "Release to upgrade to, e.g. 0.5.0 (default: this CLI's version)")
	cmd.Flags().IntVar(&upgradeKeepBackups, "keep-backups", 5, "Number of backups to keep in /opt/aiquila/backups")
	cmd.Flags().BoolVar(&upgradeDryRun, "dry-run", false, "Check the server and show the planned changes without making any")
	cmd.Flags().BoolVar(&upgradeNoConfirm, "noconfirm", false, "Do not ask before applying the plan")
	cmd.Flags().StringVar(&upgradeToken, "token", "", "Hetzner API token (default: $HCLOUD_TOKEN)")
	cmd.Flags().StringVar(&upgradeSSHKey, "ssh-key", "", "SSH private key path (default: ~/.ssh/aiquila_ed25519)")
	_ = cmd.MarkFlagRequired("name")
	return cmd
}

// remote runs commands in the stack directory of one server.
type remote struct {
	ssh      *xssh.Client
	profiles string // "--profile monitoring" when that profile is in use
}

func (r *remote) run(cmd string) (string, error) {
	return provision.RunCommand(r.ssh, "cd "+stackDir+" && "+cmd)
}

// compose runs docker compose with every profile the deployment uses, so that
// no running service is mistaken for an orphan.
func (r *remote) compose(args string) (string, error) {
	return r.run(strings.TrimSpace("docker compose "+r.profiles) + " " + args)
}

func (r *remote) occ(args string) (string, error) {
	return r.compose("exec -T -u www-data nc php occ " + args)
}

// upgradePlan is what an upgrade would change, worked out before anything is.
type upgradePlan struct {
	stack       upgrade.Stack
	tag         string
	profiles    string
	oldEnv      string
	newEnv      string
	addedKeys   []string
	oldCompose  string
	newCompose  string
	appFrom     string // installed AIquila app version, "" without Nextcloud
	appTo       string // "" when the app stays
	mcpFrom     string // running MCP version
	mcpImageID  string // image the MCP container runs now
	mcpPulledID string // image the target tag resolves to after pulling
}

func (p *upgradePlan) configChanged() bool {
	return p.newEnv != p.oldEnv || p.newCompose != p.oldCompose
}

func (p *upgradePlan) mcpChanged() bool {
	return p.stack.HasMCP() && (p.mcpPulledID == "" || p.mcpPulledID != p.mcpImageID || p.newCompose != p.oldCompose || p.newEnv != p.oldEnv)
}

func (p *upgradePlan) empty() bool {
	return !p.configChanged() && !p.mcpChanged() && p.appTo == ""
}

func runUpgrade(_ *cobra.Command, _ []string) error {
	tag, err := upgrade.TargetTag(upgradeVersion, version)
	if err != nil {
		return err
	}
	if upgradeKeepBackups < 1 {
		return fmt.Errorf("--keep-backups must be at least 1")
	}
	if upgradeSSHKey == "" {
		home, err := os.UserHomeDir()
		if err != nil {
			return fmt.Errorf("home dir: %w", err)
		}
		upgradeSSHKey = filepath.Join(home, ".ssh", "aiquila_ed25519")
	}

	client, err := hcloudclient.NewClient(upgradeToken, globalProfile)
	if err != nil {
		return err
	}
	srv, err := lookupServer(context.Background(), client, upgradeName)
	if err != nil {
		return err
	}
	serverIP := srv.PublicNet.IPv4.IP.String()
	fmt.Printf("==> Upgrading %q (%s) to %s\n", srv.Name, serverIP, tag)
	appLog.Info("upgrade", "starting", "server", srv.Name, "target", tag)

	sshClient, err := provision.WaitAndDial(serverIP, upgradeSSHKey)
	if err != nil {
		return err
	}
	defer sshClient.Close()
	r := &remote{ssh: sshClient}

	// ── 1. Preflight ──────────────────────────────────────────────────────
	fmt.Println("\n── Preflight")
	plan, err := preflight(r, tag)
	if err != nil {
		return err
	}

	// ── 2. Plan ───────────────────────────────────────────────────────────
	fmt.Println("\n── Plan")
	if err := planChanges(r, plan); err != nil {
		return err
	}
	printPlan(plan)
	if plan.empty() {
		fmt.Println("\n  Already up to date — nothing to do.")
		return nil
	}
	if upgradeDryRun {
		fmt.Println("\n  Dry run — no changes made.")
		return nil
	}
	if !upgradeNoConfirm && !confirm("Apply this plan?") {
		return fmt.Errorf("aborted")
	}

	// ── 3. Back up ────────────────────────────────────────────────────────
	fmt.Println("\n── Backing up")
	backupDir, err := backup(r, plan)
	if err != nil {
		return err
	}
	appLog.Info("upgrade", "backup taken", "dir", backupDir)

	// ── 4. Migrate the configuration ──────────────────────────────────────
	if plan.configChanged() {
		fmt.Println("\n── Writing the new configuration")
		if err := writeConfig(sshClient, plan); err != nil {
			return withRollback(err, plan, backupDir)
		}
	}

	// ── 5. Update ─────────────────────────────────────────────────────────
	if plan.appTo != "" {
		fmt.Printf("\n── Updating the AIquila app %s → %s\n", plan.appFrom, plan.appTo)
		if err := updateApp(r, plan.appTo); err != nil {
			return withRollback(err, plan, backupDir)
		}
	}
	if plan.mcpChanged() {
		fmt.Println("\n── Updating the MCP server")
		if out, err := r.compose("up -d mcp 2>&1"); err != nil {
			fmt.Fprintln(os.Stderr, out)
			return withRollback(fmt.Errorf("start the new MCP container: %w", err), plan, backupDir)
		}
	}

	// ── 6. Verify ─────────────────────────────────────────────────────────
	fmt.Println("\n── Verifying")
	if err := verify(r, plan); err != nil {
		return withRollback(err, plan, backupDir)
	}

	fmt.Printf("\n  Upgrade of %q to %s complete. Backup: %s\n", srv.Name, tag, backupDir)
	printRollback(plan, backupDir)
	appLog.Info("upgrade", "complete", "server", srv.Name, "target", tag)
	return nil
}

// preflight reads the deployment and refuses to go on when upgrading it is
// unsafe: Nextcloud in maintenance mode or with a pending upgrade, too little
// disk, or a stray directory in custom_apps that would break `occ upgrade`.
func preflight(r *remote, tag string) (*upgradePlan, error) {
	compose, err := r.run("cat docker-compose.yml")
	if err != nil {
		return nil, fmt.Errorf("read %s/docker-compose.yml: %w", stackDir, err)
	}
	env, err := r.run("cat .env")
	if err != nil {
		return nil, fmt.Errorf("read %s/.env: %w", stackDir, err)
	}
	stack, err := upgrade.DetectStack(compose)
	if err != nil {
		return nil, err
	}
	p := &upgradePlan{stack: stack, tag: tag, oldEnv: env, oldCompose: compose}
	fmt.Printf("  Stack: %s\n", stack)

	if out, _ := r.run(`docker ps --format '{{.Names}}'`); strings.Contains(out, "aiq-prometheus") || strings.Contains(out, "aiq-grafana") {
		r.profiles = "--profile monitoring"
		p.profiles = r.profiles
		fmt.Println("  Monitoring profile in use — every compose call includes it.")
	}

	out, err := r.run(`df -Pk ` + stackDir + ` /var/lib/docker | awk 'NR>1 {print $6, $4}'`)
	if err != nil {
		return nil, fmt.Errorf("check free disk space: %w", err)
	}
	for _, line := range strings.Split(strings.TrimSpace(out), "\n") {
		mount, kb, _ := strings.Cut(line, " ")
		if n, err := strconv.Atoi(strings.TrimSpace(kb)); err == nil && n < minFreeKB {
			return nil, fmt.Errorf("only %d MiB free on %s; the backup and new images need %d MiB", n/1024, mount, minFreeKB/1024)
		}
	}
	fmt.Println("  Disk space: ok")

	if stack.HasMCP() {
		v, err := r.compose(`exec -T mcp node -p "require('/app/package.json').version"`)
		if err != nil {
			fmt.Println("  MCP server: not running — it will be started")
		} else {
			p.mcpFrom = strings.TrimSpace(v)
			fmt.Printf("  MCP server: %s\n", p.mcpFrom)
		}
		p.mcpImageID = strings.TrimSpace(mustOut(r.run(`docker inspect -f '{{.Image}}' aiq-mcp 2>/dev/null`)))
	}

	if stack.HasNextcloud() {
		st, err := ncStatus(r)
		if err != nil {
			return nil, err
		}
		if st.Maintenance {
			return nil, fmt.Errorf("Nextcloud is in maintenance mode — finish or undo what put it there first (occ maintenance:mode --off)")
		}
		if st.NeedsDBUpgrade {
			return nil, fmt.Errorf("Nextcloud has a pending upgrade — run `occ upgrade` first, so this upgrade does not mix with it")
		}
		fmt.Printf("  Nextcloud: %s, not in maintenance, no pending upgrade\n", st.VersionString)

		stray, err := r.compose(`exec -T nc sh -c 'cd /var/www/html/custom_apps && for d in */; do [ -f "$d/appinfo/info.xml" ] || echo "${d%/}"; done'`)
		if err != nil {
			return nil, fmt.Errorf("check custom_apps: %w", err)
		}
		if s := strings.TrimSpace(stray); s != "" {
			return nil, fmt.Errorf("custom_apps holds directories that are not apps (%s) — move them out of the webroot; `occ upgrade` fails on them", strings.ReplaceAll(s, "\n", ", "))
		}
		fmt.Println("  custom_apps: only valid apps")

		p.appFrom, err = appVersion(r)
		if err != nil {
			return nil, err
		}
		fmt.Printf("  AIquila app: %s\n", p.appFrom)
	}
	return p, nil
}

// planChanges works out the new configuration and which components move.
func planChanges(r *remote, p *upgradePlan) error {
	p.newCompose = upgrade.RenderCompose(p.stack, p.tag)
	m, err := upgrade.MigrateEnv(p.stack, p.oldEnv, p.newCompose)
	if err != nil {
		return err
	}
	p.newEnv, p.addedKeys = m.Env, m.Added

	if p.stack.HasNextcloud() && p.tag != "latest" && p.appFrom != p.tag {
		p.appTo = p.tag
	}
	if p.stack.HasMCP() {
		image := "ghcr.io/elgorro/aiquila-mcp:" + p.tag
		if upgradeDryRun {
			id, _ := r.run(fmt.Sprintf("docker image inspect -f '{{.Id}}' %s 2>/dev/null", image))
			p.mcpPulledID = strings.TrimSpace(id)
		} else {
			// Pulling only fills the local image cache; no container changes yet.
			if out, err := r.run("docker pull -q " + image + " 2>&1"); err != nil {
				fmt.Fprintln(os.Stderr, out)
				return fmt.Errorf("pull %s: %w", image, err)
			}
			id, err := r.run(fmt.Sprintf("docker image inspect -f '{{.Id}}' %s", image))
			if err != nil {
				return fmt.Errorf("inspect %s: %w", image, err)
			}
			p.mcpPulledID = strings.TrimSpace(id)
		}
	}
	return nil
}

func printPlan(p *upgradePlan) {
	if len(p.addedKeys) > 0 {
		fmt.Printf("  .env: add %s (generated; values not shown)\n", strings.Join(p.addedKeys, ", "))
	} else {
		fmt.Println("  .env: no change")
	}
	if p.newCompose != p.oldCompose {
		fmt.Printf("  docker-compose.yml: replace with the %s template", p.stack)
		if from, to := upgrade.MCPImageTag(p.oldCompose), upgrade.MCPImageTag(p.newCompose); from != to && to != "" {
			fmt.Printf(" (MCP image %s → %s)", orNone(from), to)
		}
		fmt.Println()
	} else {
		fmt.Println("  docker-compose.yml: no change")
	}
	if p.stack.HasMCP() {
		switch {
		case !p.mcpChanged():
			fmt.Printf("  MCP server: stays on %s\n", orNone(p.mcpFrom))
		case p.mcpPulledID == "":
			fmt.Printf("  MCP server: %s → %s (image pulled when applying)\n", orNone(p.mcpFrom), p.tag)
		default:
			fmt.Printf("  MCP server: %s → %s (only the mcp container is recreated)\n", orNone(p.mcpFrom), p.tag)
		}
	}
	if p.stack.HasNextcloud() {
		if p.appTo != "" {
			fmt.Printf("  AIquila app: %s → %s, then occ upgrade\n", p.appFrom, p.appTo)
		} else {
			fmt.Printf("  AIquila app: stays on %s\n", p.appFrom)
		}
		fmt.Println("  Nextcloud core, PostgreSQL, Redis: not touched")
	}
	if p.newCompose != p.oldCompose {
		fmt.Println("  Compose changes to services other than mcp take effect when you next recreate them.")
	}
}

// backup writes a timestamped directory under /opt/aiquila/backups and keeps
// the newest --keep-backups of them.
func backup(r *remote, p *upgradePlan) (string, error) {
	dir := stackDir + "/backups/" + time.Now().UTC().Format("20060102T150405Z")
	steps := []struct{ what, cmd string }{
		{"directory", fmt.Sprintf("install -d -m 700 %s", dir)},
		{".env and docker-compose.yml", fmt.Sprintf("cp -p .env docker-compose.yml %s/", dir)},
	}
	if p.stack.HasNextcloud() {
		steps = append(steps,
			struct{ what, cmd string }{"PostgreSQL (pg_dump)",
				fmt.Sprintf("docker compose exec -T nc-db pg_dump -U nextcloud -d nextcloud -Fc > %s/nextcloud-db.dump", dir)},
			struct{ what, cmd string }{"Nextcloud config and AIquila app",
				fmt.Sprintf("docker compose exec -T nc tar -C /var/www/html -czf - config custom_apps/aiquila > %s/nextcloud-config-app.tar.gz", dir)},
		)
	}
	if p.stack.HasMCP() {
		steps = append(steps,
			struct{ what, cmd string }{"MCP state",
				fmt.Sprintf("docker compose exec -T mcp tar -C /app/state -czf - . > %s/mcp-state.tar.gz", dir)},
			struct{ what, cmd string }{"MCP image id",
				fmt.Sprintf("echo %s > %s/mcp-image-id", shellQuote(p.mcpImageID), dir)},
		)
	}
	for _, s := range steps {
		if s.what == "MCP state" && p.mcpFrom == "" {
			// The container is not running; its volume still exists, read it directly.
			s.cmd = fmt.Sprintf("docker run --rm -v aiquila_mcp_state:/s:ro alpine tar -C /s -czf - . > %s/mcp-state.tar.gz", dir)
		}
		if out, err := r.run(s.cmd); err != nil {
			fmt.Fprintln(os.Stderr, out)
			return "", fmt.Errorf("back up %s: %w", s.what, err)
		}
		fmt.Printf("  %s\n", s.what)
	}
	if _, err := r.run(fmt.Sprintf("chmod 600 %s/*", dir)); err != nil {
		return "", fmt.Errorf("secure backup: %w", err)
	}
	prune := fmt.Sprintf("ls -1d backups/*/ | sort | head -n -%d | xargs -r rm -rf", upgradeKeepBackups)
	if _, err := r.run(prune); err != nil {
		fmt.Fprintf(os.Stderr, "  Warning: could not prune old backups: %v\n", err)
	}
	fmt.Printf("  Saved to %s (keeping the newest %d)\n", dir, upgradeKeepBackups)
	return dir, nil
}

func writeConfig(sshClient *xssh.Client, p *upgradePlan) error {
	up, err := provision.NewUploader(sshClient)
	if err != nil {
		return err
	}
	defer up.Close()
	if p.newEnv != p.oldEnv {
		if err := up.WriteFile(stackDir+"/.env", p.newEnv); err != nil {
			return fmt.Errorf("write .env: %w", err)
		}
		if err := up.Chmod(stackDir+"/.env", 0o600); err != nil {
			return fmt.Errorf("secure .env: %w", err)
		}
		fmt.Printf("  .env: added %s\n", strings.Join(p.addedKeys, ", "))
	}
	if p.newCompose != p.oldCompose {
		if err := up.WriteFile(stackDir+"/docker-compose.yml", p.newCompose); err != nil {
			return fmt.Errorf("write docker-compose.yml: %w", err)
		}
		fmt.Println("  docker-compose.yml: replaced")
	}
	return nil
}

// updateApp swaps the AIquila app for the release's package and runs occ
// upgrade. The old copy is moved outside the webroot, and put back when the
// upgrade fails.
func updateApp(r *remote, ver string) error {
	url := fmt.Sprintf("https://github.com/elgorro/aiquila/releases/download/nc-v%s/aiquila.tar.gz", ver)
	script := `set -e
cd /var/www/html
curl -fsSL -o /tmp/aiquila.tar.gz ` + shellQuote(url) + `
tar -tzf /tmp/aiquila.tar.gz | grep -q '^aiquila/appinfo/info.xml$'
rm -rf /var/www/aiquila.rollback
mv custom_apps/aiquila /var/www/aiquila.rollback
tar -xzf /tmp/aiquila.tar.gz -C custom_apps
chown -R www-data:www-data custom_apps/aiquila
rm -f /tmp/aiquila.tar.gz`
	if out, err := r.compose("exec -T nc bash -c " + shellQuote(script) + " 2>&1"); err != nil {
		fmt.Fprintln(os.Stderr, out)
		restoreApp(r)
		return fmt.Errorf("install the AIquila %s package: %w", ver, err)
	}
	fmt.Println("  Package installed; old copy at /var/www/aiquila.rollback (outside the webroot)")
	out, err := r.occ("upgrade 2>&1")
	fmt.Print(indent(out))
	if err != nil {
		restoreApp(r)
		return fmt.Errorf("occ upgrade: %w", err)
	}
	if out, err := r.occ("app:enable aiquila 2>&1"); err != nil {
		fmt.Fprintln(os.Stderr, out)
		return fmt.Errorf("enable the AIquila app: %w", err)
	}
	return nil
}

func restoreApp(r *remote) {
	fmt.Fprintln(os.Stderr, "  Putting the previous AIquila app back")
	_, err := r.compose(`exec -T nc bash -c 'test -d /var/www/aiquila.rollback && rm -rf /var/www/html/custom_apps/aiquila && mv /var/www/aiquila.rollback /var/www/html/custom_apps/aiquila'`)
	if err == nil {
		_, err = r.occ("maintenance:mode --off")
	}
	if err != nil {
		fmt.Fprintf(os.Stderr, "  Warning: restoring the previous app failed: %v\n", err)
	}
}

// verify waits for the MCP healthcheck and checks versions, then runs a short
// MCP smoke test against the server from inside its container.
func verify(r *remote, p *upgradePlan) error {
	if p.stack.HasMCP() {
		if err := waitHealthy(r, "aiq-mcp", 3*time.Minute); err != nil {
			return err
		}
		v, err := r.compose(`exec -T mcp node -p "require('/app/package.json').version"`)
		if err != nil {
			return fmt.Errorf("read the MCP version: %w", err)
		}
		v = strings.TrimSpace(v)
		if p.tag != "latest" && v != p.tag {
			return fmt.Errorf("the MCP server reports %s, expected %s", v, p.tag)
		}
		fmt.Printf("  MCP server: %s, healthy\n", v)
		out, err := r.compose("exec -T mcp node -e " + shellQuote(mcpSmokeTest))
		if err != nil {
			return fmt.Errorf("MCP smoke test: %w", err)
		}
		fmt.Print(indent(out))
	}
	if p.stack.HasNextcloud() {
		st, err := ncStatus(r)
		if err != nil {
			return err
		}
		if st.Maintenance || st.NeedsDBUpgrade {
			return fmt.Errorf("Nextcloud is left in maintenance mode or with a pending upgrade")
		}
		app, err := appVersion(r)
		if err != nil {
			return err
		}
		if p.appTo != "" && app != p.appTo {
			return fmt.Errorf("the AIquila app reports %s, expected %s", app, p.appTo)
		}
		fmt.Printf("  Nextcloud: no pending upgrade; AIquila app %s\n", app)
	}
	return nil
}

// mcpSmokeTest asks the server what it supports (2026-07-28 server/discover)
// and for its tools. Both are served without a token unless the operator set
// MCP_LAZY_AUTH=false, in which case a 401 is the expected answer.
const mcpSmokeTest = `
const meta = { 'io.modelcontextprotocol/protocolVersion': '2026-07-28',
  'io.modelcontextprotocol/clientInfo': { name: 'aiquila-hetzner', version: 'upgrade' },
  'io.modelcontextprotocol/clientCapabilities': {} };
const call = async (method) => {
  const r = await fetch('http://localhost:3339/mcp', { method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream',
      'MCP-Protocol-Version': '2026-07-28', 'Mcp-Method': method },
    body: JSON.stringify({ jsonrpc: '2.0', id: 1, method, params: { _meta: meta } }) });
  if (r.status === 401) return null;
  const raw = (await r.text()).trim();
  const line = raw.startsWith('{') ? raw : raw.split('\n').filter(l => l.startsWith('data:')).pop().slice(5);
  const body = JSON.parse(line);
  if (body.error) throw new Error(method + ': ' + body.error.message);
  return body.result;
};
(async () => {
  const d = await call('server/discover');
  if (d === null) { console.log('MCP_LAZY_AUTH=false: /mcp requires a token; health check only'); return; }
  const t = await call('tools/list');
  console.log('MCP: protocol ' + d.supportedVersions.join(', ') + '; ' + t.tools.length + ' tools');
})().catch(e => { console.error(e.message); process.exit(1); });
`

func waitHealthy(r *remote, container string, timeout time.Duration) error {
	deadline := time.Now().Add(timeout)
	for {
		out, _ := r.run(fmt.Sprintf("docker inspect -f '{{.State.Health.Status}}' %s", container))
		switch strings.TrimSpace(out) {
		case "healthy":
			return nil
		case "unhealthy":
			return fmt.Errorf("%s is unhealthy — see `docker logs %s`", container, container)
		}
		if time.Now().After(deadline) {
			return fmt.Errorf("%s did not become healthy within %s", container, timeout)
		}
		time.Sleep(5 * time.Second)
	}
}

type ncStatusInfo struct {
	Maintenance    bool   `json:"maintenance"`
	NeedsDBUpgrade bool   `json:"needsDbUpgrade"`
	VersionString  string `json:"versionstring"`
}

func ncStatus(r *remote) (ncStatusInfo, error) {
	var st ncStatusInfo
	out, err := r.occ("status --output=json")
	if err != nil {
		return st, fmt.Errorf("occ status: %w", err)
	}
	if err := json.Unmarshal([]byte(lastJSONLine(out)), &st); err != nil {
		return st, fmt.Errorf("parse occ status: %w", err)
	}
	return st, nil
}

func appVersion(r *remote) (string, error) {
	out, err := r.occ("app:list --output=json")
	if err != nil {
		return "", fmt.Errorf("occ app:list: %w", err)
	}
	var apps struct {
		Enabled  map[string]string `json:"enabled"`
		Disabled map[string]string `json:"disabled"`
	}
	if err := json.Unmarshal([]byte(lastJSONLine(out)), &apps); err != nil {
		return "", fmt.Errorf("parse occ app:list: %w", err)
	}
	if v, ok := apps.Enabled["aiquila"]; ok {
		return v, nil
	}
	if v, ok := apps.Disabled["aiquila"]; ok {
		return v, nil
	}
	return "", fmt.Errorf("the AIquila app is not installed in this Nextcloud")
}

// lastJSONLine skips warnings occ prints before its JSON.
func lastJSONLine(out string) string {
	lines := strings.Split(strings.TrimSpace(out), "\n")
	for i := len(lines) - 1; i >= 0; i-- {
		if strings.HasPrefix(strings.TrimSpace(lines[i]), "{") {
			return lines[i]
		}
	}
	return out
}

func withRollback(err error, p *upgradePlan, backupDir string) error {
	fmt.Fprintf(os.Stderr, "\nUpgrade failed: %v\n", err)
	printRollback(p, backupDir)
	return err
}

func printRollback(p *upgradePlan, dir string) {
	fmt.Printf("\nTo roll back, on the server:\n  cd %s\n", stackDir)
	fmt.Printf("  cp %s/.env %s/docker-compose.yml .\n", dir, dir)
	if p.stack.HasMCP() && p.mcpImageID != "" {
		if from := upgrade.MCPImageTag(p.oldCompose); from != "" {
			fmt.Printf("  docker tag %s ghcr.io/elgorro/aiquila-mcp:%s\n", p.mcpImageID, from)
		}
		fmt.Printf("  docker compose %sup -d mcp\n", profileArg(p))
	}
	if p.stack.HasNextcloud() && p.appTo != "" {
		fmt.Println("  docker compose exec -T nc rm -rf /var/www/html/custom_apps/aiquila")
		fmt.Printf("  docker compose exec -T nc tar -C /var/www/html -xzf - custom_apps/aiquila < %s/nextcloud-config-app.tar.gz\n", dir)
		fmt.Println("  # if the app's migrations already ran, restore the database too:")
		fmt.Printf("  docker compose exec -T nc-db pg_restore -U nextcloud -d nextcloud --clean --if-exists < %s/nextcloud-db.dump\n", dir)
	}
}

func profileArg(p *upgradePlan) string {
	if p.profiles == "" {
		return ""
	}
	return p.profiles + " "
}

func confirm(q string) bool {
	fmt.Printf("\n%s [y/N] ", q)
	line, _ := bufio.NewReader(os.Stdin).ReadString('\n')
	return strings.EqualFold(strings.TrimSpace(line), "y") || strings.EqualFold(strings.TrimSpace(line), "yes")
}

func orNone(s string) string {
	if s == "" {
		return "(none)"
	}
	return s
}

func indent(s string) string {
	s = strings.TrimRight(s, "\n")
	if s == "" {
		return ""
	}
	return "  " + strings.ReplaceAll(s, "\n", "\n  ") + "\n"
}

func mustOut(s string, _ error) string { return s }
