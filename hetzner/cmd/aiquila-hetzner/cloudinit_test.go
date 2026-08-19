package main

import (
	"strings"
	"testing"
)

// The Docker install runs as root on first boot, so every artifact it fetches
// must be verified. These assertions fail loudly if a future edit drops one of
// the checks rather than letting an unverified install ship silently.
func TestCloudInitVerifiesDockerArtifacts(t *testing.T) {
	got := cloudInitYAML("", nil)

	for _, want := range []string{
		dockerGPGFingerprint,
		"verify_docker_gpg /etc/apt/keyrings/docker.asc",
		"sha256sum -c -",
		composeSHA256x86_64,
		composeSHA256aarch64,
		composeVersion,
		"gpgcheck=1",
	} {
		if !strings.Contains(got, want) {
			t.Errorf("cloud-init is missing integrity check %q", want)
		}
	}
}

func TestCloudInitFailsOnUnsupportedDistro(t *testing.T) {
	got := cloudInitYAML("", nil)
	if !strings.Contains(got, "Unsupported distribution") {
		t.Error("cloud-init should exit non-zero on a distro with no known package manager")
	}
	if !strings.Contains(got, "set -e") {
		t.Error("cloud-init runcmd should abort on the first failing command")
	}
}

func TestCloudInitSwapAndPackages(t *testing.T) {
	if strings.Contains(cloudInitYAML("", nil), "/swapfile") {
		t.Error("no swap step expected when swapSize is empty")
	}
	got := cloudInitYAML("2G", []string{"htop", "jq"})
	if !strings.Contains(got, "fallocate -l 2G /swapfile") {
		t.Error("swap step missing for swapSize=2G")
	}
	for _, want := range []string{"packages:", "  - htop", "  - jq"} {
		if !strings.Contains(got, want) {
			t.Errorf("package block missing %q", want)
		}
	}
}
