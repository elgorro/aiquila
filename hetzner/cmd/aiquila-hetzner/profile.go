package main

import (
	"fmt"
	"os"
	"sort"
	"text/tabwriter"

	profilepkg "github.com/elgorro/aiquila/hetzner/internal/profile"
	"github.com/spf13/cobra"
)

var (
	profileAddName     string
	profileAddToken    string
	profileAddNCURL    string
	profileAddNCUser   string
	profileAddNCPass   string
	profileAddEmail    string
	profileShowName    string
	profileDeleteName  string
	profileUseName     string
)

// buildProfileCmd returns the 'profile' parent command with all subcommands.
func buildProfileCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "profile",
		Short: "Manage named configuration profiles",
		Long: `Named profiles store a Hetzner API token and optional Nextcloud credentials
so you don't have to repeat them on every command.

Profile resolution order (first non-empty wins):
  1. --token flag / $HCLOUD_TOKEN env var
  2. Profile named by --profile flag
  3. Profile set as current with 'profile use'

Config file: ~/.config/aiquila-hetzner/config.json`,
	}
	cmd.AddCommand(buildProfileAddCmd())
	cmd.AddCommand(buildProfileListCmd())
	cmd.AddCommand(buildProfileShowCmd())
	cmd.AddCommand(buildProfileUseCmd())
	cmd.AddCommand(buildProfileDeleteCmd())
	return cmd
}

// ── profile add ───────────────────────────────────────────────────────────────

func buildProfileAddCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "add",
		Short: "Add or update a named profile",
		RunE:  runProfileAdd,
	}
	cmd.Flags().StringVar(&profileAddName, "name", "", "Profile name (required)")
	cmd.Flags().StringVar(&profileAddToken, "token", "", "Hetzner API token for this profile")
	cmd.Flags().StringVar(&profileAddNCURL, "nc-url", "", "Nextcloud URL")
	cmd.Flags().StringVar(&profileAddNCUser, "nc-user", "", "Nextcloud username")
	cmd.Flags().StringVar(&profileAddNCPass, "nc-password", "", "Nextcloud app password")
	cmd.Flags().StringVar(&profileAddEmail, "acme-email", "", "ACME email for Let's Encrypt expiry notices")
	_ = cmd.MarkFlagRequired("name")
	return cmd
}

func runProfileAdd(_ *cobra.Command, _ []string) error {
	cfg, err := profilepkg.Load()
	if err != nil {
		return err
	}

	existing, exists := cfg.Get(profileAddName)
	if !exists {
		existing = profilepkg.Profile{}
	}

	// Merge: only overwrite fields that were explicitly set.
	if profileAddToken != "" {
		existing.Token = profileAddToken
	}
	if profileAddNCURL != "" {
		existing.NextcloudURL = profileAddNCURL
	}
	if profileAddNCUser != "" {
		existing.NextcloudUser = profileAddNCUser
	}
	if profileAddNCPass != "" {
		existing.NextcloudPassword = profileAddNCPass
	}
	if profileAddEmail != "" {
		existing.AcmeEmail = profileAddEmail
	}

	cfg.Set(profileAddName, existing)
	if err := profilepkg.Save(cfg); err != nil {
		return err
	}

	verb := "Added"
	if exists {
		verb = "Updated"
	}
	fmt.Printf("  %s profile %q\n", verb, profileAddName)

	path, _ := profilepkg.ConfigPath()
	fmt.Printf("  Config: %s\n", path)
	return nil
}

// ── profile list ──────────────────────────────────────────────────────────────

func buildProfileListCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "list",
		Short: "List all profiles",
		RunE:  runProfileList,
	}
}

func runProfileList(_ *cobra.Command, _ []string) error {
	cfg, err := profilepkg.Load()
	if err != nil {
		return err
	}

	if len(cfg.Profiles) == 0 {
		fmt.Println("No profiles configured. Use 'profile add' to create one.")
		return nil
	}

	names := make([]string, 0, len(cfg.Profiles))
	for n := range cfg.Profiles {
		names = append(names, n)
	}
	sort.Strings(names)

	w := tabwriter.NewWriter(os.Stdout, 0, 0, 3, ' ', 0)
	fmt.Fprintln(w, "NAME\tCURRENT\tTOKEN\tNC URL")
	fmt.Fprintln(w, "────\t───────\t─────\t──────")
	for _, name := range names {
		p := cfg.Profiles[name]
		current := ""
		if name == cfg.Current {
			current = "✓"
		}
		fmt.Fprintf(w, "%s\t%s\t%s\t%s\n",
			name,
			current,
			profilepkg.MaskSecret(p.Token),
			p.NextcloudURL,
		)
	}
	w.Flush()
	return nil
}

// ── profile show ──────────────────────────────────────────────────────────────

func buildProfileShowCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "show",
		Short: "Show full details of a profile (secrets masked)",
		RunE:  runProfileShow,
	}
	cmd.Flags().StringVar(&profileShowName, "name", "", "Profile name (default: current)")
	return cmd
}

func runProfileShow(_ *cobra.Command, _ []string) error {
	cfg, err := profilepkg.Load()
	if err != nil {
		return err
	}

	p, name, ok := cfg.Active(profileShowName)
	if !ok {
		if profileShowName != "" {
			return fmt.Errorf("profile %q not found", profileShowName)
		}
		return fmt.Errorf("no current profile set — use --name or 'profile use <name>'")
	}

	current := ""
	if name == cfg.Current {
		current = " (current)"
	}
	fmt.Printf("Profile: %s%s\n\n", name, current)
	fmt.Printf("  token:             %s\n", profilepkg.MaskSecret(p.Token))
	fmt.Printf("  nextcloud_url:     %s\n", orNotSet(p.NextcloudURL))
	fmt.Printf("  nextcloud_user:    %s\n", orNotSet(p.NextcloudUser))
	fmt.Printf("  nextcloud_password:%s\n", profilepkg.MaskSecret(p.NextcloudPassword))
	fmt.Printf("  acme_email:        %s\n", orNotSet(p.AcmeEmail))
	return nil
}

func orNotSet(s string) string {
	if s == "" {
		return "(not set)"
	}
	return s
}

// ── profile use ───────────────────────────────────────────────────────────────

func buildProfileUseCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "use",
		Short: "Set the default profile",
		RunE:  runProfileUse,
	}
	cmd.Flags().StringVar(&profileUseName, "name", "", "Profile name to set as default (required)")
	_ = cmd.MarkFlagRequired("name")
	return cmd
}

func runProfileUse(_ *cobra.Command, _ []string) error {
	cfg, err := profilepkg.Load()
	if err != nil {
		return err
	}
	if _, ok := cfg.Get(profileUseName); !ok {
		return fmt.Errorf("profile %q not found — create it first with 'profile add'", profileUseName)
	}
	cfg.Current = profileUseName
	if err := profilepkg.Save(cfg); err != nil {
		return err
	}
	fmt.Printf("  Default profile set to %q\n", profileUseName)
	return nil
}

// ── profile delete ────────────────────────────────────────────────────────────

func buildProfileDeleteCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "delete",
		Short: "Remove a profile",
		RunE:  runProfileDelete,
	}
	cmd.Flags().StringVar(&profileDeleteName, "name", "", "Profile name to delete (required)")
	_ = cmd.MarkFlagRequired("name")
	return cmd
}

func runProfileDelete(_ *cobra.Command, _ []string) error {
	cfg, err := profilepkg.Load()
	if err != nil {
		return err
	}
	if _, ok := cfg.Get(profileDeleteName); !ok {
		return fmt.Errorf("profile %q not found", profileDeleteName)
	}
	cfg.Delete(profileDeleteName)
	if cfg.Current == profileDeleteName {
		cfg.Current = ""
	}
	if err := profilepkg.Save(cfg); err != nil {
		return err
	}
	fmt.Printf("  Deleted profile %q\n", profileDeleteName)
	return nil
}
