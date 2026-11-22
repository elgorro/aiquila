// SPDX-License-Identifier: MIT

package upgrade

import (
	"fmt"
	"regexp"
	"sort"
	"strings"

	"gopkg.in/yaml.v3"
)

// ImageChange is a service whose image the release's template pins
// differently from the deployed compose file.
type ImageChange struct {
	Service string
	From    string
	To      string
	// Held names why upgrade never applies this change, even with --images;
	// "" when --images may apply it.
	Held string
}

// serviceImageLines maps each service of a compose file to its image and the
// 1-based line the image is on.
func serviceImageLines(compose string) (map[string]struct {
	image string
	line  int
}, error) {
	var doc yaml.Node
	if err := yaml.Unmarshal([]byte(compose), &doc); err != nil {
		return nil, fmt.Errorf("parse docker-compose.yml: %w", err)
	}
	out := map[string]struct {
		image string
		line  int
	}{}
	if len(doc.Content) == 0 {
		return out, nil
	}
	services := mappingValue(doc.Content[0], "services")
	if services == nil || services.Kind != yaml.MappingNode {
		return out, nil
	}
	for i := 0; i+1 < len(services.Content); i += 2 {
		img := mappingValue(services.Content[i+1], "image")
		if img == nil || img.Kind != yaml.ScalarNode {
			continue
		}
		out[services.Content[i].Value] = struct {
			image string
			line  int
		}{img.Value, img.Line}
	}
	return out, nil
}

func mappingValue(n *yaml.Node, key string) *yaml.Node {
	if n == nil || n.Kind != yaml.MappingNode {
		return nil
	}
	for i := 0; i+1 < len(n.Content); i += 2 {
		if n.Content[i].Value == key {
			return n.Content[i+1]
		}
	}
	return nil
}

// ServiceImages maps each service of a compose file to the image it names.
func ServiceImages(compose string) (map[string]string, error) {
	m, err := serviceImageLines(compose)
	if err != nil {
		return nil, err
	}
	out := make(map[string]string, len(m))
	for s, v := range m {
		out[s] = v.image
	}
	return out, nil
}

// ImageDrift lists the services both files define whose image differs, by
// service name. The MCP image is left out: upgrade pins it to the target
// release on its own.
func ImageDrift(oldCompose, newCompose string) ([]ImageChange, error) {
	from, err := ServiceImages(oldCompose)
	if err != nil {
		return nil, err
	}
	to, err := ServiceImages(newCompose)
	if err != nil {
		return nil, err
	}
	var out []ImageChange
	for svc, f := range from {
		t, ok := to[svc]
		if !ok || t == f || imageRepo(f) == mcpImage || imageRepo(t) == mcpImage {
			continue
		}
		out = append(out, ImageChange{Service: svc, From: f, To: t, Held: held(f, t)})
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Service < out[j].Service })
	return out, nil
}

// held says why a change is never applied by upgrade. Nextcloud core moves
// through its own upgrade (occ upgrade, one major at a time), and a
// PostgreSQL major needs a dump and restore: a new major refuses an older
// data directory.
func held(from, to string) string {
	fr, tr := imageRepo(from), imageRepo(to)
	switch {
	case fr == "nextcloud" || tr == "nextcloud":
		return "Nextcloud core is upgraded on its own"
	case fr == "postgres" || tr == "postgres":
		if fr != tr {
			return "the database image changes"
		}
		fm, tm := majorOf(imageTag(from)), majorOf(imageTag(to))
		if fm == "" || tm == "" || fm != tm {
			return "a PostgreSQL major upgrade needs a dump and restore"
		}
	}
	return ""
}

// imageRepo is an image reference without its tag or digest.
func imageRepo(image string) string {
	if i := strings.Index(image, "@"); i >= 0 {
		image = image[:i]
	}
	if i := strings.LastIndex(image, ":"); i > strings.LastIndex(image, "/") {
		image = image[:i]
	}
	return image
}

// imageTag is the tag of an image reference, "latest" when it has none.
func imageTag(image string) string {
	if i := strings.Index(image, "@"); i >= 0 {
		image = image[:i]
	}
	if i := strings.LastIndex(image, ":"); i > strings.LastIndex(image, "/") {
		return image[i+1:]
	}
	return "latest"
}

var majorRe = regexp.MustCompile(`^v?(\d+)(?:[.-]|$)`)

// majorOf is the leading number of a version tag ("18.7-alpine" → "18"), or
// "" for a tag that does not start with one.
func majorOf(tag string) string {
	if m := majorRe.FindStringSubmatch(tag); m != nil {
		return m[1]
	}
	return ""
}

var imageLineRe = regexp.MustCompile(`^(\s*image:\s*)\S+(.*)$`)

// PinImages rewrites the image of each named service in compose, keeping the
// rest of the file (comments included) as it is. A service compose does not
// define is an error.
func PinImages(compose string, images map[string]string) (string, error) {
	if len(images) == 0 {
		return compose, nil
	}
	lines, err := serviceImageLines(compose)
	if err != nil {
		return "", err
	}
	text := strings.Split(compose, "\n")
	for svc, img := range images {
		l, ok := lines[svc]
		if !ok || l.line < 1 || l.line > len(text) || !imageLineRe.MatchString(text[l.line-1]) {
			return "", fmt.Errorf("docker-compose.yml: no image line for service %q", svc)
		}
		text[l.line-1] = imageLineRe.ReplaceAllString(text[l.line-1], "${1}"+strings.ReplaceAll(img, "$", "$$")+"${2}")
	}
	return strings.Join(text, "\n"), nil
}
