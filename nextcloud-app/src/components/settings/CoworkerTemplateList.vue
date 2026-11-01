<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<!--
		Organization coworker templates. They are published from a coworker's
		row in Cowork, which is where a working configuration already exists,
		so this list only shows and deletes them.
	-->
	<div class="cw-templates">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<p v-if="!loading && templates.length === 0" class="cw-templates__empty">
			{{ t('aiquila', 'No organization coworker templates yet. Publish one from a coworker\'s row in AIquila → Cowork.') }}
		</p>
		<ul v-else class="cw-templates__list">
			<li v-for="tpl in templates" :key="tpl.id" class="cw-templates__item">
				<div class="cw-templates__text">
					<span class="cw-templates__title">{{ tpl.title }}</span>
					<span class="cw-templates__meta">{{ tpl.task_type }} · {{ tpl.cron_schedule }} · {{ tpl.input_path }}</span>
				</div>
				<NcButton type="tertiary"
					:aria-label="t('aiquila', 'Delete')"
					:title="t('aiquila', 'Delete')"
					@click="remove(tpl)">
					<template #icon>
						<DeleteIcon :size="20" />
					</template>
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'

import { listCoworkerTemplatesAdmin, deleteCoworkerTemplate } from '../../settings-api.js'

export default {
	name: 'CoworkerTemplateList',
	components: { NcButton, NcNoteCard, DeleteIcon },
	data() {
		return { templates: [], loading: true, error: '' }
	},
	async mounted() {
		try {
			const { data } = await listCoworkerTemplatesAdmin()
			this.templates = data
		} catch (err) {
			this.error = err.response?.data?.error || err.message
		} finally {
			this.loading = false
		}
	},
	methods: {
		t,
		async remove(tpl) {
			this.error = ''
			try {
				await deleteCoworkerTemplate(tpl.id)
				this.templates = this.templates.filter(x => x.id !== tpl.id)
			} catch (err) {
				this.error = err.response?.data?.error || err.message
			}
		},
	},
}
</script>

<style scoped>
.cw-templates {
	max-width: 640px;
}

.cw-templates__empty,
.cw-templates__meta {
	color: var(--color-text-maxcontrast);
}

.cw-templates__item {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 4px 0;
	border-bottom: 1px solid var(--color-border);
}

.cw-templates__text {
	flex: 1;
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.cw-templates__title {
	font-weight: 600;
}

.cw-templates__meta {
	font-size: 0.9em;
}
</style>
