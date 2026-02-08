# Nextcloud Share Management Tools

List and audit file shares in Nextcloud for diagnostics and security purposes.

## Prerequisites

- SSH or Docker exec access to Nextcloud server
- Administrator privileges to execute OCC commands

## Overview

These tools provide read-only access to view file shares across your Nextcloud instance. Use them for security auditing, troubleshooting access issues, and reviewing sharing permissions.

## Available Tools

### list_shares

List all file shares in Nextcloud.

**Parameters:**
- `userId` (string, optional): Filter shares by specific user

**Returns:**
OCC command instructions to list all shares with details about share types, permissions, and ownership.

**Example Usage:**
```
Ask Claude: "List all file shares"
Ask Claude: "Show me shares for user alice"
Ask Claude: "What files are being shared?"
Ask Claude: "Audit all public link shares"
```

**Example Command:**
```bash
docker exec -u www-data aiquila-nextcloud php occ sharing:list
```

**With User Filter:**
```bash
docker exec -u www-data aiquila-nextcloud php occ sharing:list --user=alice
```

**Example Output:**
```json
{
  "shares": [
    {
      "id": "123",
      "share_type": 0,
      "share_with": "bob",
      "share_with_displayname": "Bob Jones",
      "path": "/Documents/project.pdf",
      "permissions": 19,
      "stime": 1707312000,
      "uid_owner": "alice",
      "displayname_owner": "Alice Smith"
    },
    {
      "id": "124",
      "share_type": 3,
      "share_with": "https://cloud.example.com/s/AbCd123",
      "path": "/Photos/vacation.jpg",
      "permissions": 1,
      "stime": 1707312100,
      "uid_owner": "alice",
      "token": "AbCd123",
      "expiration": "2024-03-01"
    }
  ]
}
```

---

## Understanding Share Data

### Share Types

| Type | Description | Use Case |
|------|-------------|----------|
| `0` | User share | Shared with specific Nextcloud user |
| `1` | Group share | Shared with a group |
| `3` | Public link | Shareable URL (anyone with link) |
| `4` | Email share | Sent via email |
| `6` | Federated | Shared with remote Nextcloud server |

### Permissions (Bitwise)

| Value | Permission | Description |
|-------|------------|-------------|
| `1` | Read | Can view/download |
| `2` | Update | Can modify existing files |
| `4` | Create | Can create new files |
| `8` | Delete | Can delete files |
| `16` | Share | Can re-share with others |
| `19` | Read+Update+Share | Common for collaboration |
| `31` | All permissions | Full access |

**Calculating Permissions:**
Permissions are bitwise OR combinations:
- Read only: `1`
- Read + Share: `1 + 16 = 17`
- Read + Update + Share: `1 + 2 + 16 = 19`
- Full permissions: `1 + 2 + 4 + 8 + 16 = 31`

---

## Common Use Cases

### Security Audit

**Find all public links:**
```bash
php occ sharing:list | jq '.shares[] | select(.share_type == 3)'
```

**Check for shares without expiration:**
```bash
php occ sharing:list | jq '.shares[] | select(.share_type == 3 and .expiration == null)'
```

**Review high-permission shares:**
```bash
php occ sharing:list | jq '.shares[] | select(.permissions >= 19)'
```

### Troubleshooting

**User can't access file:**
1. List shares for that user: `--user=username`
2. Check `permissions` value
3. Verify `share_type` is correct
4. Check if share has expired

**Public link not working:**
1. Find link by path or token
2. Check `expiration` date
3. Verify `token` matches URL
4. Check if owner account is active

### Access Review

**Monthly audit checklist:**
- [ ] Review all public links (`share_type: 3`)
- [ ] Check for expiration dates
- [ ] Verify permission levels are appropriate
- [ ] Remove unnecessary shares
- [ ] Document business-critical shares

---

## Share Information Fields

### Core Fields

- **id**: Unique share identifier
- **share_type**: Type of share (see table above)
- **path**: File/folder path being shared
- **permissions**: Access level (see table above)
- **stime**: Share creation timestamp
- **uid_owner**: User ID who created the share
- **displayname_owner**: Owner's display name

### Type-Specific Fields

**User Shares (`type: 0`):**
- `share_with`: Recipient user ID
- `share_with_displayname`: Recipient's name

**Group Shares (`type: 1`):**
- `share_with`: Group name
- `share_with_displayname`: Group display name

**Public Links (`type: 3`):**
- `token`: URL token (e.g., `AbCd123`)
- `url`: Full share URL
- `expiration`: Expiration date (if set)
- `password`: Whether password protected (boolean)

**Federated Shares (`type: 6`):**
- `share_with`: Remote user address
- `remote`: Remote server URL

---

## Limitations

### Current Capabilities
- ✅ View all shares
- ✅ Filter by user
- ✅ See share types and permissions
- ✅ Identify public links
- ✅ Check expiration dates

### Not Supported
- ❌ Create new shares (use web UI or WebDAV)
- ❌ Modify share permissions (use web UI)
- ❌ Delete shares (use `php occ sharing:delete <id>`)
- ❌ Change share passwords (use web UI)
- ❌ Set expiration dates (use web UI)

For these operations, use the Nextcloud web interface or direct OCC commands.

---

## Security Considerations

### Privacy
- Share list may contain sensitive file paths
- Limit access to share information
- Only run as administrator

### Best Practices
1. **Regular Audits**: Review shares monthly
2. **Expiration Dates**: Set expirations on public links
3. **Least Privilege**: Use minimum required permissions
4. **Password Protection**: Enable for sensitive public links
5. **Documentation**: Document business-critical shares

### Warning Signs
- ⚠️  Public links without expiration
- ⚠️  Full permissions (31) on public links
- ⚠️  Shares to disabled accounts
- ⚠️  Very old shares (check `stime`)
- ⚠️  Unexpected federated shares

---

## Related Commands

### Managing Shares

**Delete a share:**
```bash
docker exec -u www-data aiquila-nextcloud php occ sharing:delete <share-id>
```

**Clean up expired shares:**
```bash
docker exec -u www-data aiquila-nextcloud php occ sharing:cleanup-remote-storages
```

**List external storages:**
```bash
docker exec -u www-data aiquila-nextcloud php occ files_external:list
```

### Creating Shares

Shares are typically created through:
- **Web UI**: Files → Share icon
- **WebDAV**: With sharing headers
- **API**: Nextcloud sharing API

---

## Troubleshooting

### No shares returned
**Problem**: Empty share list

**Solution**:
- Verify shares exist (check web UI)
- Confirm running as correct user
- Try without user filter
- Check sharing is enabled

### Permission denied
**Problem**: Cannot run command

**Solution**:
- Ensure running as www-data user
- Verify administrator privileges
- Check OCC is accessible

### Large output
**Problem**: Too many shares to review

**Solution**:
- Use `--user` filter for specific users
- Pipe to `jq` for filtering
- Export to file for analysis
- Focus on specific share types

---

## Integration with Other Tools

### With User Management
```
User: "List all shares for user alice"
Claude: Uses list_shares with userId filter
```

### With Security Tools
```
User: "Audit system security including file shares"
Claude: Combines check_core_integrity and list_shares
```

## Development

To extend share management tools:
- See [Adding Tools Guide](../../development/adding-tools.md)
- Source code: [mcp-server/src/tools/apps/shares.ts](../../../../mcp-server/src/tools/apps/shares.ts)

## References

- [Nextcloud Sharing Documentation](https://docs.nextcloud.com/server/latest/user_manual/en/files/sharing.html)
- [OCC Sharing Commands](https://docs.nextcloud.com/server/latest/admin_manual/configuration_server/occ_command.html#sharing-commands)
- [Sharing API](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/OCS/ocs-share-api.html)
