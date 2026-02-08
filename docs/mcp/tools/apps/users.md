# Nextcloud User Management Tools

Manage Nextcloud user accounts via OCC commands. These tools provide essential user administration capabilities.

## Prerequisites

- SSH or Docker exec access to Nextcloud server
- Administrator privileges to execute OCC commands

## Available Tools

### list_users

List all users in the Nextcloud instance.

**Parameters:**
- `showDisabled` (boolean, optional): Include disabled users in the list (default: false)

**Returns:**
OCC command instructions to list all users with their display names and email addresses.

**Example Usage:**
```
Ask Claude: "List all Nextcloud users"
Ask Claude: "Show me all users including disabled ones"
Ask Claude: "Who are the users on my Nextcloud?"
```

**Example Command:**
```bash
docker exec -u www-data aiquila-nextcloud php occ user:list
```

**Example Output:**
```
- alice: Alice Smith (alice@example.com)
- bob: Bob Jones (bob@example.com)
- admin: Administrator (admin@example.com)
```

---

### get_user_info

Get detailed information about a specific Nextcloud user.

**Parameters:**
- `userId` (string, required): The user ID (login name)

**Returns:**
OCC command instructions to display detailed user information including groups, quota, last login, and account status.

**Example Usage:**
```
Ask Claude: "Get info about user alice"
Ask Claude: "Show me details for user bob"
Ask Claude: "What groups is alice in?"
```

**Example Command:**
```bash
docker exec -u www-data aiquila-nextcloud php occ user:info alice
```

**Example Output:**
```
  - user_id: alice
  - display_name: Alice Smith
  - email: alice@example.com
  - groups: admin, users
  - quota: 10 GB
  - last_seen: 2024-02-07 10:30:00
  - enabled: true
  - backend: Database
```

---

### enable_user

Enable a disabled Nextcloud user account.

**Parameters:**
- `userId` (string, required): The user ID (login name) to enable

**Returns:**
OCC command instructions to re-enable the user account.

**Example Usage:**
```
Ask Claude: "Enable user alice"
Ask Claude: "Re-enable the account for bob"
Ask Claude: "Turn on user alice's access"
```

**Example Command:**
```bash
docker exec -u www-data aiquila-nextcloud php occ user:enable alice
```

**What This Does:**
- Re-enables the user account
- User can log in again
- User's files and data remain intact
- User regains access to all their resources

---

### disable_user

Disable a Nextcloud user account (prevents login but preserves data).

**Parameters:**
- `userId` (string, required): The user ID (login name) to disable

**Returns:**
OCC command instructions to disable the user account with warnings about the operation.

**Example Usage:**
```
Ask Claude: "Disable user alice"
Ask Claude: "Prevent bob from logging in"
Ask Claude: "Turn off access for user alice"
```

**Example Command:**
```bash
docker exec -u www-data aiquila-nextcloud php occ user:disable alice
```

**What This Does:**
- Prevents the user from logging in
- User's files and data are preserved
- User's shares remain active
- Can be reversed with `user:enable`

**Does NOT:**
- Delete the user's files
- Remove the user's account
- Delete the user's shares

**Important:**
- Cannot disable admin users while they are the only admin
- Use `user:delete` for permanent deletion

---

## Common Workflows

### Onboarding New User
```
1. User creates account (via web UI or user:add OCC)
2. Use get_user_info to verify account
3. Use add_user_to_group to assign to appropriate groups
```

### Offboarding User
```
1. Use disable_user to prevent login
2. Transfer ownership of files if needed
3. Optionally use user:delete for permanent removal
```

### User Troubleshooting
```
1. Use get_user_info to check account status
2. Check if user is enabled
3. Verify group memberships
4. Check quota and last login
```

## Limitations

### Current Capabilities
- ✅ List users
- ✅ Get user information
- ✅ Enable/disable users
- ✅ Works with all user backends (Database, LDAP, etc.)

### Not Yet Supported
- ❌ Create new users (use web UI or direct OCC)
- ❌ Delete users (use web UI or direct OCC)
- ❌ Reset passwords (use web UI or direct OCC)
- ❌ Modify user quota (use web UI or direct OCC)
- ❌ Change display name (use web UI or direct OCC)

For these operations, use the Nextcloud web interface or run OCC commands directly.

## Security Considerations

- User operations require administrator privileges
- Disabling a user is reversible; deletion is permanent
- Never disable the last administrator account
- Use app passwords for API access
- Audit user management operations regularly

## Troubleshooting

### User not found
**Problem**: "User does not exist" error

**Solution**:
- Verify user ID spelling (case-sensitive)
- Use list_users to see all available users
- Check if user exists in external backend (LDAP, etc.)

---

### Cannot disable admin
**Problem**: Cannot disable admin user

**Solution**:
- Ensure there are multiple admins before disabling one
- Create another admin user first
- Use add_user_to_group to add another user to admin group

---

### Permission denied
**Problem**: "Insufficient permissions" error

**Solution**:
- Ensure running as www-data user
- Verify you have administrator privileges
- Check OCC is accessible from command line

## Integration with Other Tools

### With Group Management
```
User: "Get info about user alice and show which groups she's in"
Claude: Uses get_user_info then references group membership

User: "Disable user bob and remove him from all groups"
Claude: Uses disable_user then uses remove_user_from_group for each group
```

### With System Status
```
User: "How many users are on the system?"
Claude: Uses list_users and counts results, or suggests system_status for summary
```

## Development

To extend user management tools:
- See [Adding Tools Guide](../../development/adding-tools.md)
- Source code: [mcp-server/src/tools/apps/users.ts](../../../../mcp-server/src/tools/apps/users.ts)

## References

- [Nextcloud User Management Documentation](https://docs.nextcloud.com/server/latest/admin_manual/configuration_user/)
- [OCC User Commands](https://docs.nextcloud.com/server/latest/admin_manual/configuration_server/occ_command.html#user-commands)
- [User Provisioning API](https://docs.nextcloud.com/server/latest/admin_manual/configuration_user/user_provisioning_api.html)
