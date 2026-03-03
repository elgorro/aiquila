package storagebox

import (
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"encoding/pem"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"

	"golang.org/x/crypto/ssh"
)

const robotBaseURL = "https://robot-ws.your-server.de"

// StorageBox mirrors the relevant fields from the Hetzner Robot API response.
type StorageBox struct {
	ID       int    `json:"id"`
	Login    string `json:"login"`
	Server   string `json:"server"`
	Samba    bool   `json:"samba"`
	SSH      bool   `json:"ssh"`
	Product  string `json:"product"`
	Location string `json:"location"`
}

// RobotClient is a minimal Hetzner Robot API client using Basic Auth and stdlib net/http.
type RobotClient struct {
	user       string
	password   string
	httpClient *http.Client
}

// NewClient creates a new RobotClient with the given Robot API credentials.
func NewClient(user, password string) *RobotClient {
	return &RobotClient{
		user:       user,
		password:   password,
		httpClient: &http.Client{},
	}
}

func (c *RobotClient) basicAuth() string {
	return "Basic " + base64.StdEncoding.EncodeToString([]byte(c.user+":"+c.password))
}

func (c *RobotClient) do(method, path string, body url.Values) ([]byte, error) {
	reqURL := robotBaseURL + path
	var bodyReader io.Reader
	if body != nil {
		bodyReader = strings.NewReader(body.Encode())
	}
	req, err := http.NewRequest(method, reqURL, bodyReader)
	if err != nil {
		return nil, fmt.Errorf("build request: %w", err)
	}
	req.Header.Set("Authorization", c.basicAuth())
	if body != nil {
		req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	}

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return nil, fmt.Errorf("robot API request: %w", err)
	}
	defer resp.Body.Close()

	data, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, fmt.Errorf("read response: %w", err)
	}
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return nil, fmt.Errorf("robot API %s %s: HTTP %d: %s", method, path, resp.StatusCode, string(data))
	}
	return data, nil
}

// GetStorageBox retrieves storage box details by ID.
func (c *RobotClient) GetStorageBox(id int) (*StorageBox, error) {
	data, err := c.do("GET", fmt.Sprintf("/storagebox/%d", id), nil)
	if err != nil {
		return nil, err
	}
	var envelope struct {
		StorageBox StorageBox `json:"storagebox"`
	}
	if err := json.Unmarshal(data, &envelope); err != nil {
		return nil, fmt.Errorf("parse storage box response: %w", err)
	}
	return &envelope.StorageBox, nil
}

// EnableSamba enables Samba/CIFS access for the storage box.
func (c *RobotClient) EnableSamba(id int) error {
	_, err := c.do("POST", fmt.Sprintf("/storagebox/%d", id), url.Values{"samba": {"true"}})
	return err
}

// SetPassword sets a new password for the storage box.
func (c *RobotClient) SetPassword(id int, pw string) error {
	_, err := c.do("POST", fmt.Sprintf("/storagebox/%d/password", id), url.Values{"password": {pw}})
	return err
}

// EnableSSH enables SSH/SFTP access for the storage box.
func (c *RobotClient) EnableSSH(id int) error {
	_, err := c.do("POST", fmt.Sprintf("/storagebox/%d", id), url.Values{"ssh": {"true"}})
	return err
}

// AddSSHKey uploads an SSH public key to the storage box.
// name is a human-readable label; data is the raw OpenSSH public key string.
func (c *RobotClient) AddSSHKey(id int, name, data string) error {
	_, err := c.do("POST", fmt.Sprintf("/storagebox/%d/sshkey", id),
		url.Values{"name": {name}, "data": {data}})
	return err
}

// GenerateSSHKeyPair generates an ed25519 key pair.
// Returns (publicKeyAuthorizedKeysFormat, privateKeyPEMBytes, error).
func GenerateSSHKeyPair() ([]byte, []byte, error) {
	pub, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		return nil, nil, fmt.Errorf("generate ed25519 key: %w", err)
	}

	// Marshal private key to OpenSSH PEM format.
	privPEM, err := ssh.MarshalPrivateKey(priv, "")
	if err != nil {
		return nil, nil, fmt.Errorf("marshal private key: %w", err)
	}
	privBytes := pem.EncodeToMemory(privPEM)

	// Marshal public key to authorized_keys format.
	sshPub, err := ssh.NewPublicKey(pub)
	if err != nil {
		return nil, nil, fmt.Errorf("marshal public key: %w", err)
	}
	pubBytes := ssh.MarshalAuthorizedKey(sshPub)

	return pubBytes, privBytes, nil
}
