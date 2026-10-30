package main

import "testing"

func TestParseAppPassword(t *testing.T) {
	const tok = "t1Caj7dieRv21JcvbAo6yBHOmjIczxsmUSN7rulDFCnbovcxqdafC5bBBudgyP7uredA5Mds"
	cases := []struct {
		name, out, want string
		ok              bool
	}{
		{"plain", "app password:\n" + tok + "\n", tok, true},
		{"after account creation and warnings",
			"Fontconfig error: No writable cache directories\n" +
				"The account \"aiquila-mcp\" was created successfully\n" +
				"Display name set to \"AIquila MCP\"\napp password:\n" + tok + "\n", tok, true},
		{"CRLF", "app password:\r\n" + tok + "\r\n", tok, true},
		{"error only", "Account does not exist\n", "", false},
		{"empty", "", "", false},
	}
	for _, c := range cases {
		got, ok := parseAppPassword(c.out)
		if got != c.want || ok != c.ok {
			t.Errorf("%s: parseAppPassword() = %q, %v; want %q, %v", c.name, got, ok, c.want, c.ok)
		}
	}
}
