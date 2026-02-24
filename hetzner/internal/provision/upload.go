package provision

import (
	"fmt"
	"io"
	"strings"

	"github.com/pkg/sftp"
	xssh "golang.org/x/crypto/ssh"
)

// Uploader uploads files to a remote host via SFTP.
type Uploader struct {
	client *sftp.Client
}

// NewUploader creates an SFTP client from an existing SSH connection.
func NewUploader(sshClient *xssh.Client) (*Uploader, error) {
	sc, err := sftp.NewClient(sshClient)
	if err != nil {
		return nil, fmt.Errorf("open SFTP session: %w", err)
	}
	return &Uploader{client: sc}, nil
}

// Close closes the underlying SFTP session.
func (u *Uploader) Close() error {
	return u.client.Close()
}

// WriteFile writes content to a remote path, creating parent directories as needed.
func (u *Uploader) WriteFile(remotePath, content string) error {
	// Ensure remote directory exists
	dir := remotePath[:strings.LastIndex(remotePath, "/")]
	if err := u.client.MkdirAll(dir); err != nil {
		return fmt.Errorf("mkdir %q: %w", dir, err)
	}

	f, err := u.client.Create(remotePath)
	if err != nil {
		return fmt.Errorf("create remote file %q: %w", remotePath, err)
	}
	defer f.Close()

	if _, err := io.WriteString(f, content); err != nil {
		return fmt.Errorf("write remote file %q: %w", remotePath, err)
	}
	return nil
}
