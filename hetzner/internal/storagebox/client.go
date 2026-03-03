package storagebox

import (
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
)

const robotBaseURL = "https://robot-ws.your-server.de"

// StorageBox mirrors the relevant fields from the Hetzner Robot API response.
type StorageBox struct {
	ID       int    `json:"id"`
	Login    string `json:"login"`
	Server   string `json:"server"`
	Samba    bool   `json:"samba"`
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
