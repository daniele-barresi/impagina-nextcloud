/**
 * Punto di ingresso della pagina Impostazioni -> Amministrazione -> Impagina.
 */

import { createApp } from 'vue'
import AdminSettings from './admin/AdminSettings.vue'

// Lo stile dei toast mostrati dalla pagina (salvataggio, verifica).
import '@nextcloud/dialogs/style.css'

const radice = document.getElementById('impagina-admin-settings')
if (radice !== null) {
	createApp(AdminSettings).mount(radice)
}
