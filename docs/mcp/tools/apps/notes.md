# Nextcloud Notes Tools

Integration with Nextcloud Notes app. Create notes as markdown files in the `/Notes` folder.

## Prerequisites

- Nextcloud Notes app installed (optional but recommended)
- `/Notes` folder will be created automatically if it doesn't exist

## Overview

The Notes tool creates note files in markdown format that are compatible with the Nextcloud Notes app. Notes are stored as `.md` files and can be accessed through the Notes app or directly as files.

## Available Tools

### create_note

Create a note in Nextcloud Notes.

**Parameters:**
- `title` (string, required): The note title
- `content` (string, required): The note content (supports markdown)

**Returns:**
Success message with the file path where the note was saved.

**Example Usage:**
```
Ask Claude: "Create a note called 'Meeting Notes' with today's agenda"
Ask Claude: "Make a note titled 'Ideas' with my project brainstorm"
Ask Claude: "Save this as a note: [paste content]"
```

**Simple Example:**
```json
{
  "title": "Shopping List",
  "content": "Groceries to buy:\n- Milk\n- Eggs\n- Bread\n- Butter"
}
```

**Markdown Example:**
```json
{
  "title": "Project Plan",
  "content": "## Phase 1: Research\n\n- Analyze requirements\n- Review existing solutions\n\n## Phase 2: Development\n\n- Build prototype\n- Gather feedback\n\n## Phase 3: Launch\n\n- Deploy to production\n- Monitor performance"
}
```

---

## Note File Format

Notes are saved as markdown files in `/Notes/{title}.md` with this structure:

```markdown
# Note Title

Note content goes here...
```

The title is automatically added as an H1 header, followed by the content.

## File Naming

Note file names are generated from the title:
- Spaces and punctuation are preserved
- File name matches the note title

**Examples:**
- "Meeting Notes" → `/Notes/Meeting Notes.md`
- "2024 Goals" → `/Notes/2024 Goals.md`
- "Quick Ideas" → `/Notes/Quick Ideas.md`

## Markdown Support

Notes fully support markdown syntax:

### Headers
```markdown
# H1 Header
## H2 Header
### H3 Header
```

### Lists
```markdown
- Unordered item 1
- Unordered item 2

1. Ordered item 1
2. Ordered item 2
```

### Emphasis
```markdown
**Bold text**
*Italic text*
~~Strikethrough~~
```

### Links
```markdown
[Link text](https://example.com)
```

### Code
```markdown
Inline `code`

```
Code block
```
```

### Checkboxes
```markdown
- [ ] Incomplete task
- [x] Completed task
```

## Organizing Notes

### Folders
Organize notes into subdirectories:

```
Ask Claude: "Create a folder /Notes/Work"
Ask Claude: "Create a note in /Notes/Work/ about the project meeting"
```

### Categories
Use note titles with prefixes:

- `Work: Project Status`
- `Personal: Vacation Planning`
- `Research: AI Tools Comparison`

## Integration with Nextcloud Notes App

If you have the Nextcloud Notes app installed:

1. **Automatic Sync**: Notes appear immediately in the app
2. **Mobile Access**: Use Notes mobile apps on iOS/Android
3. **Rich Editing**: Use the Notes app editor for formatting
4. **Favorites**: Mark important notes as favorites
5. **Categories**: Organize notes by category

Even without the Notes app:
- Notes are accessible as markdown files
- Use Nextcloud Files to view/edit
- Compatible with any markdown editor
- Sync via WebDAV to local editors

## Workflow Examples

### Quick Note
```
User: "Make a note: Remember to call Sarah tomorrow"
Claude: Creates note "Remember to call Sarah tomorrow" with that content
```

### Meeting Notes
```
User: "Create meeting notes for today's standup with these topics: progress updates, blockers, next steps"
Claude: Creates formatted note with sections for each topic
```

### Knowledge Base
```
User: "Create a note documenting our API authentication flow"
Claude: Creates detailed technical note with code examples and explanations
```

### Daily Journal
```
User: "Create a note called 'Journal - Jan 15 2024' with today's reflections"
Claude: Creates dated journal entry
```

## Editing Notes

To modify an existing note:

1. **Read the note**: `read_file /Notes/Note Title.md`
2. **Update content**: Have Claude modify the text
3. **Write back**: `write_file /Notes/Note Title.md` with new content

Example:
```
User: "Add a bullet point to my shopping list note"
Claude: Reads /Notes/Shopping List.md, adds item, writes back
```

## Templates

Create note templates for common use cases:

### Meeting Template
```markdown
# Meeting: [Topic] - [Date]

## Attendees
-

## Agenda
1.

## Discussion Points
-

## Action Items
- [ ]

## Next Meeting
Date:
```

### Daily Journal Template
```markdown
# Journal - [Date]

## Highlights
-

## Challenges
-

## Learnings
-

## Tomorrow's Focus
-
```

### Project Note Template
```markdown
# Project: [Name]

## Overview
Brief description

## Goals
-

## Tasks
- [ ]
- [ ]

## Resources
-

## Notes
```

## Limitations

### Current Capabilities
- ✅ Create notes
- ✅ Full markdown support
- ✅ Organize in folders
- ✅ Unicode support

### Not Yet Supported
- ❌ Note tags/categories (via Notes app)
- ❌ Note favorites
- ❌ Note sharing
- ❌ Attachments/images
- ❌ Real-time collaboration

For these features, use the Nextcloud Notes web interface or mobile apps.

## Troubleshooting

### Note not appearing in Notes app
**Problem**: Created note file but doesn't show in Notes app

**Solution**:
- Verify file is in `/Notes` folder
- Check file has `.md` extension
- Refresh the Notes app
- Ensure Notes app is installed and enabled

---

### Special characters in title
**Problem**: Note title contains special characters

**Solution**:
- Most special characters are supported
- Avoid filesystem reserved characters: `/` `\` `:` `*` `?` `"` `<` `>` `|`

---

### Formatting not rendering
**Problem**: Markdown not displaying correctly

**Solution**:
- Check markdown syntax is correct
- Ensure proper line breaks (two spaces or blank line)
- Use Notes app preview to verify formatting

## Integration with Other Tools

### With File System Tools
```
User: "List all my notes"
Claude: Uses list_files on /Notes folder

User: "Read my project ideas note"
Claude: Uses read_file on /Notes/Project Ideas.md
```

### With Tasks Tool
```
User: "Create tasks from the action items in my meeting note"
Claude: Reads note, extracts action items, creates tasks
```

### With Cookbook Tool
```
User: "Convert my recipe note to a proper cookbook entry"
Claude: Reads note, reformats as recipe, saves to /Recipes
```

## Best Practices

1. **Use descriptive titles**: "Project Meeting Notes - 2024-01-15" vs "Notes"
2. **Consistent formatting**: Use markdown headers for structure
3. **Regular organization**: Review and categorize notes periodically
4. **Link related notes**: Reference other notes by filename
5. **Date important notes**: Include dates in titles or content

## Security

- Notes are stored with your Nextcloud user permissions
- Files are created via WebDAV with HTTPS encryption
- Use app passwords for better security
- Notes are private unless explicitly shared

## Development

To extend notes tools:
- See [Adding Tools Guide](../../development/adding-tools.md)
- Source code: [mcp-server/src/tools/apps/notes.ts](../../../../mcp-server/src/tools/apps/notes.ts)
- Uses WebDAV client: [mcp-server/src/client/webdav.ts](../../../../mcp-server/src/client/webdav.ts)

## References

- [Nextcloud Notes App](https://apps.nextcloud.com/apps/notes)
- [Notes Documentation](https://github.com/nextcloud/notes)
- [Markdown Guide](https://www.markdownguide.org/)
- [Notes iOS App](https://apps.apple.com/app/nextcloud-notes/id813882607)
- [Notes Android App](https://play.google.com/store/apps/details?id=it.niedermann.owncloud.notes)
