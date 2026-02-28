package main

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"

	templates "github.com/elgorro/aiquila/hetzner/docker"
	"github.com/elgorro/aiquila/hetzner/internal/config"
	hcloudclient "github.com/elgorro/aiquila/hetzner/internal/hcloud"
	profilepkg "github.com/elgorro/aiquila/hetzner/internal/profile"
	"github.com/elgorro/aiquila/hetzner/internal/provision"
	"github.com/spf13/cobra"
	"gopkg.in/yaml.v3"
)

// DeployConfig holds the deployment parameters that can be supplied via a
// YAML or JSON config file (keys match flag names, snake_case).
type DeployConfig struct {
	Name       string `yaml:"name"        json:"name"`
	Domain     string `yaml:"domain"      json:"domain"`
	NCURL      string `yaml:"nc_url"      json:"nc_url"`
	NCUser     string `yaml:"nc_user"     json:"nc_user"`
	NCPassword string `yaml:"nc_password" json:"nc_password"`
	AcmeEmail  string   `yaml:"acme_email"  json:"acme_email"`
	Monitoring bool     `yaml:"monitoring"  json:"monitoring"`
	SSHKey     string   `yaml:"ssh_key"     json:"ssh_key"`
	Token      string   `yaml:"token"       json:"token"`
	Packages   []string `yaml:"packages"    json:"packages"`
	// Infrastructure
	Stack      string `yaml:"stack"       json:"stack"`
	Image      string `yaml:"image"       json:"image"`
	Location   string `yaml:"location"    json:"location"`
	Type       string `yaml:"type"        json:"type"`
	Swap       string `yaml:"swap"        json:"swap"`
	VolumeSize int    `yaml:"volume_size" json:"volume_size"`
	LUKS       bool   `yaml:"luks"        json:"luks"`
	Network    string `yaml:"network"     json:"network"`
	Labels     []string `yaml:"labels"    json:"labels"`
	// DNS
	DNSZone  string `yaml:"dns_zone"  json:"dns_zone"`
	DNSToken string `yaml:"dns_token" json:"dns_token"`
	// SSH
	SSHAllowCIDR string `yaml:"ssh_allow_cidr" json:"ssh_allow_cidr"`
	// Nextcloud self-hosted (--stack nextcloud/full)
	NCDomain         string `yaml:"nc_domain"          json:"nc_domain"`
	NCAdminUser      string `yaml:"nc_admin_user"      json:"nc_admin_user"`
	NCAdminPassword  string `yaml:"nc_admin_password"  json:"nc_admin_password"`
	NCAppVersion     string `yaml:"nc_app_version"     json:"nc_app_version"`
}

var (
	rebuildName       string
	rebuildConfig     string
	rebuildDomain     string
	rebuildNCURL      string
	rebuildNCUser     string
	rebuildNCPassword string
	rebuildAcmeEmail  string
	rebuildMonitoring bool
	rebuildSSHKey     string
	rebuildToken      string
)

func buildRebuildCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "rebuild",
		Short: "Re-provision an existing AIquila server without destroy/create",
		Long: `rebuild re-uploads all configuration files and restarts the Docker stack
on an already-running server. It does not touch Hetzner infrastructure
(no server, firewall, volume, or network changes).

Credentials can be supplied via --config (YAML or JSON file), flags, or
environment variables. Priority order: explicit flag > config file > env var > profile.`,
		RunE: runRebuild,
	}

	cmd.Flags().StringVar(&rebuildName, "name", "", "Server name (required unless provided by --config)")
	cmd.Flags().StringVar(&rebuildConfig, "config", "", "Path to YAML or JSON deployment config file")
	cmd.Flags().StringVar(&rebuildDomain, "domain", "", "FQDN — overrides config file")
	cmd.Flags().StringVar(&rebuildNCURL, "nc-url", "", "Nextcloud URL — overrides config file")
	cmd.Flags().StringVar(&rebuildNCUser, "nc-user", "", "Nextcloud username — overrides config file")
	cmd.Flags().StringVar(&rebuildNCPassword, "nc-password", "", "Nextcloud app password — overrides config file")
	cmd.Flags().StringVar(&rebuildAcmeEmail, "acme-email", "", "ACME email — overrides config file")
	cmd.Flags().BoolVar(&rebuildMonitoring, "monitoring", false, "Enable monitoring profile (Prometheus + Grafana)")
	cmd.Flags().StringVar(&rebuildSSHKey, "ssh-key", "", "Path to private SSH key for server access")
	cmd.Flags().StringVar(&rebuildToken, "token", "", "Hetzner API token (default: $HCLOUD_TOKEN)")

	return cmd
}

func runRebuild(cmd *cobra.Command, _ []string) error {
	// ── 1. Load config file (if given) ────────────────────────────────────
	var fileCfg DeployConfig
	if rebuildConfig != "" {
		fc, err := loadDeployConfig(rebuildConfig)
		if err != nil {
			return err
		}
		fileCfg = *fc
	}

	// ── 2. Merge: explicit CLI flags override config-file values ──────────
	name := mergeString(rebuildName, fileCfg.Name)
	domain := mergeString(rebuildDomain, fileCfg.Domain)
	ncURL := mergeString(rebuildNCURL, fileCfg.NCURL)
	ncUser := mergeString(rebuildNCUser, fileCfg.NCUser)
	ncPassword := mergeString(rebuildNCPassword, fileCfg.NCPassword)
	acmeEmail := mergeString(rebuildAcmeEmail, fileCfg.AcmeEmail)
	sshKey := mergeString(rebuildSSHKey, fileCfg.SSHKey)
	token := mergeString(rebuildToken, fileCfg.Token)
	monitoring := fileCfg.Monitoring
	if cmd.Flags().Changed("monitoring") {
		monitoring = rebuildMonitoring
	}

	// ── 3. Env var fallbacks ───────────────────────────────────────────────
	if ncURL == "" {
		ncURL = os.Getenv("NEXTCLOUD_URL")
	}
	if ncUser == "" {
		ncUser = os.Getenv("NEXTCLOUD_USER")
	}
	if ncPassword == "" {
		ncPassword = os.Getenv("NEXTCLOUD_PASSWORD")
	}

	// ── 4. Profile fallback ────────────────────────────────────────────────
	{
		cfg, err := profilepkg.Load()
		if err == nil {
			if p, _, ok := cfg.Active(globalProfile); ok {
				if ncURL == "" {
					ncURL = p.NextcloudURL
				}
				if ncUser == "" {
					ncUser = p.NextcloudUser
				}
				if ncPassword == "" {
					ncPassword = p.NextcloudPassword
				}
				if acmeEmail == "" {
					acmeEmail = p.AcmeEmail
				}
			}
		}
	}

	// ── 5. Validate ────────────────────────────────────────────────────────
	var missing []string
	if name == "" {
		missing = append(missing, "--name / config.name")
	}
	if domain == "" {
		missing = append(missing, "--domain / config.domain")
	}
	if ncURL == "" {
		missing = append(missing, "--nc-url / $NEXTCLOUD_URL")
	}
	if ncUser == "" {
		missing = append(missing, "--nc-user / $NEXTCLOUD_USER")
	}
	if ncPassword == "" {
		missing = append(missing, "--nc-password / $NEXTCLOUD_PASSWORD")
	}
	if sshKey == "" {
		missing = append(missing, "--ssh-key / config.ssh_key")
	}
	if len(missing) > 0 {
		return fmt.Errorf("required values missing: %s", strings.Join(missing, ", "))
	}

	sshKey = expandHome(sshKey)

	fmt.Printf("==> aiquila-hetzner rebuild %q\n", name)
	appLog.Info("start", "rebuilding server", "name", name, "domain", domain)

	// ── 6. Init hcloud client + get server IP ─────────────────────────────
	fmt.Println("\n── Hetzner Cloud API")
	client, err := hcloudclient.NewClient(token, globalProfile)
	if err != nil {
		return err
	}

	ctx := context.Background()
	srv, err := lookupServer(ctx, client, name)
	if err != nil {
		return err
	}
	serverIP := srv.PublicNet.IPv4.IP.String()
	fmt.Printf("  Server %q found (id=%d, ip=%s)\n", name, srv.ID, serverIP)
	appLog.Info("server", "server found", "server", name, "ip", serverIP)

	// ── 7. SSH into server ────────────────────────────────────────────────
	fmt.Println("\n── Connecting via SSH")
	sshClient, err := provision.WaitAndDial(serverIP, sshKey)
	if err != nil {
		return err
	}
	defer sshClient.Close()
	appLog.Info("ssh", "SSH connected", "ip", serverIP)

	// ── 8. Generate .env ──────────────────────────────────────────────────
	fmt.Println("\n── Generating configuration")
	env, err := config.Generate(ncURL, ncUser, ncPassword, domain, acmeEmail)
	if err != nil {
		return err
	}

	// ── 9. Upload files via SFTP ──────────────────────────────────────────
	fmt.Println("\n── Uploading files to /opt/aiquila")
	uploader, err := provision.NewUploader(sshClient)
	if err != nil {
		return err
	}
	defer uploader.Close()

	uploads := []struct{ path, content string }{
		{"/opt/aiquila/docker-compose.yml", templates.MCPDockerCompose},
		{"/opt/aiquila/traefik.yml", templates.MCPTraefik},
		{"/opt/aiquila/crowdsec/acquis.yml", templates.MCPCrowdSecAcquis},
		{"/opt/aiquila/.env", env.Render()},
	}
	if monitoring {
		uploads = append(uploads, struct{ path, content string }{
			"/opt/aiquila/monitoring/prometheus.yml", templates.MCPPrometheus,
		})
	}

	for _, f := range uploads {
		if err := uploader.WriteFile(f.path, f.content); err != nil {
			return fmt.Errorf("upload %s: %w", f.path, err)
		}
		fmt.Printf("  Uploaded %s\n", f.path)
	}

	appLog.Info("upload", "files uploaded", "count", len(uploads))

	// ── 10. Pull + restart stack ───────────────────────────────────────────
	fmt.Println("\n── Restarting Docker services")
	composeCmd := "cd /opt/aiquila && docker compose pull && docker compose up -d --remove-orphans 2>&1"
	if monitoring {
		composeCmd = "cd /opt/aiquila && docker compose pull && docker compose --profile monitoring up -d --remove-orphans 2>&1"
	}
	out, err := provision.RunCommand(sshClient, composeCmd)
	if err != nil {
		fmt.Fprintln(os.Stderr, out)
		return fmt.Errorf("docker compose restart: %w", err)
	}
	fmt.Print(out)

	// ── 11. Summary ────────────────────────────────────────────────────────
	grafanaLine := ""
	if monitoring {
		grafanaLine = fmt.Sprintf("  Grafana:    https://%s/grafana\n", domain)
	}

	fmt.Printf(`
╔══════════════════════════════════════╗
║    AIquila rebuild complete          ║
╠══════════════════════════════════════╣
  Server:     %s (%s)
  AIquila:    https://%s
  MCP URL:    https://%s/mcp
%s╚══════════════════════════════════════╝
`,
		name, serverIP,
		domain,
		domain,
		grafanaLine,
	)
	return nil
}

// loadDeployConfig reads a YAML or JSON deployment config file.
// JSON is detected by a .json extension; everything else is parsed as YAML.
func loadDeployConfig(path string) (*DeployConfig, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("read config file %q: %w", path, err)
	}

	var cfg DeployConfig
	if strings.ToLower(filepath.Ext(path)) == ".json" {
		if err := json.Unmarshal(data, &cfg); err != nil {
			return nil, fmt.Errorf("parse JSON config %q: %w", path, err)
		}
	} else {
		if err := yaml.Unmarshal(data, &cfg); err != nil {
			return nil, fmt.Errorf("parse YAML config %q: %w", path, err)
		}
	}
	return &cfg, nil
}

// mergeString returns flagVal if non-empty, otherwise fileVal.
func mergeString(flagVal, fileVal string) string {
	if flagVal != "" {
		return flagVal
	}
	return fileVal
}

// expandHome expands a leading ~ to the user's home directory.
func expandHome(path string) string {
	if !strings.HasPrefix(path, "~") {
		return path
	}
	home, err := os.UserHomeDir()
	if err != nil {
		return path
	}
	return filepath.Join(home, path[1:])
}
