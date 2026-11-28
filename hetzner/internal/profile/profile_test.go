// SPDX-License-Identifier: MIT

package profile

import (
	"os"
	"path/filepath"
	"testing"
)

func TestLoadWithoutAFileIsEmpty(t *testing.T) {
	t.Setenv("HOME", t.TempDir())

	cfg, err := Load()
	if err != nil {
		t.Fatal(err)
	}
	if cfg.Current != "" || cfg.Profiles == nil || len(cfg.Profiles) != 0 {
		t.Errorf("cfg = %+v", cfg)
	}
}

func TestSaveAndLoadRoundTripPrivately(t *testing.T) {
	home := t.TempDir()
	t.Setenv("HOME", home)

	cfg := &Config{Current: "prod", Profiles: map[string]Profile{}}
	cfg.Set("prod", Profile{Token: "tok", NextcloudURL: "https://cloud.example.com", AcmeEmail: "ops@example.com"})
	if err := Save(cfg); err != nil {
		t.Fatal(err)
	}

	path := filepath.Join(home, ".config", "aiquila-hetzner", "config.json")
	info, err := os.Stat(path)
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() != 0o600 {
		t.Errorf("config mode = %o, want 600: it holds API tokens", info.Mode().Perm())
	}
	if dir, _ := os.Stat(filepath.Dir(path)); dir.Mode().Perm() != 0o700 {
		t.Errorf("config dir mode = %o, want 700", dir.Mode().Perm())
	}
	if _, err := os.Stat(path + ".tmp"); !os.IsNotExist(err) {
		t.Errorf("temp file left behind: %v", err)
	}

	loaded, err := Load()
	if err != nil {
		t.Fatal(err)
	}
	p, ok := loaded.Get("prod")
	if !ok || p != cfg.Profiles["prod"] || loaded.Current != "prod" {
		t.Errorf("loaded = %+v", loaded)
	}
}

func TestLoadRejectsACorruptFile(t *testing.T) {
	home := t.TempDir()
	t.Setenv("HOME", home)
	dir := filepath.Join(home, ".config", "aiquila-hetzner")
	if err := os.MkdirAll(dir, 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "config.json"), []byte("{"), 0o600); err != nil {
		t.Fatal(err)
	}

	if _, err := Load(); err == nil {
		t.Fatal("Load accepted invalid JSON")
	}
}

func TestLoadFillsAMissingProfilesMap(t *testing.T) {
	home := t.TempDir()
	t.Setenv("HOME", home)
	dir := filepath.Join(home, ".config", "aiquila-hetzner")
	_ = os.MkdirAll(dir, 0o700)
	_ = os.WriteFile(filepath.Join(dir, "config.json"), []byte(`{"current":"x"}`), 0o600)

	cfg, err := Load()
	if err != nil {
		t.Fatal(err)
	}
	cfg.Set("x", Profile{}) // would panic on a nil map
}

func TestActiveResolvesTheNamedThenTheCurrentProfile(t *testing.T) {
	cfg := &Config{Current: "a", Profiles: map[string]Profile{"a": {Token: "A"}, "b": {Token: "B"}}}

	if p, name, ok := cfg.Active("b"); !ok || name != "b" || p.Token != "B" {
		t.Errorf("Active(b) = %+v %q %v", p, name, ok)
	}
	if p, name, ok := cfg.Active(""); !ok || name != "a" || p.Token != "A" {
		t.Errorf("Active() = %+v %q %v", p, name, ok)
	}
	if _, name, ok := cfg.Active("missing"); ok || name != "missing" {
		t.Errorf("Active(missing) = %q %v", name, ok)
	}
	cfg.Delete("a")
	cfg.Delete("never-there")
	if _, _, ok := cfg.Active(""); ok {
		t.Error("Active() found the deleted current profile")
	}
	if _, _, ok := (&Config{Profiles: map[string]Profile{}}).Active(""); ok {
		t.Error("Active() with no current profile found one")
	}
}

func TestMaskSecretNeverShowsAShortSecret(t *testing.T) {
	for in, want := range map[string]string{
		"":                   "(not set)",
		"short":              "***",
		"12345678":           "***",
		"abcdefghijklmnopqr": "abcd...opqr",
	} {
		if got := MaskSecret(in); got != want {
			t.Errorf("MaskSecret(%q) = %q, want %q", in, got, want)
		}
	}
}
