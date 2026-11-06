/*!
 * SPDX-FileCopyrightText: 2026 Schauties Media
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import '../gemeinsam/thema.css'
import { createApp } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import KundenReview from './KundenReview.vue'
import type { KundenInfo } from './texte'

interface KundenStart {
	token: string
	info: KundenInfo
}

const start = loadState<KundenStart>('clipproof', 'kunde')
createApp(KundenReview, { token: start.token, info: start.info }).mount('#clipproof-kunde')
