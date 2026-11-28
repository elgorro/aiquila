// SPDX-License-Identifier: MIT

// Package sshtest runs an in-process SSH server for tests. It speaks the real
// SSH protocol over a loopback connection, so code under test gets a genuine
// *ssh.Client and nothing leaves the machine. (net.Pipe would deadlock: both
// ends write their version banner before reading.) Exec requests are recorded and answered by
// a handler; the sftp subsystem is served from memory.
package sshtest

import (
	"bytes"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/binary"
	"io"
	"net"
	"os"
	"sync"
	"testing"

	"github.com/pkg/sftp"
	xssh "golang.org/x/crypto/ssh"
)

// Result is what a command prints (stdout and stderr combined) and its exit status.
type Result struct {
	Output string
	Exit   uint32
}

// Server records every command it is asked to run.
type Server struct {
	t      testing.TB
	config *xssh.ServerConfig
	// Handle answers a command. The default prints nothing and exits 0.
	Handle func(cmd string) Result

	mu       sync.Mutex
	commands []string
	modes    map[string]os.FileMode
	files    sftp.Handlers
}

// New returns a server whose host key and client acceptance are test-only.
func New(t testing.TB) *Server {
	t.Helper()
	_, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	signer, err := xssh.NewSignerFromKey(priv)
	if err != nil {
		t.Fatal(err)
	}
	cfg := &xssh.ServerConfig{
		PublicKeyCallback: func(xssh.ConnMetadata, xssh.PublicKey) (*xssh.Permissions, error) {
			return nil, nil
		},
	}
	cfg.AddHostKey(signer)
	return &Server{
		t:      t,
		config: cfg,
		Handle: func(string) Result { return Result{} },
		modes:  map[string]os.FileMode{},
		files:  sftp.InMemHandler(),
	}
}

// Commands returns the commands run so far, in order.
func (s *Server) Commands() []string {
	s.mu.Lock()
	defer s.mu.Unlock()
	return append([]string(nil), s.commands...)
}

// Mode returns the permissions last set on path over SFTP, and whether any were.
func (s *Server) Mode(path string) (os.FileMode, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	m, ok := s.modes[path]
	return m, ok
}

// Dial has the signature of ssh.Dial, so it can replace it. The address is
// ignored; the client config, including its auth methods, is used as given.
func (s *Server) Dial(_, addr string, cfg *xssh.ClientConfig) (*xssh.Client, error) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		return nil, err
	}
	go func() {
		defer ln.Close()
		if serverSide, err := ln.Accept(); err == nil {
			s.serve(serverSide)
		}
	}()
	clientSide, err := net.Dial("tcp", ln.Addr().String())
	if err != nil {
		return nil, err
	}
	conn, chans, reqs, err := xssh.NewClientConn(clientSide, addr, cfg)
	if err != nil {
		return nil, err
	}
	return xssh.NewClient(conn, chans, reqs), nil
}

// Client returns a connected client that authenticates with a throwaway key.
func (s *Server) Client() *xssh.Client {
	s.t.Helper()
	_, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		s.t.Fatal(err)
	}
	signer, err := xssh.NewSignerFromKey(priv)
	if err != nil {
		s.t.Fatal(err)
	}
	client, err := s.Dial("tcp", "sshtest:22", &xssh.ClientConfig{
		User:            "root",
		Auth:            []xssh.AuthMethod{xssh.PublicKeys(signer)},
		HostKeyCallback: xssh.InsecureIgnoreHostKey(), //nolint:gosec // in-process test server
	})
	if err != nil {
		s.t.Fatal(err)
	}
	s.t.Cleanup(func() { _ = client.Close() })
	return client
}

// ReadFile returns a file written over SFTP, read back the same way.
func (s *Server) ReadFile(path string) (string, error) {
	sc, err := sftp.NewClient(s.Client())
	if err != nil {
		return "", err
	}
	defer sc.Close()
	f, err := sc.Open(path)
	if err != nil {
		return "", err
	}
	defer f.Close()
	var buf bytes.Buffer
	if _, err := io.Copy(&buf, f); err != nil {
		return "", err
	}
	return buf.String(), nil
}

func (s *Server) serve(nc net.Conn) {
	_, chans, reqs, err := xssh.NewServerConn(nc, s.config)
	if err != nil {
		return
	}
	go xssh.DiscardRequests(reqs)
	for nch := range chans {
		if nch.ChannelType() != "session" {
			_ = nch.Reject(xssh.UnknownChannelType, "only sessions")
			continue
		}
		ch, requests, err := nch.Accept()
		if err != nil {
			continue
		}
		go s.session(ch, requests)
	}
}

func (s *Server) session(ch xssh.Channel, requests <-chan *xssh.Request) {
	defer ch.Close()
	for req := range requests {
		switch req.Type {
		case "exec":
			cmd := payloadString(req.Payload)
			_ = req.Reply(true, nil)
			s.mu.Lock()
			s.commands = append(s.commands, cmd)
			s.mu.Unlock()
			res := s.Handle(cmd)
			_, _ = io.WriteString(ch, res.Output)
			status := make([]byte, 4)
			binary.BigEndian.PutUint32(status, res.Exit)
			_, _ = ch.SendRequest("exit-status", false, status)
			return
		case "subsystem":
			if payloadString(req.Payload) != "sftp" {
				_ = req.Reply(false, nil)
				continue
			}
			_ = req.Reply(true, nil)
			handlers := s.files
			handlers.FileCmd = modeRecorder{next: s.files.FileCmd, s: s}
			server := sftp.NewRequestServer(ch, handlers)
			_ = server.Serve()
			_ = server.Close()
			return
		default:
			_ = req.Reply(req.Type == "signal", nil)
		}
	}
}

// modeRecorder notes chmod calls, which the in-memory handler ignores.
type modeRecorder struct {
	next sftp.FileCmder
	s    *Server
}

func (m modeRecorder) Filecmd(r *sftp.Request) error {
	if r.Method == "Setstat" && r.AttrFlags().Permissions {
		m.s.mu.Lock()
		m.s.modes[r.Filepath] = r.Attributes().FileMode().Perm()
		m.s.mu.Unlock()
	}
	return m.next.Filecmd(r)
}

// payloadString decodes an SSH string (uint32 length, then bytes).
func payloadString(p []byte) string {
	if len(p) < 4 {
		return ""
	}
	n := binary.BigEndian.Uint32(p)
	if int(n) > len(p)-4 {
		return ""
	}
	return string(p[4 : 4+n])
}
