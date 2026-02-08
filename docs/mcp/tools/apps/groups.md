# Nextcloud Group Management Tools

Manage Nextcloud groups and group memberships via OCC commands. Essential for organizing users into teams and departments.

## Prerequisites

- SSH or Docker exec access to Nextcloud server
- Administrator privileges to execute OCC commands

## Overview

Groups in Nextcloud allow you to organize users and assign permissions collectively. Users can belong to multiple groups, and groups can have shared folders and resources.

## Available Tools

### list_groups

List all groups in the Nextcloud instance.

**Parameters:**
None

**Returns:**
OCC command instructions to list all groups with their members.

**Example Usage:**
```
Ask Claude: "List all Nextcloud groups"
Ask Claude: "Show me all groups and their members"
Ask Claude: "What groups exist?"
```

**Example Command:**
```bash
docker exec -u www-data aiquila-nextcloud php occ group:list
```

**Example Output:**
```
- admin: alice, bob
- users: alice, bob, charlie, diana
- marketing: charlie, diana
- developers: alice, bob
```

---

### get_group_info

Get detailed information about a specific Nextcloud group.

**Parameters:**
- `groupId` (string, required): The group ID (name)

**Returns:**
OCC command instructions to display group members.

**Example Usage:**
```
Ask Claude: "Get info about the admin group"
Ask Claude: "Show me who's in the developers group"
Ask Claude: "List members of marketing group"
```

**Example Command:**
```bash
docker exec -u www-data aiquila-nextcloud php occ group:list admin
```

**Example Output:**
```
- admin: alice, bob, charlie
```

---

### add_user_to_group

Add a Nextcloud user to a group.

**Parameters:**
- `userId` (string, required): The user ID (login name) to add
- `groupId` (string, required): The group ID (name) to add the user to

**Returns:**
OCC command instructions to add user to group, including what permissions they'll inherit.

**Example Usage:**
```
Ask Claude: "Add alice to the admin group"
Ask Claude: "Put bob in the developers group"
Ask Claude: "Add user charlie to marketing"
```

**Example Command:**
```bash
docker exec -u www-data aiquila-nextcloud php occ group:adduser developers alice
```

**What This Does:**
- Adds user to the specified group
- User inherits group permissions
- User gains access to group folders and resources
- User can see shared items from group members

**Important:**
- Adding to `admin` group grants administrator privileges
- User can be in multiple groups simultaneously
- If group doesn't exist, create it first with `group:add`

---

### remove_user_from_group

Remove a Nextcloud user from a group.

**Parameters:**
- `userId` (string, required): The user ID (login name) to remove
- `groupId` (string, required): The group ID (name) to remove the user from

**Returns:**
OCC command instructions to remove user from group with warnings about permission loss.

**Example Usage:**
```
Ask Claude: "Remove alice from the developers group"
Ask Claude: "Take bob out of the admin group"
Ask Claude: "Remove user charlie from marketing"
```

**Example Command:**
```bash
docker exec -u www-data aiquila-nextcloud php occ group:removeuser marketing charlie
```

**What This Does:**
- Removes user from the specified group
- User loses group permissions
- User may lose access to group folders and resources
- Shared items from this group may become inaccessible

**Warnings:**
- ⚠️  Removing from `admin` removes administrator privileges
- ⚠️  Cannot remove the last admin from `admin` group
- ⚠️  Group folder access will be revoked
- ⚠️  Does not delete the user or the group

---

## Common Group Types

### Built-in Groups

- **admin** - Administrators with full system access
- **users** - Default group for all regular users

### Custom Groups

You can create custom groups for:
- Departments (marketing, sales, engineering)
- Projects (project-alpha, website-redesign)
- Teams (management, contractors, interns)
- Locations (office-nyc, office-london)

## Common Workflows

### Creating a Team
```
1. Create group: php occ group:add team-name
2. Add users: Use add_user_to_group for each member
3. Set up group folder (via web UI)
4. Configure group permissions
```

### Onboarding to Team
```
1. Use get_user_info to verify user exists
2. Use add_user_to_group to add to appropriate groups
3. User immediately gains group access
```

### Offboarding from Team
```
1. Use remove_user_from_group for each group
2. Optionally disable_user if leaving organization
3. Transfer ownership of personal files if needed
```

### Audit Group Memberships
```
1. Use list_groups to see all groups
2. For each critical group, verify members are correct
3. Remove unauthorized users
```

## Limitations

### Current Capabilities
- ✅ List all groups
- ✅ View group membership
- ✅ Add users to groups
- ✅ Remove users from groups

### Not Yet Supported
- ❌ Create new groups (use web UI or direct OCC)
- ❌ Delete groups (use web UI or direct OCC)
- ❌ Rename groups (use web UI or direct OCC)
- ❌ Configure group folders (use web UI)
- ❌ Set group quotas (use web UI)

For these operations, use the Nextcloud web interface or run OCC commands directly.

## Security Considerations

- Group operations require administrator privileges
- Be careful when adding users to `admin` group
- Never remove the last administrator
- Audit group memberships regularly
- Use descriptive group names for clarity

## Troubleshooting

### Group not found
**Problem**: "Group does not exist" error

**Solution**:
- Verify group name spelling (case-sensitive)
- Use list_groups to see all available groups
- Create group first: `php occ group:add groupname`

---

### Cannot remove last admin
**Problem**: "Cannot remove last admin from admin group"

**Solution**:
- Add another user to admin group first
- Ensure there are always multiple administrators
- Use add_user_to_group to promote another user

---

### User already in group
**Problem**: User is already a member

**Solution**:
- This is informational, not an error
- Use get_group_info to verify current membership
- No action needed if user should be in group

## Integration with Other Tools

### With User Management
```
User: "Add alice to admin group and verify her permissions"
Claude: Uses add_user_to_group then get_user_info to verify

User: "Show me all admin users"
Claude: Uses get_group_info for admin group
```

### With System Status
```
User: "List all groups and check if system is healthy"
Claude: Uses list_groups and system_status together
```

## Best Practices

1. **Naming Convention**: Use descriptive, lowercase names (e.g., `sales-team`, `project-alpha`)
2. **Regular Audits**: Review group memberships quarterly
3. **Least Privilege**: Only add users to groups they need
4. **Documentation**: Document group purposes and membership criteria
5. **Multiple Admins**: Always maintain at least 2-3 administrators

## Development

To extend group management tools:
- See [Adding Tools Guide](../../development/adding-tools.md)
- Source code: [mcp-server/src/tools/apps/groups.ts](../../../../mcp-server/src/tools/apps/groups.ts)

## References

- [Nextcloud Groups Documentation](https://docs.nextcloud.com/server/latest/admin_manual/configuration_user/user_auth_ldap.html#group-management)
- [OCC Group Commands](https://docs.nextcloud.com/server/latest/admin_manual/configuration_server/occ_command.html#group-commands)
- [Group Folders App](https://apps.nextcloud.com/apps/groupfolders)
