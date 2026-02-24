// Package profile manages named configuration profiles for aiquila-hetzner.
// Profiles are stored in ~/.config/aiquila-hetzner/config.json (mode 0600).
// Each profile stores a Hetzner API token plus optional Nextcloud credentials
// so they do not need to be repeated on every command invocation.
package profile

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
)

// Profile is a named set of connection defaults.
type Profile struct {
	Token             string `json:"token,omitempty"`
	NextcloudURL      string `json:"nextcloud_url,omitempty"`
	NextcloudUser     string `json:"nextcloud_user,omitempty"`
	NextcloudPassword string `json:"nextcloud_password,omitempty"`
	AcmeEmail         string `json:"acme_email,omitempty"`
}

// Config is the root of the config file.
type Config struct {
	// Current is the profile used when --profile is not specified.
	Current  string             `json:"current,omitempty"`
	Profiles map[string]Profile `json:"profiles"`
}

// ConfigPath returns the absolute path to the config file.
func ConfigPath() (string, error) {
	home, err := os.UserHomeDir()
	if err != nil {
		return "", fmt.Errorf("home dir: %w", err)
	}
	return filepath.Join(home, ".config", "aiquila-hetzner", "config.json"), nil
}

// Load reads the config file. Returns an empty Config if the file does not exist.
func Load() (*Config, error) {
	path, err := ConfigPath()
	if err != nil {
		return nil, err
	}
	data, err := os.ReadFile(path)
	if os.IsNotExist(err) {
		return &Config{Profiles: make(map[string]Profile)}, nil
	}
	if err != nil {
		return nil, fmt.Errorf("read config %s: %w", path, err)
	}
	var cfg Config
	if err := json.Unmarshal(data, &cfg); err != nil {
		return nil, fmt.Errorf("parse config: %w", err)
	}
	if cfg.Profiles == nil {
		cfg.Profiles = make(map[string]Profile)
	}
	return &cfg, nil
}

// Save writes the config atomically to disk (mode 0600).
func Save(cfg *Config) error {
	path, err := ConfigPath()
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return fmt.Errorf("create config dir: %w", err)
	}
	data, err := json.MarshalIndent(cfg, "", "  ")
	if err != nil {
		return fmt.Errorf("marshal config: %w", err)
	}
	// Write via temp file then rename for atomicity.
	tmp := path + ".tmp"
	if err := os.WriteFile(tmp, data, 0o600); err != nil {
		return fmt.Errorf("write config: %w", err)
	}
	return os.Rename(tmp, path)
}

// Get returns the named profile and whether it exists.
func (c *Config) Get(name string) (Profile, bool) {
	p, ok := c.Profiles[name]
	return p, ok
}

// Set adds or replaces a profile.
func (c *Config) Set(name string, p Profile) {
	c.Profiles[name] = p
}

// Delete removes a profile. Does nothing if it does not exist.
func (c *Config) Delete(name string) {
	delete(c.Profiles, name)
}

// Active resolves which profile to use given an explicit name:
//   - If name is non-empty, uses that profile.
//   - Otherwise falls back to Config.Current.
//
// Returns the profile, the resolved name, and true if found.
func (c *Config) Active(name string) (Profile, string, bool) {
	target := name
	if target == "" {
		target = c.Current
	}
	if target == "" {
		return Profile{}, "", false
	}
	p, ok := c.Profiles[target]
	return p, target, ok
}

// MaskSecret returns a redacted version of a secret for display.
func MaskSecret(s string) string {
	if s == "" {
		return "(not set)"
	}
	if len(s) <= 8 {
		return "***"
	}
	return s[:4] + "..." + s[len(s)-4:]
}
