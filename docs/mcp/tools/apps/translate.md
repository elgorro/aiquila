# Translate Tools

Translate text with the translation provider configured in Nextcloud.

## Prerequisites

One of these apps enabled, any of which registers the category: `translate2` (Local Machine Translation), `aiquila`, `text_translate` or `translate`. The tool only works if one of them actually provides translation.

## API

`translate_text` runs the `core:text2text:translate` task through the [Task processing API](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/OCS/ocs-taskprocessing-api.html) when a provider offers that task type: translate2, the AIquila app, or an LLM app. When none does, it falls back to the older [Translation API](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/OCS/ocs-translation-api.html), which is deprecated since Nextcloud 30 and only reaches providers that still implement it.

## Available Tools

#### `translate_text`
Translate text, or list the available languages.

**Parameters:**
- `text` (string, optional) — the text to translate. Leave it out to list the source and target languages.
- `toLanguage` (string) — target language code, such as `de`. Required when `text` is given.
- `fromLanguage` (string, optional) — source language code. Leave it out to detect the language.

A translation task that is still running after about 30 seconds is not waited for: the tool returns its task ID, and `get_task_result` in the [Assistant tools](assistant.md) fetches the translation later.

**Example:** "Translate this paragraph into French."
