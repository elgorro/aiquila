# aiquila-hetzner — Hetzner Storage Box

← [Overview](README.md)

> **Alpha feature.** Storage Box integration works but has not been hardened through
> extensive production use. Test it with non-critical data before relying on it for
> anything important. Manual steps are provided below for servers that were provisioned
> without `--storage-box`.

---

## Overview

A [Hetzner Storage Box](https://www.hetzner.com/storage/storage-box/) is a managed
NAS product (separate from Hetzner Cloud) that provides persistent block storage
independent of any Cloud server. Because Storage Boxes survive server deletion and
re-provisioning, they are the recommended way to keep Nextcloud data across rebuilds.

When `--storage-box` is supplied to `aiquila-hetzner create`, the CLI:

1. Retrieves Storage Box details from the Hetzner Robot API.
2. Enables Samba/CIFS access if it is not already on.
3. Sets the CIFS mount password (auto-generated 24-char string if `--storage-box-password` is omitted).
4. Enables SSH access and uploads an SSH public key labeled `aiquila` (auto-generated
   ed25519 pair if `--storage-box-ssh-key` is omitted).
5. Mounts the share at `/mnt/storagebox` on the server via CIFS.
6. Writes a persistent `/etc/fstab` entry so the share is remounted on reboot.
7. On `nextcloud` and `full` stacks: creates `/mnt/storagebox/nextcloud/` and
   symlinks `/opt/aiquila/data/nc` → `/mnt/storagebox/nextcloud` so Nextcloud data
   lands on the Storage Box automatically.

> **CrowdSec always uses local storage.** The `/opt/crowdsec` directory is always
> on the server's local disk; decision data is not stored on the Storage Box.

---

## Automated provisioning

### Prerequisites

- A Hetzner Storage Box purchased from the [Robot panel](https://robot.hetzner.com).
- Hetzner Robot API credentials (username + password from the Robot panel).

### CLI flags

| Flag | Env var | Default | Description |
|------|---------|---------|-------------|
| `--storage-box <id>` | — | — | Storage Box ID (integer, from Robot panel) |
| `--robot-user <user>` | `HETZNER_ROBOT_USER` | — | Robot API username |
| `--robot-password <pass>` | `HETZNER_ROBOT_PASSWORD` | — | Robot API password |
| `--storage-box-password <pass>` | `HETZNER_STORAGE_BOX_PASSWORD` | auto-generated | CIFS mount password |
| `--storage-box-ssh-key <path>` | — | auto-generated | Path to SSH public key file to add |

### Example

```bash
aiquila-hetzner create \
  --stack nextcloud \
  --nc-domain nc.example.com \
  --nc-admin-password secret \
  --storage-box 1234567 \
  --robot-user robot_XXXXXXXX \
  --robot-password YOUR_ROBOT_PASSWORD
```

### Config file

```yaml
storage_box: 1234567
robot_user: robot_XXXXXXXX
robot_password: YOUR_ROBOT_PASSWORD
# Optional:
storage_box_password: custom-cifs-password   # auto-generated if omitted
storage_box_ssh_key: ~/.ssh/storagebox.pub   # auto-generated ed25519 pair if omitted
```

### Auto-generated SSH key pair

When `--storage-box-ssh-key` is omitted, the CLI generates an ed25519 key pair and
saves it to the current working directory:

```
storagebox-1234567       ← private key (mode 0600)
storagebox-1234567.pub   ← public key
```

The private key can be used to access the Storage Box directly over SFTP:

```bash
sftp -P 23 -i storagebox-1234567 <login>@<host>
```

The login and host are printed in the provisioning summary.

---

## Manual setup

Use these steps to mount a Storage Box on a server that was already provisioned
**without** `--storage-box`, or to reproduce the setup manually.

### Step 1 — Enable Samba/CIFS in the Robot panel

1. Log in to [robot.hetzner.com](https://robot.hetzner.com).
2. Go to **Storage Boxes** → select your box.
3. Under **Settings**, enable **Samba**.
4. Note the **Server** hostname (e.g. `u123456.your-storagebox.de`) and
   **Login** (e.g. `u123456`).

### Step 2 — Set a CIFS password

In the Robot panel, under **Storage Box → Settings**, set a password.
This is separate from your Robot account password.

```bash
# Or via the Robot API:
curl -u robot_XXXXXXXX:ROBOT_PASSWORD \
  -X POST https://robot-ws.your-server.de/storagebox/1234567/password \
  -d "password=YOUR_CIFS_PASSWORD"
```

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

# SFTP access (requires SSH enabled in Robot panel + key uploaded)
sftp -P 23 u123456@u123456.your-storagebox.de
```

---

## Re-provisioning with an existing Storage Box

When rebuilding or replacing a server, pass the same `--storage-box <id>` to `create`.
The CLI sets a new CIFS password and re-mounts the share. Nextcloud data under
`/mnt/storagebox/nextcloud/` is untouched; Nextcloud will resume from where it left off
after the stack starts.
