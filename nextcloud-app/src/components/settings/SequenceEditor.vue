<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<!--
		Edits one sequence: its name and description (what the model reads),
		its inputs, and its steps. Each step picks a tool from the MCP server's
		catalog; its argument fields come from that tool's input schema. Any
		field can hold a {{variable}}: an input, a date, or an earlier step's
		output. The server checks the whole sequence again on save.
	-->
	<form class="seq-editor" @submit.prevent="submit">
		<NcTextField v-model="form.title"
			:label="t('aiquila', 'Title')"
			:maxlength="255"
			required />
		<NcTextField v-model="form.slug"
			:label="t('aiquila', 'Tool name')"
			:helper-text="t('aiquila', 'Lowercase letters, digits, _ and -. The model sees it as {name}.', { name: toolName })"
			:maxlength="48"
			required />
		<label class="seq-editor__label" :for="ids.description">
			{{ t('aiquila', 'Description (the text the model reads)') }}
		</label>
		<textarea :id="ids.description"
			v-model="form.description"
			class="seq-editor__textarea"
			rows="3"
			:maxlength="2000" />

		<div class="seq-editor__badges">
			<span class="seq-editor__badge" :class="`seq-editor__badge--${kind.id}`">{{ kind.label }}</span>
			<NcCheckboxRadioSwitch v-model="form.pinned">
				{{ t('aiquila', 'Show as its own tool') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="form.enabled">
				{{ t('aiquila', 'Enabled') }}
			</NcCheckboxRadioSwitch>
		</div>

		<h4>{{ t('aiquila', 'Inputs') }}</h4>
		<p class="seq-editor__hint">
			{{ inputsHint }}
		</p>
		<table v-if="form.inputs.length > 0" class="seq-editor__inputs">
			<thead>
				<tr>
					<th>{{ t('aiquila', 'Name') }}</th>
					<th>{{ t('aiquila', 'Type') }}</th>
					<th>{{ t('aiquila', 'Required') }}</th>
					<th>{{ t('aiquila', 'Description') }}</th>
					<th>{{ t('aiquila', 'Default') }}</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<tr v-for="(input, i) in form.inputs" :key="input.key">
					<td><input v-model="input.name" :aria-label="t('aiquila', 'Name')" maxlength="32"></td>
					<td>
						<select v-model="input.type" :aria-label="t('aiquila', 'Type')">
							<option v-for="type in inputTypes" :key="type" :value="type">
								{{ type }}
							</option>
						</select>
					</td>
					<td><input v-model="input.required" type="checkbox" :aria-label="t('aiquila', 'Required')"></td>
					<td><input v-model="input.description" :aria-label="t('aiquila', 'Description')" maxlength="500"></td>
					<td><input v-model="input.defaultText" :aria-label="t('aiquila', 'Default')"></td>
					<td>
						<NcButton type="tertiary" :aria-label="t('aiquila', 'Remove input')" @click="form.inputs.splice(i, 1)">
							<template #icon>
								<DeleteIcon :size="20" />
							</template>
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
		<NcButton type="secondary" class="seq-editor__add" @click="addInput">
			<template #icon>
				<PlusIcon :size="20" />
			</template>
			{{ t('aiquila', 'Add input') }}
		</NcButton>

		<h4>{{ t('aiquila', 'Steps') }}</h4>
		<ol class="seq-editor__steps">
			<li v-for="(step, i) in form.steps" :key="step.key" class="seq-editor__step">
				<div class="seq-editor__step-head">
					<input v-model="step.id"
						class="seq-editor__step-id"
						:aria-label="t('aiquila', 'Step id')"
						:placeholder="`step${i + 1}`"
						maxlength="32">
					<NcSelect class="seq-editor__tool"
						:model-value="step.tool"
						:options="toolOptions"
						:reduce="o => o.value"
						label="label"
						:clearable="false"
						:input-label="t('aiquila', 'Tool')"
						:placeholder="t('aiquila', 'Pick a tool')"
						@update:model-value="tool => setTool(step, tool)" />
					<select v-model="step.onFailure" :aria-label="t('aiquila', 'On failure')">
						<option value="stop">
							{{ t('aiquila', 'On failure: stop') }}
						</option>
						<option value="continue">
							{{ t('aiquila', 'On failure: continue') }}
						</option>
					</select>
					<NcButton type="tertiary" :disabled="i === 0" :aria-label="t('aiquila', 'Move up')" @click="move(i, -1)">
						<template #icon>
							<ArrowUpIcon :size="20" />
						</template>
					</NcButton>
					<NcButton type="tertiary" :disabled="i === form.steps.length - 1" :aria-label="t('aiquila', 'Move down')" @click="move(i, 1)">
						<template #icon>
							<ArrowDownIcon :size="20" />
						</template>
					</NcButton>
					<NcButton type="tertiary" :aria-label="t('aiquila', 'Remove step')" @click="form.steps.splice(i, 1)">
						<template #icon>
							<DeleteIcon :size="20" />
						</template>
					</NcButton>
				</div>
				<div v-for="arg in argumentsOf(step)" :key="arg.name" class="seq-editor__arg">
					<NcTextField v-model="step.args[arg.name]"
						:label="arg.name + (arg.required ? ' *' : '')"
						:helper-text="arg.help"
						:placeholder="arg.placeholder" />
					<NcActions :aria-label="t('aiquila', 'Insert variable')" :force-menu="true">
						<template #icon>
							<CodeBracesIcon :size="20" />
						</template>
						<NcActionButton v-for="v in variablesFor(i)"
							:key="v"
							:close-after-click="true"
							@click="insert(step, arg.name, v)">
							{{ placeholder(v) }}
						</NcActionButton>
					</NcActions>
				</div>
			</li>
		</ol>
		<NcButton type="secondary" class="seq-editor__add" :disabled="form.steps.length >= maxSteps" @click="addStep">
			<template #icon>
				<PlusIcon :size="20" />
			</template>
			{{ t('aiquila', 'Add step') }}
		</NcButton>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<div class="seq-editor__actions">
			<NcButton type="primary" native-type="submit" :disabled="saving || !canSave">
				{{ saving ? t('aiquila', 'Saving…') : t('aiquila', 'Save') }}
			</NcButton>
			<NcButton type="tertiary" @click="$emit('cancel')">
				{{ t('aiquila', 'Cancel') }}
			</NcButton>
		</div>
	</form>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ArrowDownIcon from 'vue-material-design-icons/ArrowDown.vue'
import ArrowUpIcon from 'vue-material-design-icons/ArrowUp.vue'
import CodeBracesIcon from 'vue-material-design-icons/CodeBraces.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'

let uid = 0
const LONE_TEMPLATE = /^\{\{\s*(inputs|steps|today|now)\b[^{}]*\}\}$/

/** A value as the text of a field. */
function asText(value) {
	if (value === undefined || value === null) {
		return ''
	}
	return typeof value === 'string' ? value : JSON.stringify(value)
}

/** The text of a field as the value the schema wants; '' means "leave out". */
function fromText(text, property = {}) {
	if (text === undefined || text === null || text.trim() === '') {
		return undefined
	}
	if (LONE_TEMPLATE.test(text.trim())) {
		return text.trim()
	}
	const types = [].concat(property.type || [])
	if (types.includes('integer') || types.includes('number')) {
		const n = Number(text)
		if (text.trim() !== '' && !Number.isNaN(n)) {
			return n
		}
	}
	if (types.includes('boolean') && (text === 'true' || text === 'false')) {
		return text === 'true'
	}
	if (types.includes('array') || types.includes('object')) {
		try {
			return JSON.parse(text)
		} catch {
			return text
		}
	}
	return text
}

export default {
	name: 'SequenceEditor',
	components: {
		NcActionButton,
		NcActions,
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcSelect,
		NcTextField,
		ArrowDownIcon,
		ArrowUpIcon,
		CodeBracesIcon,
		DeleteIcon,
		PlusIcon,
	},
	props: {
		/** The sequence to edit, or null for a new one. */
		sequence: {
			type: Object,
			default: null,
		},
		/** From GET /api/sequences/catalog. */
		catalog: {
			type: Object,
			required: true,
		},
		/** 'user' (seq_my_*) or 'instance' (seq_*). */
		scope: {
			type: String,
			default: 'user',
		},
		maxSteps: {
			type: Number,
			default: 20,
		},
		saving: {
			type: Boolean,
			default: false,
		},
		error: {
			type: String,
			default: '',
		},
	},
	emits: ['save', 'cancel'],
	data() {
		uid += 1
		const s = this.sequence
		const def = s?.definition || { inputs: [], steps: [] }
		return {
			ids: { description: `aiquila-seq-description-${uid}` },
			form: {
				slug: s?.slug || '',
				title: s?.title || '',
				description: s?.description || '',
				pinned: s ? !!s.pinned : false,
				enabled: s ? s.enabled !== false : true,
				inputs: (def.inputs || []).map(i => ({
					key: ++uid,
					name: i.name,
					type: i.type,
					required: !!i.required,
					description: i.description || '',
					defaultText: asText(i.default),
				})),
				steps: (def.steps || []).map(st => ({
					key: ++uid,
					id: st.id || '',
					tool: st.tool,
					onFailure: st.onFailure || 'stop',
					args: Object.fromEntries(Object.entries(st.arguments || {}).map(([k, v]) => [k, asText(v)])),
				})),
			},
		}
	},
	computed: {
		toolsByName() {
			return Object.fromEntries((this.catalog.tools || []).map(tool => [tool.name, tool]))
		},
		toolOptions() {
			return (this.catalog.tools || []).map(tool => ({
				value: tool.name,
				label: `${tool.category} › ${tool.title} (${tool.name})`,
			}))
		},
		inputTypes() {
			return this.catalog.inputTypes || ['string', 'integer', 'number', 'boolean', 'array']
		},
		toolName() {
			const slug = this.form.slug || '…'
			return this.scope === 'instance' ? `seq_${slug}` : `seq_my_${slug}`
		},
		/** Read-only only if every step is; destructive if any is. */
		kind() {
			const hints = this.form.steps.map(st => this.toolsByName[st.tool]?.annotations)
			if (hints.length > 0 && hints.every(h => h?.readOnlyHint)) {
				return { id: 'readonly', label: t('aiquila', 'Read-only') }
			}
			if (hints.some(h => !h || (!h.readOnlyHint && h.destructiveHint))) {
				return { id: 'destructive', label: t('aiquila', 'Destructive') }
			}
			return { id: 'changes', label: t('aiquila', 'Changes data') }
		},
		inputsHint() {
			return t('aiquila', 'Values the caller passes in. Use them in a step as {example}.', { example: this.placeholder('inputs.name') })
		},
		canSave() {
			return this.form.title.trim() !== '' && this.form.slug.trim() !== ''
				&& this.form.steps.length > 0 && this.form.steps.every(st => st.tool)
		},
	},
	methods: {
		t,
		addInput() {
			this.form.inputs.push({ key: ++uid, name: '', type: 'string', required: false, description: '', defaultText: '' })
		},
		addStep() {
			this.form.steps.push({ key: ++uid, id: '', tool: '', onFailure: 'stop', args: {} })
		},
		setTool(step, tool) {
			step.tool = tool
			// Keep the values of arguments the new tool also has.
			const props = this.toolsByName[tool]?.inputSchema?.properties || {}
			step.args = Object.fromEntries(Object.entries(step.args).filter(([k]) => k in props))
		},
		move(i, delta) {
			const steps = this.form.steps
			const [step] = steps.splice(i, 1)
			steps.splice(i + delta, 0, step)
		},
		argumentsOf(step) {
			const schema = this.toolsByName[step.tool]?.inputSchema
			if (!schema) {
				return []
			}
			const required = schema.required || []
			return Object.entries(schema.properties || {}).map(([name, p]) => ({
				name,
				required: required.includes(name),
				help: p.description || '',
				placeholder: p.enum ? p.enum.join(' | ') : [].concat(p.type || []).join(' | '),
			}))
		},
		/** The variables a step may use: inputs, dates, earlier steps. */
		variablesFor(index) {
			const vars = this.form.inputs.filter(i => i.name).map(i => `inputs.${i.name}`)
			vars.push('today', 'today+1d', 'today+7d', 'now', 'now+1h')
			this.form.steps.slice(0, index).forEach((st, n) => {
				const id = st.id || `step${n + 1}`
				vars.push(`steps.${id}.text`)
				if (this.toolsByName[st.tool]) {
					vars.push(`steps.${id}.result`)
				}
			})
			return vars
		},
		/** Written out here: a literal pair of braces in the template would end the interpolation. */
		placeholder(variable) {
			return '{' + '{' + variable + '}' + '}'
		},
		insert(step, name, variable) {
			step.args[name] = (step.args[name] || '') + this.placeholder(variable)
		},
		submit() {
			const inputs = this.form.inputs.map(i => {
				const out = { name: i.name.trim(), type: i.type, required: !!i.required, description: i.description }
				const value = fromText(i.defaultText, { type: i.type })
				if (value !== undefined) {
					out.default = value
				}
				return out
			})
			const steps = this.form.steps.map(st => {
				const props = this.toolsByName[st.tool]?.inputSchema?.properties || {}
				const args = {}
				for (const [k, text] of Object.entries(st.args)) {
					const value = fromText(text, props[k])
					if (value !== undefined) {
						args[k] = value
					}
				}
				return { ...(st.id.trim() ? { id: st.id.trim() } : {}), tool: st.tool, arguments: args, onFailure: st.onFailure }
			})
			this.$emit('save', {
				slug: this.form.slug.trim(),
				title: this.form.title.trim(),
				description: this.form.description,
				pinned: this.form.pinned,
				enabled: this.form.enabled,
				definition: { inputs, steps },
			})
		},
	},
}
</script>

<style scoped>
.seq-editor {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 12px 0;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.seq-editor__label {
	font-weight: 600;
}

.seq-editor__textarea {
	width: 100%;
	resize: vertical;
}

.seq-editor__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.seq-editor__badges {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px;
}

.seq-editor__badge {
	padding: 2px 10px;
	border-radius: var(--border-radius-pill);
	font-size: 0.9em;
	font-weight: 600;
	background: var(--color-background-dark);
}

.seq-editor__badge--readonly {
	color: var(--color-success-text);
}

.seq-editor__badge--changes {
	color: var(--color-warning-text);
}

.seq-editor__badge--destructive {
	color: var(--color-error-text);
}

.seq-editor__inputs {
	width: 100%;
	border-collapse: collapse;
}

.seq-editor__inputs th {
	text-align: start;
	font-weight: 600;
}

.seq-editor__inputs input:not([type="checkbox"]),
.seq-editor__inputs select {
	width: 100%;
}

.seq-editor__add {
	align-self: flex-start;
}

.seq-editor__steps {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding-inline-start: 20px;
}

.seq-editor__step-head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px;
}

.seq-editor__step-id {
	width: 9em;
}

.seq-editor__tool {
	flex: 1;
	min-width: 240px;
}

.seq-editor__arg {
	display: flex;
	align-items: flex-end;
	gap: 4px;
	margin-inline-start: 12px;
}

.seq-editor__actions {
	display: flex;
	gap: 8px;
}
</style>
