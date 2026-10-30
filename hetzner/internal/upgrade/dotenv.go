// SPDX-License-Identifier: MIT

package upgrade

import (
	"strings"
)

// EnvFile is a .env kept line by line, so that rewriting it preserves the
// operator's comments, order and any keys this CLI does not know.
type EnvFile struct {
	lines []string
}

// ParseEnv reads a .env file.
func ParseEnv(s string) *EnvFile {
	s = strings.ReplaceAll(s, "\r\n", "\n")
	s = strings.TrimSuffix(s, "\n")
	if s == "" {
		return &EnvFile{}
	}
	return &EnvFile{lines: strings.Split(s, "\n")}
}

func keyOf(line string) (string, string, bool) {
	t := strings.TrimSpace(line)
	if t == "" || strings.HasPrefix(t, "#") {
		return "", "", false
	}
	t = strings.TrimPrefix(t, "export ")
	k, v, ok := strings.Cut(t, "=")
	if !ok {
		return "", "", false
	}
	return strings.TrimSpace(k), strings.Trim(strings.TrimSpace(v), `"'`), true
}

// Get returns the value of key, or "" when it is absent or empty. The last
// assignment wins, as in Compose.
func (f *EnvFile) Get(key string) string {
	val := ""
	for _, l := range f.lines {
		if k, v, ok := keyOf(l); ok && k == key {
			val = v
		}
	}
	return val
}

// Keys lists the assigned keys in file order.
func (f *EnvFile) Keys() []string {
	var out []string
	seen := map[string]bool{}
	for _, l := range f.lines {
		if k, _, ok := keyOf(l); ok && !seen[k] {
			seen[k] = true
			out = append(out, k)
		}
	}
	return out
}

// Set assigns key. An existing assignment is replaced in place; a new key is
// appended after a blank line and the comment, if any.
func (f *EnvFile) Set(key, value, comment string) {
	for i, l := range f.lines {
		if k, _, ok := keyOf(l); ok && k == key {
			f.lines[i] = key + "=" + value
			return
		}
	}
	if len(f.lines) > 0 && strings.TrimSpace(f.lines[len(f.lines)-1]) != "" {
		f.lines = append(f.lines, "")
	}
	for _, c := range strings.Split(comment, "\n") {
		if c = strings.TrimPrefix(c, "# "); c != "" {
			f.lines = append(f.lines, "# "+c)
		}
	}
	f.lines = append(f.lines, key+"="+value)
}

// String renders the file with a trailing newline.
func (f *EnvFile) String() string {
	if len(f.lines) == 0 {
		return ""
	}
	return strings.Join(f.lines, "\n") + "\n"
}
