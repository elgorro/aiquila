package main

import (
	"context"
	"fmt"
	"math/rand"
	"os"
	"strings"
	"time"

	templates "github.com/elgorro/aiquila/hetzner/docker"
	"github.com/elgorro/aiquila/hetzner/internal/config"
	"github.com/elgorro/aiquila/hetzner/internal/dns"
	"github.com/elgorro/aiquila/hetzner/internal/firewall"
	hcloudclient "github.com/elgorro/aiquila/hetzner/internal/hcloud"
	profilepkg "github.com/elgorro/aiquila/hetzner/internal/profile"
	"github.com/elgorro/aiquila/hetzner/internal/provision"
	"github.com/elgorro/aiquila/hetzner/internal/server"
	"github.com/elgorro/aiquila/hetzner/internal/volume"
	"github.com/hetznercloud/hcloud-go/v2/hcloud"
	"github.com/spf13/cobra"
	xssh "golang.org/x/crypto/ssh"
)

var version = "dev"

var (
	// global flags
	globalProfile string
	logFilePath   string

	// package-level NDJSON logger (initialised by PersistentPreRunE)
	appLog *jsonLogger

	// create flags
	createName       string
	createType       string
	createImage      string
	createLocation   string
	createSSHKey     string
	createDomain     string
	createNCURL      string
	createNCUser     string
	createNCPassword string
	createToken      string
	createAcmeEmail  string
	createMonitoring bool
	createVolumeSize int
	createLUKS       bool
	createDryRun     bool
	createLabels        []string
	createDNSZone       string
	createDNSToken      string
	createSSHAllowCIDR  string
	createNetworkName   string
	createSwap          string
	createConfig        string
	createPackages      []string

	// destroy flags
	destroyName     string
	destroyToken    string
	destroyDNSZone  string
	destroyDNSToken string
)

func main() {
	rootCmd := &cobra.Command{
		Use:     "aiquila-hetzner",
		Short:   "Provision AIquila on Hetzner Cloud",
		Version: version,
		Long: `aiquila-hetzner — single-command provisioner for AIquila MCP Server on Hetzner Cloud.

Creates a server, configures a firewall (22/80/443), installs Docker via cloud-init,
uploads a production docker-compose stack (Traefik + CrowdSec), and starts the services.`,
	}

	rootCmd.PersistentFlags().StringVar(&globalProfile, "profile", "", "Named configuration profile to use (see 'profile' subcommand)")
	rootCmd.PersistentFlags().StringVar(&logFilePath, "log-file", "aiquila-hetzner.log.json", "NDJSON audit log path (empty string to disable)")

	rootCmd.PersistentPreRunE = func(cmd *cobra.Command, _ []string) error {
		var err error
		appLog, err = openLog(logFilePath, cmd.Name())
		if err != nil {
			fmt.Fprintf(os.Stderr, "WARNING: cannot open log file: %v\n", err)
			appLog, _ = openLog("", cmd.Name()) // fall back to no-op
		}
		return nil
	}

	rootCmd.AddCommand(buildCreateCmd())
	rootCmd.AddCommand(buildDestroyCmd())
	rootCmd.AddCommand(buildListCmd())
	rootCmd.AddCommand(buildStopCmd())
	rootCmd.AddCommand(buildStartCmd())
	rootCmd.AddCommand(buildRestartCmd())
	rootCmd.AddCommand(buildDeleteCmd())
	rootCmd.AddCommand(buildDeployCmd())
	rootCmd.AddCommand(buildLogsCmd())
	rootCmd.AddCommand(buildProfileCmd())
	rootCmd.AddCommand(buildDNSCmd())
	rootCmd.AddCommand(buildFirewallCmd())
	rootCmd.AddCommand(buildNetworkCmd())
	rootCmd.AddCommand(buildSnapshotCmd())
	rootCmd.AddCommand(buildRebuildCmd())

	runErr := rootCmd.Execute()
	if appLog != nil {
		appLog.Done(runErr)
		appLog.Close()
	}
	if runErr != nil {
		os.Exit(1)
	}
}

// ── create ────────────────────────────────────────────────────────────────────

func buildCreateCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "create",
		Short: "Create and provision an AIquila server on Hetzner",
		RunE:  runCreate,
	}

	cmd.Flags().StringVar(&createName, "name", "", "Server name (default: aiquila-<random>)")
	cmd.Flags().StringVar(&createType, "type", "cpx21", "Server type (cpx11/cpx21/cpx31/cx22/cx32/ccx13/ccx23)")
	cmd.Flags().StringVar(&createImage, "image", "fedora-41", "OS image — any Hetzner official image\n\t\t\t\t(ubuntu-24.04, ubuntu-22.04, ubuntu-20.04,\n\t\t\t\t debian-12, debian-11,\n\t\t\t\t fedora-41, fedora-40,\n\t\t\t\t centos-stream-9, rocky-9, almalinux-9,\n\t\t\t\t opensuse-leap-15.6, arch)")
	cmd.Flags().StringVar(&createLocation, "location", "nbg1", "Datacenter location (nbg1/fsn1/hel1/ash/hil/sin)")
	cmd.Flags().StringVar(&createSSHKey, "ssh-key", "", "Path to existing SSH public key (omit to generate Ed25519 pair)")
	cmd.Flags().StringVar(&createDomain, "domain", "", "FQDN for HTTPS + MCP_AUTH_ISSUER (required)")
	cmd.Flags().StringVar(&createNCURL, "nc-url", "", "Nextcloud URL (or $NEXTCLOUD_URL)")
	cmd.Flags().StringVar(&createNCUser, "nc-user", "", "Nextcloud username (or $NEXTCLOUD_USER)")
	cmd.Flags().StringVar(&createNCPassword, "nc-password", "", "Nextcloud app password (or $NEXTCLOUD_PASSWORD)")
	cmd.Flags().StringVar(&createToken, "token", "", "Hetzner API token (default: $HCLOUD_TOKEN)")
	cmd.Flags().StringVar(&createAcmeEmail, "acme-email", "", "Email for Let's Encrypt cert expiry notices (optional)")
	cmd.Flags().BoolVar(&createMonitoring, "monitoring", false, "Start monitoring profile (Prometheus + Grafana)")
	cmd.Flags().IntVar(&createVolumeSize, "volume-size", 0, "Create Hetzner Cloud Volume (GB) and mount at /opt/aiquila")
	cmd.Flags().BoolVar(&createLUKS, "luks", false, "[EXPERIMENTAL] LUKS-encrypt the Cloud Volume (requires --volume-size)")
	cmd.Flags().BoolVar(&createDryRun, "dry-run", false, "Print what would be created without making any API calls")
	cmd.Flags().StringArrayVar(&createLabels, "label", nil, "Resource label key=value (repeatable, applied to server/firewall/key/volume)")
	cmd.Flags().StringVar(&createDNSZone, "dns-zone", "", "Hetzner DNS zone (e.g. example.com) — creates <name>.<zone> A record after server IP is known")
	cmd.Flags().StringVar(&createDNSToken, "dns-token", "", "Hetzner DNS API token (default: $HETZNER_DNS_TOKEN or $HCLOUD_TOKEN)")
	cmd.Flags().StringVar(&createSSHAllowCIDR, "ssh-allow-cidr", "", "Restrict SSH (port 22) to this CIDR instead of 0.0.0.0/0 (e.g. 203.0.113.0/24)")
	cmd.Flags().StringVar(&createNetworkName, "network", "", "Attach server to this private network (must exist; create with 'network create')")
	cmd.Flags().StringVar(&createSwap, "swap", "", "Create a swap file of this size (e.g. 1G, 2G) — useful for cpx11/cx22 instances")
	cmd.Flags().StringVar(&createConfig, "config", "", "Path to YAML or JSON deployment config file")
	cmd.Flags().StringArrayVar(&createPackages, "package", nil, "Extra package to install via cloud-init (repeatable; distro-native pkg manager)")

	return cmd
}

func runCreate(_ *cobra.Command, _ []string) error {
	// ── 1. Env var fallbacks for credentials ───────────────────────────────
	if createNCURL == "" {
		createNCURL = os.Getenv("NEXTCLOUD_URL")
	}
	if createNCUser == "" {
		createNCUser = os.Getenv("NEXTCLOUD_USER")
	}
	if createNCPassword == "" {
		createNCPassword = os.Getenv("NEXTCLOUD_PASSWORD")
	}

	// ── 1b. Profile credential fallback ────────────────────────────────────
	{
		cfg, err := profilepkg.Load()
		if err == nil {
			if p, _, ok := cfg.Active(globalProfile); ok {
				if createNCURL == "" {
					createNCURL = p.NextcloudURL
				}
				if createNCUser == "" {
					createNCUser = p.NextcloudUser
				}
				if createNCPassword == "" {
					createNCPassword = p.NextcloudPassword
				}
				if createAcmeEmail == "" {
					createAcmeEmail = p.AcmeEmail
				}
			}
		}
	}

	// ── 1c. Config file fallbacks ──────────────────────────────────────────
	var fileCfg DeployConfig
	if createConfig != "" {
		fc, err := loadDeployConfig(createConfig)
		if err != nil {
			return err
		}
		fileCfg = *fc
	}
	if createName == "" {
		createName = fileCfg.Name
	}
	if createDomain == "" {
		createDomain = fileCfg.Domain
	}
	if createNCURL == "" {
		createNCURL = fileCfg.NCURL
	}
	if createNCUser == "" {
		createNCUser = fileCfg.NCUser
	}
	if createNCPassword == "" {
		createNCPassword = fileCfg.NCPassword
	}
	if createAcmeEmail == "" {
		createAcmeEmail = fileCfg.AcmeEmail
	}
	if createSSHKey == "" {
		createSSHKey = fileCfg.SSHKey
	}
	if createToken == "" {
		createToken = fileCfg.Token
	}
	if fileCfg.Monitoring {
		createMonitoring = true
	}

	// Packages: config file takes priority over CLI --package flags.
	var packages []string
	if len(fileCfg.Packages) > 0 {
		packages = fileCfg.Packages
	} else {
		packages = createPackages
	}

	// ── 2. Validate flags ──────────────────────────────────────────────────
	var missing []string
	if createDomain == "" {
		missing = append(missing, "--domain")
	}
	if createNCURL == "" {
		missing = append(missing, "--nc-url / $NEXTCLOUD_URL")
	}
	if createNCUser == "" {
		missing = append(missing, "--nc-user / $NEXTCLOUD_USER")
	}
	if createNCPassword == "" {
		missing = append(missing, "--nc-password / $NEXTCLOUD_PASSWORD")
	}
	if len(missing) > 0 {
		return fmt.Errorf("required values missing: %s", strings.Join(missing, ", "))
	}

	if createLUKS && createVolumeSize == 0 {
		fmt.Fprintln(os.Stderr, "WARNING: --luks requires --volume-size; ignoring --luks")
		createLUKS = false
	}

	if createName == "" {
		createName = "aiquila-" + randomSuffix(6)
	}

	// ── 3. Parse labels ────────────────────────────────────────────────────
	labels, err := parseLabels(createLabels)
	if err != nil {
		return err
	}

	// ── 4. Dry-run — print plan and exit ───────────────────────────────────
	if createDryRun {
		return printDryRun(createSwap, packages)
	}

	ctx := context.Background()
	fmt.Println("==> aiquila-hetzner create")
	appLog.Info("start", "creating server",
		"name", createName, "type", createType,
		"location", createLocation, "domain", createDomain)

	// ── 5. SSH key ─────────────────────────────────────────────────────────
	fmt.Println("\n── SSH key")
	var privKeyPath, pubKeyContent string

	if createSSHKey != "" {
		content, err := provision.LoadPublicKey(createSSHKey)
		if err != nil {
			return err
		}
		pubKeyContent = content
		privKeyPath = strings.TrimSuffix(createSSHKey, ".pub")
		fmt.Printf("  Using provided SSH key: %s\n", createSSHKey)
	} else {
		kp, err := provision.GenerateKeyPair()
		if err != nil {
			return fmt.Errorf("generate SSH key pair: %w", err)
		}
		privKeyPath = kp.PrivatePath
		pubKeyContent = kp.PublicKey
	}

	// ── 6. Init hcloud client ──────────────────────────────────────────────
	fmt.Println("\n── Hetzner Cloud API")
	client, err := hcloudclient.NewClient(createToken, globalProfile)
	if err != nil {
		return err
	}

	// ── 7. Upload SSH public key to Hetzner (idempotent) ──────────────────
	hcloudKeyName, err := ensureSSHKey(ctx, client, createName, pubKeyContent, labels)
	if err != nil {
		return err
	}

	// ── 7b. Look up private network (if requested) ─────────────────────────
	var createNetworks []*hcloud.Network
	if createNetworkName != "" {
		fmt.Println("\n── Private network")
		net, _, err := client.Network.GetByName(ctx, createNetworkName)
		if err != nil {
			return fmt.Errorf("look up network %q: %w", createNetworkName, err)
		}
		if net == nil {
			return fmt.Errorf("network %q not found — create it first with 'network create'", createNetworkName)
		}
		fmt.Printf("  Found network %q (id=%d)\n", net.Name, net.ID)
		createNetworks = []*hcloud.Network{net}
	}

	// ── 8. Create server with cloud-init userData ──────────────────────────
	fmt.Println("\n── Creating server")
	srv, err := server.Create(ctx, client, server.Options{
		Name:       createName,
		ServerType: createType,
		Image:      createImage,
		Location:   createLocation,
		SSHKeyName: hcloudKeyName,
		UserData:   cloudInitYAML(createSwap, packages),
		Labels:     labels,
		Networks:   createNetworks,
	})
	if err != nil {
		return err
	}
	serverIP := srv.PublicNet.IPv4.IP.String()
	appLog.Info("server", "server created", "server", createName, "ip", serverIP)

	// ── 9. DNS record (before Traefik starts requesting certs) ────────────
	if createDNSZone != "" {
		fmt.Println("\n── DNS")
		dnsToken, err := dns.ResolveToken(createDNSToken, globalProfile)
		if err != nil {
			return err
		}
		if err := dns.EnsureRecord(ctx, dnsToken, createDNSZone, createName, serverIP, "A"); err != nil {
			return err
		}
		ipv6 := srv.PublicNet.IPv6.IP
		if ipv6 != nil && !ipv6.IsUnspecified() {
			// Use the first host address of the /64 prefix (/64 → ::1 suffix).
			ipv6Host := ipv6.Mask(srv.PublicNet.IPv6.Network.Mask)
			ipv6Host[len(ipv6Host)-1] = 1
			if err := dns.EnsureRecord(ctx, dnsToken, createDNSZone, createName, ipv6Host.String(), "AAAA"); err != nil {
				return err
			}
		}
	}

	// ── 10. Create firewall + attach to server ────────────────────────────
	fmt.Println("\n── Firewall")
	if _, err := firewall.Setup(ctx, client, createName+"-fw", srv, labels, createSSHAllowCIDR); err != nil {
		return err
	}

	// ── 11. Create + attach Hetzner Cloud Volume (if requested) ───────────
	var volumeDevicePath string
	if createVolumeSize > 0 {
		fmt.Println("\n── Cloud Volume")
		_, volumeDevicePath, err = volume.Create(ctx, client, srv, createName+"-vol", createVolumeSize, labels)
		if err != nil {
			return err
		}
	}

	// ── 11. Wait for SSH ───────────────────────────────────────────────────
	fmt.Println("\n── Waiting for SSH")
	sshClient, err := provision.WaitAndDial(serverIP, privKeyPath)
	if err != nil {
		return err
	}
	defer sshClient.Close()

	appLog.Info("ssh", "SSH connected", "ip", serverIP)

	// ── 12. Wait for cloud-init to finish ──────────────────────────────────
	fmt.Println("\n── Waiting for cloud-init / Docker install")
	if err := provision.WaitCloudInit(sshClient); err != nil {
		return err
	}

	// ── 13. Format + mount volume (if requested) ───────────────────────────
	if createVolumeSize > 0 {
		fmt.Println("\n── Mounting volume")
		if createLUKS {
			if err := volume.SetupLUKS(sshClient, volumeDevicePath); err != nil {
				return err
			}
		} else {
			if err := volume.FormatAndMount(sshClient, volumeDevicePath); err != nil {
				return err
			}
		}
	}

	// ── 14. Generate .env ──────────────────────────────────────────────────
	fmt.Println("\n── Generating configuration")
	env, err := config.Generate(createNCURL, createNCUser, createNCPassword, createDomain, createAcmeEmail)
	if err != nil {
		return err
	}

	// ── 15. Upload files via SFTP ──────────────────────────────────────────
	fmt.Println("\n── Uploading files to /opt/aiquila")
	uploader, err := provision.NewUploader(sshClient)
	if err != nil {
		return err
	}
	defer uploader.Close()

	uploads := []struct{ path, content string }{
		{"/opt/aiquila/docker-compose.yml", templates.DockerCompose},
		{"/opt/aiquila/traefik.yml", templates.Traefik},
		{"/opt/aiquila/crowdsec/acquis.yml", templates.CrowdSecAcquis},
		{"/opt/aiquila/.env", env.Render()},
	}
	if createMonitoring {
		uploads = append(uploads, struct{ path, content string }{
			"/opt/aiquila/monitoring/prometheus.yml", templates.Prometheus,
		})
	}

	for _, f := range uploads {
		if err := uploader.WriteFile(f.path, f.content); err != nil {
			return fmt.Errorf("upload %s: %w", f.path, err)
		}
		fmt.Printf("  Uploaded %s\n", f.path)
	}

	appLog.Info("upload", "files uploaded", "count", len(uploads))

	// ── 16. Start services ─────────────────────────────────────────────────
	fmt.Println("\n── Starting Docker services")
	composeCmd := "cd /opt/aiquila && docker compose up -d 2>&1"
	if createMonitoring {
		composeCmd = "cd /opt/aiquila && docker compose --profile monitoring up -d 2>&1"
	}
	out, err := provision.RunCommand(sshClient, composeCmd)
	if err != nil {
		fmt.Fprintln(os.Stderr, out)
		return fmt.Errorf("docker compose up: %w", err)
	}
	fmt.Print(out)

	// ── 17. Summary ────────────────────────────────────────────────────────
	grafanaLine := ""
	if createMonitoring {
		grafanaLine = fmt.Sprintf("  Grafana:    https://%s/grafana\n", createDomain)
	}

	privateLine := ""
	if createNetworkName != "" {
		for _, pn := range srv.PrivateNet {
			if pn.Network.Name == createNetworkName {
				privateLine = fmt.Sprintf("  Private IP: %s  (network: %s)\n", pn.IP, createNetworkName)
				break
			}
		}
	}

	dnsNote := fmt.Sprintf("Note: DNS must point to %s before HTTPS is available.", serverIP)
	if createDNSZone != "" {
		dnsNote = fmt.Sprintf("Note: DNS A record created (%s.%s → %s).\n      Allow a few minutes for propagation before HTTPS is available.", createName, createDNSZone, serverIP)
	}

	fmt.Printf(`
╔══════════════════════════════════════╗
║    AIquila deployment complete       ║
╠══════════════════════════════════════╣
  Server IP:  %s
%s  SSH:        ssh -i %s root@%s
  AIquila:    https://%s
  MCP URL:    https://%s/mcp
%s╚══════════════════════════════════════╝

%s
`,
		serverIP,
		privateLine,
		privKeyPath, serverIP,
		createDomain,
		createDomain,
		grafanaLine,
		dnsNote,
	)
	return nil
}

// ── destroy ───────────────────────────────────────────────────────────────────

func buildDestroyCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "destroy",
		Short: "Destroy an AIquila server and its firewall on Hetzner",
		RunE:  runDestroy,
	}

	cmd.Flags().StringVar(&destroyName, "name", "", "Server name to destroy (required)")
	cmd.Flags().StringVar(&destroyToken, "token", "", "Hetzner API token (default: $HCLOUD_TOKEN)")
	cmd.Flags().StringVar(&destroyDNSZone, "dns-zone", "", "Hetzner DNS zone — deletes <name>.<zone> A/AAAA records")
	cmd.Flags().StringVar(&destroyDNSToken, "dns-token", "", "Hetzner DNS API token (default: $HETZNER_DNS_TOKEN or $HCLOUD_TOKEN)")
	_ = cmd.MarkFlagRequired("name")

	return cmd
}

func runDestroy(_ *cobra.Command, _ []string) error {
	ctx := context.Background()
	client, err := hcloudclient.NewClient(destroyToken, globalProfile)
	if err != nil {
		return err
	}
	appLog.Info("start", "destroying server", "name", destroyName)
	fmt.Printf("==> Destroying %q and associated resources\n", destroyName)
	return cleanupServer(ctx, client, destroyName, destroyDNSZone, destroyDNSToken)
}

// ── helpers ───────────────────────────────────────────────────────────────────

func printDryRun(swapSize string, packages []string) error {
	home, _ := os.UserHomeDir()
	keyDesc := "generate Ed25519 → " + home + "/.ssh/aiquila_ed25519"
	if createSSHKey != "" {
		keyDesc = createSSHKey
	}

	volumeDesc := "(none)"
	if createVolumeSize > 0 {
		enc := "plain ext4"
		if createLUKS {
			enc = "LUKS + ext4 [EXPERIMENTAL]"
		}
		volumeDesc = fmt.Sprintf("%d GB, %s", createVolumeSize, enc)
	}

	monDesc := "no"
	composeCmd := "docker compose up -d"
	if createMonitoring {
		monDesc = "yes (Prometheus + Grafana at /grafana)"
		composeCmd = "docker compose --profile monitoring up -d"
	}

	acme := createAcmeEmail
	if acme == "" {
		acme = "(none — expiry notices disabled)"
	}

	fmt.Printf(`
==> Dry run — no changes will be made

  Server:    %s (%s, %s, %s)
  Firewall:  %s-fw  (TCP 22/80/443, UDP 443)
  SSH key:   %s-key  (%s)
  Volume:    %s
  Domain:    %s
  Issuer:    https://%s
  ACME:      %s
  Monitoring: %s

  Would run: %s

  Note: DNS must resolve to the server IP before HTTPS is available.
`,
		createName, createType, createImage, createLocation,
		createName,
		createName, keyDesc,
		volumeDesc,
		createDomain,
		createDomain,
		acme,
		monDesc,
		composeCmd,
	)

	fmt.Println("==> Cloud-init user-data")
	fmt.Println("---")
	fmt.Print(cloudInitYAML(swapSize, packages))
	return nil
}

func randomSuffix(n int) string {
	const chars = "abcdefghijklmnopqrstuvwxyz0123456789"
	r := rand.New(rand.NewSource(time.Now().UnixNano())) //nolint:gosec // non-security randomness
	b := make([]byte, n)
	for i := range b {
		b[i] = chars[r.Intn(len(chars))]
	}
	return string(b)
}

// ensureSSHKey uploads the public key to Hetzner if not already present,
// identified by name. Returns the key name used.
func ensureSSHKey(ctx context.Context, client *hcloud.Client, serverName, pubKeyContent string, labels map[string]string) (string, error) {
	keyName := serverName + "-key"

	existing, _, err := client.SSHKey.GetByName(ctx, keyName)
	if err != nil {
		return "", fmt.Errorf("look up SSH key %q: %w", keyName, err)
	}
	if existing != nil {
		fmt.Printf("  Reusing existing Hetzner SSH key %q (id=%d)\n", keyName, existing.ID)
		return keyName, nil
	}

	// Parse and display fingerprint for reference
	pk, _, _, _, err := xssh.ParseAuthorizedKey([]byte(pubKeyContent))
	if err != nil {
		return "", fmt.Errorf("parse public key: %w", err)
	}
	fp := xssh.FingerprintSHA256(pk)

	key, _, err := client.SSHKey.Create(ctx, hcloud.SSHKeyCreateOpts{
		Name:      keyName,
		PublicKey: pubKeyContent,
		Labels:    labels,
	})
	if err != nil {
		return "", fmt.Errorf("upload SSH key to Hetzner: %w", err)
	}
	fmt.Printf("  Uploaded SSH key %q (id=%d, fingerprint=%s)\n", keyName, key.ID, fp)
	return keyName, nil
}

// parseLabels converts a slice of "key=value" strings into a map.
func parseLabels(pairs []string) (map[string]string, error) {
	if len(pairs) == 0 {
		return nil, nil
	}
	labels := make(map[string]string, len(pairs))
	for _, pair := range pairs {
		k, v, found := strings.Cut(pair, "=")
		if !found || k == "" {
			return nil, fmt.Errorf("invalid label %q: must be key=value", pair)
		}
		labels[k] = v
	}
	return labels, nil
}

// cloudInitYAML returns the cloud-init user-data for Docker installation.
// swapSize, if non-empty (e.g. "2G"), creates and enables a swap file at /swapfile.
// packages, if non-empty, are installed via cloud-init's native package module.
func cloudInitYAML(swapSize string, packages []string) string {
	swapStep := ""
	if swapSize != "" {
		swapStep = fmt.Sprintf(`  - |
    fallocate -l %s /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    echo '/swapfile none swap sw 0 0' >> /etc/fstab
`, swapSize)
	}

	pkgBlock := ""
	if len(packages) > 0 {
		lines := "packages:\n"
		for _, p := range packages {
			lines += fmt.Sprintf("  - %s\n", p)
		}
		pkgBlock = lines + "\n"
	}

	return `#cloud-config
package_update: true

` + pkgBlock + `runcmd:
  - |
    set -e
    . /etc/os-release
    if command -v dnf >/dev/null 2>&1; then
      dnf -y install dnf-plugins-core
      # Fedora uses its own repo; CentOS/Rocky/AlmaLinux use the centos repo
      if [ "$ID" = "fedora" ]; then
        dnf config-manager --add-repo https://download.docker.com/linux/fedora/docker-ce.repo
      else
        dnf config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo
      fi
      dnf -y install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
    elif command -v pacman >/dev/null 2>&1; then
      pacman -Sy --noconfirm docker docker-compose
    elif command -v zypper >/dev/null 2>&1; then
      zypper install -y docker
      mkdir -p /usr/local/lib/docker/cli-plugins
      curl -fsSL "https://github.com/docker/compose/releases/download/v2.27.1/docker-compose-linux-$(uname -m)" \
        -o /usr/local/lib/docker/cli-plugins/docker-compose
      chmod +x /usr/local/lib/docker/cli-plugins/docker-compose
    elif command -v apt-get >/dev/null 2>&1; then
      apt-get install -y ca-certificates curl
      install -m 0755 -d /etc/apt/keyrings
      curl -fsSL "https://download.docker.com/linux/${ID}/gpg" -o /etc/apt/keyrings/docker.asc
      chmod a+r /etc/apt/keyrings/docker.asc
      echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/${ID} ${VERSION_CODENAME} stable" \
        > /etc/apt/sources.list.d/docker.list
      apt-get update -y
      apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
    fi
  - systemctl enable --now docker
  - mkdir -p /opt/aiquila
` + swapStep
}
