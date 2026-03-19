# Nextcloud Assistant / AI Tools

Integration with Nextcloud's TaskProcessing AI framework. Submit text processing and image generation tasks to configured AI providers (e.g., AIquila, LocalAI, Stable Diffusion).

## Prerequisites

- At least one AI task provider must be configured in Nextcloud (e.g., AIquila app, or an external AI integration)
- For image generation, a text-to-image provider must be available

## Available Tools

| Tool | Description |
|------|-------------|
| `list_text_tasks` | List available AI task types |
| `process_text` | Submit a text processing task |
| `get_task_result` | Get status/result of an AI task |
| `generate_image` | Generate an image from a text prompt |

---

### list_text_tasks

List AI task types available in Nextcloud's TaskProcessing framework (summarize, headline, image-to-text, etc.). Shows which task types have at least one provider configured.

**Parameters:**
None

**Returns:**
List of available AI task types with their IDs and descriptions.

**Example Usage:**
```
Ask Claude: "What AI task types are available in Nextcloud?"
Ask Claude: "List the text processing capabilities"
```

---

### process_text

Submit a text-processing task to Nextcloud's AI framework and return the task ID. Use `get_task_result` to poll until it completes.

**Parameters:**
- `taskType` (string, required): Task type ID (e.g., `core:text2text`, `core:summarize`, `core:headline`, `core:extract_topics`)
- `input` (string, required): The text to process
- `customId` (string, optional): Optional identifier to tag the task for later retrieval

**Returns:**
Task ID and initial status.

**Example Usage:**
```
Ask Claude: "Use Nextcloud AI to summarize this text: [long text]"
Ask Claude: "Generate a headline for this article using NC assistant"
```

**Common Task Types:**
| Type ID | Description |
|---------|-------------|
| `core:text2text` | General text-to-text processing |
| `core:summarize` | Text summarization |
| `core:headline` | Generate a headline |
| `core:extract_topics` | Extract topics from text |

---

### get_task_result

Get the status and result of a Nextcloud AI task previously created by `process_text` or `generate_image`.

**Parameters:**
- `taskId` (number, required): The task ID returned by `process_text` or `generate_image`

**Returns:**
Task status and result (if completed).

**Status codes:**
| Status | Meaning |
|--------|---------|
| 1 | Scheduled |
| 2 | Running |
| 3 | Successful |
| 4 | Failed |

**Example Usage:**
```
Ask Claude: "Check the result of AI task 42"
```

---

### generate_image

Generate an image from a text prompt using Nextcloud's text-to-image AI provider (e.g., Stable Diffusion via LocalAI). Returns the task ID; use `get_task_result` to check completion.

**Parameters:**
- `prompt` (string, required): Text description of the image to generate
- `savePath` (string, optional): Optional Nextcloud path to save the generated image

**Returns:**
Task ID for image generation.

**Example Usage:**
```
Ask Claude: "Generate an image of a sunset over mountains using Nextcloud AI"
Ask Claude: "Create an image of a cat sitting on a laptop and save it to /Photos/ai-cat.png"
```

---

## Workflow Examples

### Text Summarization
```
User: "Read my file /Documents/report.md and summarize it using Nextcloud AI"
Claude: Reads file -> submits to process_text with core:summarize -> polls get_task_result -> returns summary
```

### Image Generation Pipeline
```
User: "Generate a cover image for my blog post about AI and save it to /Photos"
Claude: Calls generate_image with descriptive prompt -> polls for completion -> confirms save path
```

## Development

To extend assistant tools:
- See [Adding Tools Guide](../../development/adding-tools.md)
- Source code: [mcp-server/src/tools/apps/assistant.ts](../../../../mcp-server/src/tools/apps/assistant.ts)

## References

- [Nextcloud Assistant](https://docs.nextcloud.com/server/latest/admin_manual/ai/index.html)
- [TaskProcessing API](https://docs.nextcloud.com/server/latest/developer_manual/digging_deeper/taskprocessing.html)
