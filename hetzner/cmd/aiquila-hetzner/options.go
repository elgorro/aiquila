package main

import (
	"context"
	"fmt"
	"os"
	"sort"
	"text/tabwriter"

	hcloudclient "github.com/elgorro/aiquila/hetzner/internal/hcloud"
	"github.com/hetznercloud/hcloud-go/v2/hcloud"
	"github.com/spf13/cobra"
)

var optionsToken string

func buildOptionsCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "options",
		Short: "List available server types, locations, and images from Hetzner",
		RunE:  runOptions,
	}
	cmd.Flags().StringVar(&optionsToken, "token", "", "Hetzner API token (default: $HCLOUD_TOKEN)")
	return cmd
}

func runOptions(_ *cobra.Command, _ []string) error {
	ctx := context.Background()
	client, err := hcloudclient.NewClient(optionsToken, globalProfile)
	if err != nil {
		return err
	}

	// Server types
	serverTypes, err := client.ServerType.All(ctx)
	if err != nil {
		return fmt.Errorf("list server types: %w", err)
	}
	sort.Slice(serverTypes, func(i, j int) bool { return serverTypes[i].Name < serverTypes[j].Name })

	fmt.Println("Server types:")
	w := tabwriter.NewWriter(os.Stdout, 0, 0, 3, ' ', 0)
	fmt.Fprintln(w, "  NAME\tCORES\tRAM\tDISK\tARCH")
	for _, st := range serverTypes {
		fmt.Fprintf(w, "  %s\t%d\t%.0f GB\t%d GB\t%s\n",
			st.Name, st.Cores, st.Memory, st.Disk, st.Architecture)
	}
	w.Flush()

	// Locations
	locations, err := client.Location.All(ctx)
	if err != nil {
		return fmt.Errorf("list locations: %w", err)
	}
	sort.Slice(locations, func(i, j int) bool { return locations[i].Name < locations[j].Name })

	fmt.Println("\nLocations:")
	w = tabwriter.NewWriter(os.Stdout, 0, 0, 3, ' ', 0)
	fmt.Fprintln(w, "  NAME\tCITY\tCOUNTRY")
	for _, loc := range locations {
		fmt.Fprintf(w, "  %s\t%s\t%s\n", loc.Name, loc.City, loc.Country)
	}
	w.Flush()

	// Images (system only)
	images, err := client.Image.AllWithOpts(ctx, hcloud.ImageListOpts{
		Type: []hcloud.ImageType{hcloud.ImageTypeSystem},
	})
	if err != nil {
		return fmt.Errorf("list images: %w", err)
	}
	sort.Slice(images, func(i, j int) bool { return images[i].Name < images[j].Name })

	fmt.Println("\nImages (system):")
	w = tabwriter.NewWriter(os.Stdout, 0, 0, 3, ' ', 0)
	fmt.Fprintln(w, "  NAME\tDESCRIPTION")
	for _, img := range images {
		fmt.Fprintf(w, "  %s\t%s\n", img.Name, img.Description)
	}
	w.Flush()

	return nil
}
