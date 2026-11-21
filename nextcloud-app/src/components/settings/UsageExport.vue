<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<form class="usage-export" @submit.prevent="download">
		<div class="usage-export__row">
			<NcTextField v-model="from"
				type="date"
				:label="t('aiquila', 'From')" />
			<NcTextField v-model="to"
				type="date"
				:label="t('aiquila', 'To (inclusive)')" />
		</div>

		<div class="usage-export__row">
			<div class="usage-export__field">
				<label :for="`${id}-granularity`">{{ t('aiquila', 'Rows') }}</label>
				<NcSelect v-model="granularity"
					:input-id="`${id}-granularity`"
					:options="granularityOptions"
					:clearable="false"
					:reduce="option => option.id"
					label="label" />
			</div>
			<div class="usage-export__field">
				<label :for="`${id}-type`">{{ t('aiquila', 'Request type') }}</label>
				<NcSelect v-model="requestType"
					:input-id="`${id}-type`"
					:options="typeOptions"
					:placeholder="t('aiquila', 'All types')"
					:reduce="option => option.id"
					label="label" />
			</div>
		</div>

		<div class="usage-export__row">
			<NcTextField v-model="model"
				:label="t('aiquila', 'Model (optional)')"
				:placeholder="t('aiquila', 'All models')" />
			<NcTextField v-model="provider"
				:label="t('aiquila', 'Provider (optional)')"
				:placeholder="t('aiquila', 'All providers, e.g. anthropic')" />
		</div>

		<div v-if="admin" class="usage-export__field">
			<label :for="`${id}-principal`">{{ t('aiquila', 'User or group') }}</label>
			<NcSelect v-model="principal"
				:input-id="`${id}-principal`"
				:options="principalOptions"
				:loading="principalsLoading"
				:placeholder="t('aiquila', 'Everyone')"
				label="label"
				@search="onPrincipalSearch">
				<template #option="option">
					{{ option.type === 'group' ? t('aiquila', 'Group: {name}', { name: option.label }) : option.label }}
				</template>
			</NcSelect>
		</div>

		<div class="usage-export__formats" role="radiogroup" :aria-label="t('aiquila', 'Format')">
			<NcCheckboxRadioSwitch v-for="option in formatOptions"
				:key="option.id"
				v-model="format"
				:value="option.id"
				:name="`${id}-format`"
				type="radio">
				{{ option.label }}
			</NcCheckboxRadioSwitch>
		</div>

		<p class="usage-export__hint">
			{{ t('aiquila', 'Times and days are in UTC; weeks start on Monday.') }}
			<template v-if="admin">
				{{ t('aiquila', 'For scheduled exports, use occ aiquila:usage:export.') }}
			</template>
		</p>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcButton type="submit" variant="primary" :disabled="busy">
			<template #icon>
				<NcLoadingIcon v-if="busy" :size="20" />
			</template>
			{{ t('aiquila', 'Download') }}
		</NcButton>
	</form>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import { downloadUsageExport, searchPrincipals } from '../../settings-api.js'

let instances = 0

export default {
	name: 'UsageExport',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},
	props: {
		/** Admin export: any user or group, with a user column. */
		admin: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			id: `aiquila-usage-export-${++instances}`,
			from: '',
			to: '',
			granularity: 'request',
			requestType: null,
			model: '',
			provider: '',
			format: 'csv',
			principal: null,
			principalOptions: [],
			principalsLoading: false,
			searchTimer: null,
			busy: false,
			error: '',
		}
	},
	computed: {
		granularityOptions() {
			return [
				{ id: 'request', label: t('aiquila', 'One per request') },
				{ id: 'day', label: t('aiquila', 'Sum per day') },
				{ id: 'week', label: t('aiquila', 'Sum per week') },
				{ id: 'month', label: t('aiquila', 'Sum per month') },
			]
		},
		typeOptions() {
			return [
				{ id: 'chat', label: t('aiquila', 'Chat') },
				{ id: 'api', label: t('aiquila', 'API') },
				{ id: 'task', label: t('aiquila', 'Tasks') },
				{ id: 'coworker', label: t('aiquila', 'Coworkers') },
				{ id: 'talk', label: t('aiquila', 'Talk') },
				{ id: 'flow', label: t('aiquila', 'Flow') },
			]
		},
		formatOptions() {
			return [
				{ id: 'csv', label: t('aiquila', 'CSV (spreadsheets)') },
				{ id: 'json', label: t('aiquila', 'JSON') },
			]
		},
	},
	mounted() {
		if (this.admin) {
			this.loadPrincipals('')
		}
	},
	beforeUnmount() {
		clearTimeout(this.searchTimer)
	},
	methods: {
		t,
		onPrincipalSearch(query) {
			clearTimeout(this.searchTimer)
			this.searchTimer = setTimeout(() => this.loadPrincipals(query), 300)
		},
		async loadPrincipals(query) {
			this.principalsLoading = true
			try {
				const { data } = await searchPrincipals(query || '')
				this.principalOptions = [
					...(data.users || []).map(u => ({ ...u, type: 'user', key: `user:${u.id}` })),
					...(data.groups || []).map(g => ({ ...g, type: 'group', key: `group:${g.id}` })),
				]
			} catch (e) {
				this.principalOptions = []
			} finally {
				this.principalsLoading = false
			}
		},
		async download() {
			this.busy = true
			this.error = ''
			try {
				const params = {
					format: this.format,
					granularity: this.granularity,
					from: this.from,
					to: this.to,
					model: this.model.trim(),
					provider: this.provider.trim(),
					requestType: this.requestType,
				}
				if (this.admin && this.principal) {
					params[this.principal.type] = this.principal.id
				}
				const response = await downloadUsageExport(this.admin, params)
				this.save(response.data, this.filename(response.headers['content-disposition']))
			} catch (e) {
				this.error = await this.errorMessage(e)
			} finally {
				this.busy = false
			}
		},
		filename(disposition) {
			const match = /filename="([^"]+)"/.exec(disposition || '')
			return match ? match[1] : `aiquila-usage.${this.format}`
		},
		save(blob, name) {
			const href = URL.createObjectURL(blob)
			const link = document.createElement('a')
			link.href = href
			link.download = name
			document.body.appendChild(link)
			link.click()
			link.remove()
			setTimeout(() => URL.revokeObjectURL(href), 0)
		},
		/** The server's `{error}` text; with responseType blob it arrives as a Blob. */
		async errorMessage(e) {
			const data = e?.response?.data
			try {
				const body = data instanceof Blob ? JSON.parse(await data.text()) : data
				if (body?.error) {
					return body.error
				}
			} catch (ignored) {
				// Not JSON: fall through to the generic message.
			}
			if (e?.response?.status === 429) {
				return t('aiquila', 'Too many exports in a short time. Try again in a minute.')
			}
			return t('aiquila', 'The export failed.')
		},
	},
}
</script>

<style scoped>
.usage-export {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 640px;
}

.usage-export__row {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
}

.usage-export__row > * {
	flex: 1 1 240px;
}

.usage-export__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.usage-export__formats {
	display: flex;
	gap: 16px;
}

.usage-export__hint {
	color: var(--color-text-maxcontrast);
}
</style>
