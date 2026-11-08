<!--
	Pagina di amministrazione dell'app Impagina.

	La configurazione vale per tutta l'istanza: gli utenti non hanno opzioni.
	Il token si scrive ma non si rilegge mai: la pagina sa solo se e' impostato.
-->
<template>
	<div class="impagina-admin">
		<NcSettingsSection :name="t('impagina', 'Connection to the impagina service')"
			:description="t('impagina', 'The service runs on another machine of the internal network. Only administrators can change this address.')">
			<div class="impagina-modulo">
				<NcRadioGroup v-model="impostazioni.schema" :label="t('impagina', 'Protocol')">
					<NcRadioGroupButton v-for="protocollo in protocolli"
						:key="protocollo"
						:value="protocollo"
						:label="protocollo.toUpperCase()" />
				</NcRadioGroup>

				<div class="impagina-griglia impagina-griglia--host">
					<NcTextField v-model="impostazioni.host"
						:label="t('impagina', 'Host')"
						placeholder="impagina.example.internal" />
					<NcTextField v-model="porta"
						type="number"
						:label="t('impagina', 'Port')"
						:placeholder="impostazioni.schema === 'https' ? '443' : '80'" />
				</div>

				<NcTextField v-model="impostazioni.percorsoBase"
					:label="t('impagina', 'Base path (optional)')"
					:helper-text="t('impagina', 'Only if the service is published under a sub-path of the reverse proxy.')"
					placeholder="impagina" />

				<NcNoteCard v-if="impostazioni.schema === 'http'" type="warning">
					{{ t('impagina', 'Without TLS the token travels in clear text on the network. Use it only on a trusted network.') }}
				</NcNoteCard>

				<div class="impagina-griglia impagina-griglia--token">
					<NcPasswordField v-model="token"
						:label="t('impagina', 'Bearer token')"
						:helper-text="aiutoToken"
						:placeholder="tokenImpostato ? t('impagina', 'Set: leave empty to keep it') : 'imp_…'" />
					<NcButton v-if="tokenImpostato"
						variant="tertiary"
						@click="cancellaToken">
						{{ t('impagina', 'Remove token') }}
					</NcButton>
				</div>

				<NcFormBox>
					<NcFormBoxSwitch v-model="impostazioni.verificaTls"
						:label="t('impagina', 'Verify the TLS certificate')" />
				</NcFormBox>
				<NcNoteCard v-if="!impostazioni.verificaTls" type="warning">
					{{ t('impagina', 'With verification off, anyone on the network path can impersonate the service. Prefer installing the certificate authority on the server.') }}
				</NcNoteCard>

				<div class="impagina-griglia impagina-griglia--due">
					<NcTextField v-model="timeoutConnessione"
						type="number"
						:label="t('impagina', 'Connection timeout (s)')" />
					<NcTextField v-model="timeoutRichiesta"
						type="number"
						:label="t('impagina', 'Request timeout (s)')" />
				</div>
			</div>
		</NcSettingsSection>

		<NcSettingsSection :name="t('impagina', 'Connection check')"
			:description="t('impagina', 'Two steps: first whether the service answers, then whether the token is accepted. The values in this page are used, even if not saved yet.')">
			<div class="impagina-modulo">
				<div>
					<NcButton :disabled="verificaInCorso || impostazioni.host === ''" @click="verifica">
						<template v-if="verificaInCorso" #icon>
							<NcLoadingIcon :size="20" />
						</template>
						{{ t('impagina', 'Check connection') }}
					</NcButton>
				</div>

				<NcNoteCard v-if="esito" :type="esito.servizio.stato === 'ok' ? 'success' : 'error'">
					{{ esito.servizio.messaggio }}
				</NcNoteCard>
				<NcNoteCard v-if="esito && esito.servizio.avviso" type="warning">
					{{ esito.servizio.avviso }}
				</NcNoteCard>
				<NcNoteCard v-if="esito && esito.token.stato !== 'non_provato'"
					:type="tipoEsitoToken">
					{{ esito.token.messaggio }}
				</NcNoteCard>
			</div>
		</NcSettingsSection>

		<NcSettingsSection :name="t('impagina', 'Default conversion options')"
			:description="t('impagina', 'They apply to every conversion. The front matter of each document still wins over these values.')">
			<div class="impagina-modulo">
				<div>
					<NcSelect v-model="impostazioni.template"
						class="impagina-select"
						:options="template"
						:taggable="true"
						:clearable="false"
						:input-label="t('impagina', 'Template')"
						:placeholder="'company'" />
					<p class="impagina-aiuto">
						{{ templateDisponibili
							? t('impagina', 'List read from the service.')
							: t('impagina', 'The service did not answer: type the template name.') }}
					</p>
				</div>

				<NcFormBox>
					<NcFormBoxSwitch v-model="impostazioni.toc"
						:label="t('impagina', 'Generate the table of contents')" />
				</NcFormBox>

				<NcRadioGroup v-model="impostazioni.cover" :label="t('impagina', 'Cover')">
					<NcRadioGroupButton value="template" :label="t('impagina', 'As in the template')" />
					<NcRadioGroupButton value="sempre" :label="t('impagina', 'Always')" />
					<NcRadioGroupButton value="mai" :label="t('impagina', 'Never')" />
				</NcRadioGroup>

				<NcTextField v-model="impostazioni.watermark"
					:label="t('impagina', 'Watermark')"
					:maxlength="100"
					:helper-text="t('impagina', 'Diagonal on every page. Empty means no watermark.')"
					placeholder="DRAFT" />
			</div>
		</NcSettingsSection>

		<NcSettingsSection :name="t('impagina', 'Limits')"
			:description="t('impagina', 'The service has its own limits: these values only avoid sending something that would be refused anyway.')">
			<div class="impagina-modulo">
				<div class="impagina-griglia impagina-griglia--due">
					<NcTextField v-model="dimensioneMassimaMb"
						type="number"
						:label="t('impagina', 'Maximum upload size (MB)')" />
					<NcTextField v-model="attesaMassima"
						type="number"
						:label="t('impagina', 'Maximum wait for a job (s)')" />
				</div>
				<p class="impagina-aiuto impagina-aiuto--sotto">
					{{ t('impagina', 'While waiting, the background job keeps one cron worker busy.') }}
				</p>
			</div>
		</NcSettingsSection>

		<NcSettingsSection :name="t('impagina', 'Who can use the app')"
			:description="t('impagina', 'With no group selected, every user can convert their own Markdown files. Administrators can always use it.')">
			<div class="impagina-modulo">
				<NcSelect v-model="gruppiScelti"
					class="impagina-select"
					:options="gruppi"
					:multiple="true"
					:close-on-select="false"
					label="nome"
					track-by="id"
					:input-label="t('impagina', 'Allowed groups')"
					:placeholder="t('impagina', 'Everyone')" />
			</div>
		</NcSettingsSection>

		<div class="impagina-salva">
			<NcButton variant="primary" :disabled="salvataggioInCorso" @click="salva">
				<template v-if="salvataggioInCorso" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('impagina', 'Save settings') }}
			</NcButton>
			<span v-if="modificato" class="impagina-aiuto">
				{{ t('impagina', 'There are unsaved changes.') }}
			</span>
		</div>
	</div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcFormBox from '@nextcloud/vue/components/NcFormBox'
import NcFormBoxSwitch from '@nextcloud/vue/components/NcFormBoxSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcRadioGroup from '@nextcloud/vue/components/NcRadioGroup'
import NcRadioGroupButton from '@nextcloud/vue/components/NcRadioGroupButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import {
	elencaTemplate,
	messaggioErrore,
	salvaImpostazioni,
	verificaConnessione,
} from './api'
import type { Gruppo, Impostazioni, RisultatoVerifica } from './api'

const impostazioni = ref<Impostazioni>(loadState<Impostazioni>('impagina', 'impostazioni'))
const tokenImpostato = ref(loadState<boolean>('impagina', 'tokenImpostato'))
const gruppi = ref<Gruppo[]>(loadState<Gruppo[]>('impagina', 'gruppi'))

const token = ref('')
const rimuoviToken = ref(false)
const esito = ref<RisultatoVerifica | null>(null)
const template = ref<string[]>([])
const templateDisponibili = ref(false)
const verificaInCorso = ref(false)
const salvataggioInCorso = ref(false)

const protocolli = ['https', 'http']

/** Fotografia di cio' che e' salvato, per segnalare le modifiche pendenti. */
const salvato = ref(JSON.stringify(impostazioni.value))
const modificato = computed(() => JSON.stringify(impostazioni.value) !== salvato.value
	|| token.value !== ''
	|| rimuoviToken.value)

/**
 * I campi numerici di NcTextField lavorano su stringhe: la conversione avviene
 * qui, e il backend valida comunque per conto suo.
 *
 * @param leggi come ricavare il valore dalle impostazioni
 * @param scrivi come riportarlo nelle impostazioni
 */
function numero(leggi: () => number | null, scrivi: (valore: number | null) => void) {
	return computed({
		get: () => {
			const valore = leggi()
			return valore === null ? '' : String(valore)
		},
		set: (testo: string) => {
			const pulito = testo.trim()
			scrivi(pulito === '' ? null : Number(pulito))
		},
	})
}

const porta = numero(() => impostazioni.value.porta, (v) => { impostazioni.value.porta = v })
const timeoutConnessione = numero(
	() => impostazioni.value.timeoutConnessione,
	(v) => { impostazioni.value.timeoutConnessione = v ?? 10 },
)
const timeoutRichiesta = numero(
	() => impostazioni.value.timeoutRichiesta,
	(v) => { impostazioni.value.timeoutRichiesta = v ?? 60 },
)
const dimensioneMassimaMb = numero(
	() => impostazioni.value.dimensioneMassimaMb,
	(v) => { impostazioni.value.dimensioneMassimaMb = v ?? 50 },
)
const attesaMassima = numero(
	() => impostazioni.value.attesaMassima,
	(v) => { impostazioni.value.attesaMassima = v ?? 180 },
)

const gruppiScelti = computed({
	get: () => gruppi.value.filter((gruppo) => impostazioni.value.gruppi.includes(gruppo.id)),
	set: (scelti: Gruppo[]) => { impostazioni.value.gruppi = scelti.map((gruppo) => gruppo.id) },
})

const aiutoToken = computed(() => (tokenImpostato.value
	? t('impagina', 'A token is saved. Type a new one only to replace it.')
	: t('impagina', 'Create it on the service with: ./impagina.sh --api chiave nuova nextcloud')))

const tipoEsitoToken = computed(() => {
	if (esito.value === null) {
		return 'error'
	}
	if (esito.value.token.stato === 'ok') {
		return 'success'
	}
	return esito.value.token.stato === 'mancante' ? 'warning' : 'error'
})

function cancellaToken() {
	token.value = ''
	rimuoviToken.value = true
	tokenImpostato.value = false
	showSuccess(t('impagina', 'The token will be removed when you save.'))
}

async function verifica() {
	verificaInCorso.value = true
	try {
		esito.value = await verificaConnessione(impostazioni.value, token.value === '' ? null : token.value)
		const elenco = esito.value.token.template
		if (elenco !== undefined && elenco.length > 0) {
			template.value = elenco
			templateDisponibili.value = true
		}
	} catch (errore) {
		esito.value = null
		showError(messaggioErrore(errore) ?? t('impagina', 'The check could not be run.'))
	} finally {
		verificaInCorso.value = false
	}
}

async function salva() {
	salvataggioInCorso.value = true
	try {
		const risposta = await salvaImpostazioni(
			impostazioni.value,
			token.value === '' ? null : token.value,
			rimuoviToken.value,
		)
		impostazioni.value = risposta.impostazioni
		salvato.value = JSON.stringify(risposta.impostazioni)
		tokenImpostato.value = risposta.tokenImpostato
		token.value = ''
		rimuoviToken.value = false
		showSuccess(t('impagina', 'Settings saved.'))
	} catch (errore) {
		showError(messaggioErrore(errore) ?? t('impagina', 'The settings could not be saved.'))
	} finally {
		salvataggioInCorso.value = false
	}
}

onMounted(async () => {
	try {
		const elenco = await elencaTemplate()
		if (elenco.length > 0) {
			template.value = elenco
			templateDisponibili.value = true
		}
	} catch {
		// Il menu a tendina ripiega sul testo libero: non serve avvisare.
	}
	if (!template.value.includes(impostazioni.value.template)) {
		template.value = [impostazioni.value.template, ...template.value]
	}
})
</script>

<style scoped>
/* Una colonna sola, larga come i campi delle impostazioni native. */
.impagina-modulo {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 4);
	max-width: 600px;
}

/* NcNoteCard ha margini suoi, che si sommerebbero al gap della colonna. */
.impagina-modulo > :deep(.notecard) {
	margin: 0;
}

/* Campi affiancati della stessa altezza, con i bordi allineati. */
.impagina-griglia {
	display: grid;
	gap: calc(var(--default-grid-baseline) * 3);
	align-items: start;
}

.impagina-griglia--host {
	grid-template-columns: minmax(0, 1fr) 120px;
}

.impagina-griglia--token {
	grid-template-columns: minmax(0, 1fr) auto;
}

.impagina-griglia--due {
	grid-template-columns: repeat(2, minmax(0, 1fr));
}

.impagina-select {
	width: 100%;
}

/* Come il testo di aiuto di NcTextField, per i controlli che non lo hanno. */
.impagina-aiuto {
	padding-block: 4px;
	padding-inline: var(--border-radius-element);
	color: var(--color-text-maxcontrast);
}

/* Un testo di aiuto staccato dal campo: la colonna ha gia' il suo gap. */
.impagina-aiuto--sotto {
	margin-block-start: calc(var(--default-grid-baseline) * -4);
}

/* Allineato al contenuto delle sezioni (vedi NcSettingsSection). */
.impagina-salva {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 3);
	margin: calc(var(--default-grid-baseline) * 7);
	margin-block-start: 0;
}

@media (max-width: 600px) {
	.impagina-griglia--host,
	.impagina-griglia--due {
		grid-template-columns: minmax(0, 1fr);
	}
}
</style>
