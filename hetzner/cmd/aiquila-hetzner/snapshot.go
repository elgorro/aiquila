package main

import (
	"context"
	"fmt"
	"os"
	"text/tabwriter"
	"time"

	hcloudclient "github.com/elgorro/aiquila/hetzner/internal/hcloud"
	"github.com/hetznercloud/hcloud-go/v2/hcloud"
	"github.com/spf13/cobra"
)

var (
	snapCreateName   string
	snapCreateDesc   string
	snapCreateLabels []string
	snapCreateToken  string

	snapListName  string
	snapListToken string

	snapDeleteID    int64
	snapDeleteToken string
)

func buildSnapshotCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "snapshot",
		Short: "Manage server snapshots",
	}

	cmd.AddCommand(buildSnapshotCreateCmd())
	cmd.AddCommand(buildSnapshotListCmd())
	cmd.AddCommand(buildSnapshotDeleteCmd())

	return cmd
}

// ── snapshot create ───────────────────────────────────────────────────────────

func buildSnapshotCreateCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "create",
		Short: "Create a snapshot of a server",
		RunE:  runSnapshotCreate,
	}
	cmd.Flags().StringVar(&snapCreateName, "name", "", "Server name (required)")
	cmd.Flags().StringVar(&snapCreateDesc, "description", "", "Snapshot description (default: auto-generated)")
	cmd.Flags().StringArrayVar(&snapCreateLabels, "label", nil, "Label key=value (repeatable)")
	cmd.Flags().StringVar(&snapCreateToken, "token", "", "Hetzner API token (default: $HCLOUD_TOKEN)")
	_ = cmd.MarkFlagRequired("name")
	return cmd
}

func runSnapshotCreate(_ *cobra.Command, _ []string) error {
	ctx := context.Background()

	client, err := hcloudclient.NewClient(snapCreateToken, globalProfile)
	if err != nil {
		return err
	}

	srv, err := lookupServer(ctx, client, snapCreateName)
	if err != nil {
		return err
	}

	desc := snapCreateDesc
	if desc == "" {
		desc = fmt.Sprintf("aiquila-hetzner snapshot of %s at %s", snapCreateName, time.Now().UTC().Format(time.RFC3339))
	}

	labels, err := parseLabels(snapCreateLabels)
	if err != nil {
		return err
	}

	fmt.Printf("==> Creating snapshot of %q\n", snapCreateName)
	result, _, err := client.Server.CreateImage(ctx, srv, &hcloud.ServerCreateImageOpts{
		Type:        hcloud.ImageTypeSnapshot,
		Description: hcloud.Ptr(desc),
		Labels:      labels,
	})
	if err != nil {
		return fmt.Errorf("create snapshot: %w", err)
	}

	if err := client.Action.WaitForFunc(ctx, nil, result.Action); err != nil {
		return fmt.Errorf("wait for snapshot: %w", err)
	}

	img := result.Image
	fmt.Printf("  Snapshot created: id=%d  description=%q  size=%.1fGB\n", img.ID, img.Description, img.ImageSize)
	return nil
}

// ── snapshot list ─────────────────────────────────────────────────────────────

func buildSnapshotListCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "list",
		Short: "List snapshots (optionally filtered by server name)",
		RunE:  runSnapshotList,
	}
	cmd.Flags().StringVar(&snapListName, "name", "", "Filter snapshots by server name")
	cmd.Flags().StringVar(&snapListToken, "token", "", "Hetzner API token (default: $HCLOUD_TOKEN)")
	return cmd
}

func runSnapshotList(_ *cobra.Command, _ []string) error {
	ctx := context.Background()

	client, err := hcloudclient.NewClient(snapListToken, globalProfile)
	if err != nil {
		return err
	}

	images, err := client.Image.AllWithOpts(ctx, hcloud.ImageListOpts{
		Type: []hcloud.ImageType{hcloud.ImageTypeSnapshot},
	})
	if err != nil {
		return fmt.Errorf("list snapshots: %w", err)
	}

	if snapListName != "" {
		filtered := images[:0]
		for _, img := range images {
			if img.CreatedFrom != nil && img.CreatedFrom.Name == snapListName {
				filtered = append(filtered, img)
			}
		}
		images = filtered
	}

	if len(images) == 0 {
		fmt.Println("No snapshots found.")
		return nil
	}

	w := tabwriter.NewWriter(os.Stdout, 0, 0, 3, ' ', 0)
	fmt.Fprintln(w, "ID\tCREATED_FROM\tDESCRIPTION\tSIZE\tCREATED")
	fmt.Fprintln(w, "──\t────────────\t───────────\t────\t───────")
	for _, img := range images {
		createdFrom := "(unknown)"
		if img.CreatedFrom != nil {
			createdFrom = img.CreatedFrom.Name
		}
		fmt.Fprintf(w, "%d\t%s\t%s\t%.1fGB\t%s\n",
			img.ID,
			createdFrom,
			img.Description,
			img.ImageSize,
			img.Created.Format(time.RFC3339),
		)
	}
	w.Flush()
	return nil
}

// ── snapshot delete ───────────────────────────────────────────────────────────

func buildSnapshotDeleteCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "delete",
		Short: "Delete a snapshot by ID",
		RunE:  runSnapshotDelete,
	}
	cmd.Flags().Int64Var(&snapDeleteID, "id", 0, "Snapshot ID (required)")
	cmd.Flags().StringVar(&snapDeleteToken, "token", "", "Hetzner API token (default: $HCLOUD_TOKEN)")
	_ = cmd.MarkFlagRequired("id")
	return cmd
}

func runSnapshotDelete(_ *cobra.Command, _ []string) error {
	ctx := context.Background()

	client, err := hcloudclient.NewClient(snapDeleteToken, globalProfile)
	if err != nil {
		return err
	}

	img, _, err := client.Image.GetByID(ctx, snapDeleteID)
	if err != nil {
		return fmt.Errorf("look up snapshot id=%d: %w", snapDeleteID, err)
	}
	if img == nil {
		return fmt.Errorf("snapshot id=%d not found", snapDeleteID)
	}

	fmt.Printf("==> Deleting snapshot id=%d  description=%q\n", img.ID, img.Description)
	if _, err := client.Image.Delete(ctx, img); err != nil {
		return fmt.Errorf("delete snapshot id=%d: %w", snapDeleteID, err)
	}
	fmt.Printf("  Deleted snapshot id=%d\n", snapDeleteID)
	return nil
}
