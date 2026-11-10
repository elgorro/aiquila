<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="coworker-triggers">
		<NcLoadingIcon v-if="loading" />
		<template v-else>
			<NcCheckboxRadioSwitch v-model="policy.enabled">
				{{ t('aiquila', 'Allow coworker triggers') }}
			</NcCheckboxRadioSwitch>

			<template v-if="policy.enabled">
				<p class="hint">
					{{ t('aiquila', 'Leave both lists empty to allow everyone. Otherwise only the users listed and the members of the groups listed can set up triggers, and existing triggers of anyone else stop firing.') }}
				</p>
				<SchemaField v-model="policy.users"
					:field="usersField"
					provider-id="coworker-triggers" />
				<SchemaField v-model="policy.groups"
					:field="groupsField"
					provider-id="coworker-triggers" />
			</template>

			<div class="actions">
				<NcButton type="primary" :disabled="saving" @click="save">
					{{ saving ? t('aiquila', 'Saving…') : t('aiquila', 'Save') }}
				</NcButton>
			</div>
			<NcNoteCard v-if="message" :type="messageType">
				{{ message }}
			</NcNoteCard>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import SchemaField from './SchemaField.vue'
import { getCoworkerTriggerPolicyAdmin, saveCoworkerTriggerPolicyAdmin } from '../../settings-api.js'

export default {
	name: 'CoworkerTriggerSettings',
	components: { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, SchemaField },
	data() {
		return {
			loading: true,
			saving: false,
			message: '',
			messageType: 'success',
			policy: { enabled: false, users: [], groups: [] },
		}
	},
	computed: {
		usersField() {
			return { id: 'users', type: 'multiselect', principal_type: 'user', title: t('aiquila', 'Allowed users') }
		},
		groupsField() {
			return { id: 'groups', type: 'multiselect', principal_type: 'group', title: t('aiquila', 'Allowed groups') }
		},
	},
	async mounted() {
		try {
			const { data } = await getCoworkerTriggerPolicyAdmin()
			this.policy = { enabled: !!data.enabled, users: data.users || [], groups: data.groups || [] }
		} catch (e) {
			this.message = t('aiquila', 'Could not load the trigger settings')
			this.messageType = 'error'
		} finally {
			this.loading = false
		}
	},
	methods: {
		t,
		async save() {
			this.saving = true
			this.message = ''
			try {
				const { data } = await saveCoworkerTriggerPolicyAdmin(this.policy)
				this.policy = { enabled: !!data.enabled, users: data.users || [], groups: data.groups || [] }
				this.message = t('aiquila', 'Saved')
				this.messageType = 'success'
			} catch (e) {
				this.message = t('aiquila', 'Could not save the trigger settings')
				this.messageType = 'error'
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.coworker-triggers .hint { color: var(--color-text-maxcontrast); margin: 8px 0; }
.coworker-triggers .actions { margin-top: 12px; }
</style>
