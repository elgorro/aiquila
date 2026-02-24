package provision

import (
	"crypto/ed25519"
	"crypto/rand"
	"encoding/pem"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"time"

	xssh "golang.org/x/crypto/ssh"
)

const (
	sshRetryInterval = 15 * time.Second
	sshRetryTimeout  = 5 * time.Minute
	sshPort          = "22"
)

// KeyPair holds paths to the generated Ed25519 SSH key pair.
type KeyPair struct {
	PrivatePath string
	PublicPath  string
	PublicKey   string // authorised-keys format
}

// GenerateKeyPair creates an Ed25519 SSH key pair and saves it to ~/.ssh/.
// If the files already exist the user is prompted to overwrite or reuse.
func GenerateKeyPair() (*KeyPair, error) {
	home, err := os.UserHomeDir()
	if err != nil {
		return nil, fmt.Errorf("home dir: %w", err)
	}

	privPath := filepath.Join(home, ".ssh", "aiquila_ed25519")
	pubPath := privPath + ".pub"

	// Check if files already exist
	if _, err := os.Stat(privPath); err == nil {
		fmt.Printf("  SSH key already exists at %s\n", privPath)
		fmt.Print("  Reuse existing key? [Y/n]: ")
		var answer string
		fmt.Scanln(&answer)
		if answer == "" || answer == "y" || answer == "Y" {
			pubBytes, err := os.ReadFile(pubPath)
			if err != nil {
				return nil, fmt.Errorf("read existing public key: %w", err)
			}
			return &KeyPair{
				PrivatePath: privPath,
				PublicPath:  pubPath,
				PublicKey:   string(pubBytes),
			}, nil
		}
		fmt.Println("  Overwriting existing key pair...")
	}

	// Generate Ed25519 key pair
	pubKey, privKey, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		return nil, fmt.Errorf("generate ed25519 key: %w", err)
	}

	// Marshal private key to OpenSSH PEM
	privPEMBlock, err := xssh.MarshalPrivateKey(privKey, "")
	if err != nil {
		return nil, fmt.Errorf("marshal private key: %w", err)
	}
	privPEMBytes := pem.EncodeToMemory(privPEMBlock)

	// Marshal public key to authorised-keys format
	sshPubKey, err := xssh.NewPublicKey(pubKey)
	if err != nil {
		return nil, fmt.Errorf("create ssh public key: %w", err)
	}
	pubKeyBytes := xssh.MarshalAuthorizedKey(sshPubKey)

	// Save to disk
	if err := os.MkdirAll(filepath.Dir(privPath), 0o700); err != nil {
		return nil, fmt.Errorf("create .ssh dir: %w", err)
	}
	if err := os.WriteFile(privPath, privPEMBytes, 0o600); err != nil {
		return nil, fmt.Errorf("write private key: %w", err)
	}
	if err := os.WriteFile(pubPath, pubKeyBytes, 0o644); err != nil {
		return nil, fmt.Errorf("write public key: %w", err)
	}

	fp := xssh.FingerprintSHA256(sshPubKey)
	fmt.Printf("  Generated Ed25519 key pair\n    Private: %s\n    Public:  %s\n    Fingerprint: %s\n",
		privPath, pubPath, fp)

	return &KeyPair{
		PrivatePath: privPath,
		PublicPath:  pubPath,
		PublicKey:   string(pubKeyBytes),
	}, nil
}

// LoadPublicKey reads the public key content from a file path.
func LoadPublicKey(path string) (string, error) {
	b, err := os.ReadFile(path)
	if err != nil {
		return "", fmt.Errorf("read public key %q: %w", path, err)
	}
	return string(b), nil
}

// PrivateKeyAuth returns an ssh.AuthMethod from a private key file.
func PrivateKeyAuth(privKeyPath string) (xssh.AuthMethod, error) {
	b, err := os.ReadFile(privKeyPath)
	if err != nil {
		return nil, fmt.Errorf("read private key: %w", err)
	}
	signer, err := xssh.ParsePrivateKey(b)
	if err != nil {
		return nil, fmt.Errorf("parse private key: %w", err)
	}
	return xssh.PublicKeys(signer), nil
}

// WaitAndDial connects to host:22 via SSH, retrying with backoff until the
// server accepts connections or the timeout expires.
func WaitAndDial(host, privKeyPath string) (*xssh.Client, error) {
	auth, err := PrivateKeyAuth(privKeyPath)
	if err != nil {
		return nil, err
	}

	cfg := &xssh.ClientConfig{
		User:            "root",
		Auth:            []xssh.AuthMethod{auth},
		HostKeyCallback: xssh.InsecureIgnoreHostKey(), //nolint:gosec // first-connect to a fresh server
		Timeout:         10 * time.Second,
	}

	addr := net.JoinHostPort(host, sshPort)
	deadline := time.Now().Add(sshRetryTimeout)

	fmt.Printf("  Waiting for SSH on %s", addr)
	for {
		client, err := xssh.Dial("tcp", addr, cfg)
		if err == nil {
			fmt.Println(" connected.")
			return client, nil
		}
		if time.Now().After(deadline) {
			return nil, fmt.Errorf("timeout waiting for SSH on %s: %w", addr, err)
		}
		fmt.Print(".")
		time.Sleep(sshRetryInterval)
	}
}

// RunCommand executes a shell command on the remote host and returns combined output.
func RunCommand(client *xssh.Client, cmd string) (string, error) {
	sess, err := client.NewSession()
	if err != nil {
		return "", fmt.Errorf("new SSH session: %w", err)
	}
	defer sess.Close()

	out, err := sess.CombinedOutput(cmd)
	if err != nil {
		return string(out), fmt.Errorf("run %q: %w (output: %s)", cmd, err, string(out))
	}
	return string(out), nil
}

// WaitCloudInit polls until the cloud-init boot-finished sentinel file exists.
func WaitCloudInit(client *xssh.Client) error {
	const sentinel = "/var/lib/cloud/instance/boot-finished"
	const interval = 20 * time.Second
	const timeout = 10 * time.Minute

	fmt.Print("  Waiting for cloud-init to finish")
	deadline := time.Now().Add(timeout)
	for {
		_, err := RunCommand(client, fmt.Sprintf("test -f %s && echo ok", sentinel))
		if err == nil {
			fmt.Println(" done.")
			return nil
		}
		if time.Now().After(deadline) {
			return fmt.Errorf("timeout waiting for cloud-init sentinel %s", sentinel)
		}
		fmt.Print(".")
		time.Sleep(interval)
	}
}
