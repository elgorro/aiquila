# aiquila-hetzner — Hetzner Storage Box

← [Overview](README.md)

> Manual steps are provided below for servers that were provisioned without `--storage-box`.

---

## Overview

A [Hetzner Storage Box](https://www.hetzner.com/storage/storage-box/) is a managed
NAS product (separate from Hetzner Cloud) that provides persistent block storage
independent of any Cloud server. Because Storage Boxes survive server deletion and
re-provisioning, they are the recommended way to keep Nextcloud data across rebuilds.

When `--storage-box` is supplied to `aiquila-hetzner create`, the CLI:

1. Retrieves Storage Box details via the Hetzner Cloud API.
2. Enables Samba/CIFS access if it is not already on.
3. Sets the CIFS mount password (auto-generated 24-char string if `--storage-box-password` is omitted).
4. Mounts the share at `/mnt/storagebox` on the server via CIFS.
5. Writes a persistent `/etc/fstab` entry so the share is remounted on reboot.
6. On `nextcloud` and `full` stacks: creates `/mnt/storagebox/nextcloud/` and
   symlinks `/opt/aiquila/data/nc` → `/mnt/storagebox/nextcloud` so Nextcloud data
   lands on the Storage Box automatically.

> **CrowdSec always uses local storage.** The `/opt/crowdsec` directory is always
> on the server's local disk; decision data is not stored on the Storage Box.

---

## Automated provisioning

### Prerequisites

- A Hetzner Storage Box purchased from the [Cloud Console](https://console.hetzner.cloud)
  or [Robot panel](https://robot.hetzner.com).
- A Hetzner Cloud API token (`$HCLOUD_TOKEN`) — the same token used for server provisioning.

### CLI flags

| Flag | Env var | Default | Description |
|------|---------|---------|-------------|
| `--storage-box <id>` | — | — | Storage Box ID (integer) |
| `--storage-box-password <pass>` | `HETZNER_STORAGE_BOX_PASSWORD` | auto-generated | CIFS mount password |

### Example

```bash
aiquila-hetzner create \
  --stack nextcloud \
  --nc-domain nc.example.com \
  --nc-admin-password secret \
  --storage-box 1234567
```

### Config file

```yaml
storage_box: 1234567
# Optional:
storage_box_password: custom-cifs-password   # auto-generated if omitted
```

---

## Manual setup

Use these steps to mount a Storage Box on a server that was already provisioned
**without** `--storage-box`, or to reproduce the setup manually.

### Step 1 — Enable Samba/CIFS

In the [Cloud Console](https://console.hetzner.cloud) or
[Robot panel](https://robot.hetzner.com), navigate to your Storage Box settings
and enable **Samba**. Note the **Server** hostname (e.g. `u123456.your-storagebox.de`)
and **Login** (e.g. `u123456`).

### Step 2 — Set a CIFS password

Set a password for your Storage Box in the Cloud Console or Robot panel.
This is separate from your Hetzner account password.

### Step 3 — Install cifs-utils on the server

```bash
ssh -i ~/.ssh/aiquila_ed25519 root@<server-ip>

# Debian/Ubuntu:
apt-get install -y cifs-utils

# RHEL/Fedora/Rocky:
dnf install -y cifs-utils
```

### Step 4 — Write a credentials file

```bash
cat > /root/.aiquila-storagebox.cred <<EOF
username=u123456
password=YOUR_CIFS_PASSWORD
EOF
chmod 600 /root/.aiquila-storagebox.cred
```

Replace `u123456` with your Storage Box login and `YOUR_CIFS_PASSWORD` with the
password set in Step 2.

### Step 5 — Mount the share

```bash
mkdir -p /mnt/storagebox

mount -t cifs //u123456.your-storagebox.de/backup /mnt/storagebox \
  -o credentials=/root/.aiquila-storagebox.cred,uid=0,gid=0,file_mode=0755,dir_mode=0755,vers=3.0
```

### Step 6 — Make the mount persistent

```bash
echo '//u123456.your-storagebox.de/backup /mnt/storagebox cifs credentials=/root/.aiquila-storagebox.cred,uid=0,gid=0,file_mode=0755,dir_mode=0755,vers=3.0,nofail,_netdev 0 0' \
  >> /etc/fstab
```

`nofail` prevents a boot failure if the Storage Box is temporarily unreachable.
`_netdev` tells systemd to mount only after the network is up.

Verify:

```bash
umount /mnt/storagebox
mount -a
ls /mnt/storagebox
```

### Step 7 — Symlink Nextcloud data (nextcloud/full stacks only)

If Nextcloud is already running with local data, stop it first and decide whether
to migrate the existing data before proceeding.

```bash
cd /opt/aiquila
docker compose down

mkdir -p /mnt/storagebox/nextcloud /opt/aiquila/data
ln -sf /mnt/storagebox/nextcloud /opt/aiquila/data/nc

docker compose up -d
```

> If you have existing Nextcloud data at `/opt/aiquila/data/nc/`, copy it to the
> Storage Box before creating the symlink:
> ```bash
> cp -a /opt/aiquila/data/nc/. /mnt/storagebox/nextcloud/
> rm -rf /opt/aiquila/data/nc
> ln -sf /mnt/storagebox/nextcloud /opt/aiquila/data/nc
> ```

---

## Verifying the mount

```bash
# Check that the share is mounted
mount | grep storagebox

# Check available space
df -h /mnt/storagebox
```

---

## Re-provisioning with an existing Storage Box

When rebuilding or replacing a server, pass the same `--storage-box <id>` to `create`.
The CLI sets a new CIFS password and re-mounts the share. Nextcloud data under
`/mnt/storagebox/nextcloud/` is untouched; Nextcloud will resume from where it left off
after the stack starts.
