<!--
  - SPDX-FileCopyrightText: 2026 Schauties Media
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
	Canvas über dem Video. Zeigt eine Zeichnung an oder nimmt neue Striche
	auf (aktiv). Koordinaten 0 bis 1, unabhängig von der Anzeigegröße.
-->
<template>
	<canvas
		ref="leinwand"
		class="vr-zeichenebene"
		:class="{ 'vr-zeichenebene--aktiv': aktiv }"
		@pointerdown="beginnen"
		@pointermove="weiter"
		@pointerup="enden"
		@pointercancel="enden" />
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { normiert, zeichne } from '../lib/zeichnung'
import type { Zeichnung } from './typen'

const props = defineProps<{
	/** Angezeigte Zeichnung (fremde oder die eigene in Arbeit) */
	zeichnung: Zeichnung | null
	aktiv: boolean
	farbe?: string
}>()
const emit = defineEmits<{ (e: 'aenderung', z: Zeichnung): void, (e: 'fertig'): void }>()

const leinwand = ref<HTMLCanvasElement | null>(null)
let arbeit: Zeichnung | null = null
let beobachter: ResizeObserver | null = null

function neuZeichnen(): void {
	const c = leinwand.value
	if (!c) return
	const r = c.getBoundingClientRect()
	const dpr = window.devicePixelRatio || 1
	c.width = Math.round(r.width * dpr)
	c.height = Math.round(r.height * dpr)
	const ctx = c.getContext('2d')
	if (!ctx) return
	ctx.clearRect(0, 0, c.width, c.height)
	if (props.zeichnung) zeichne(ctx, props.zeichnung, c.width, c.height)
}

function punkt(e: PointerEvent): [number, number] {
	const r = leinwand.value!.getBoundingClientRect()
	return normiert((e.clientX - r.left) / r.width, (e.clientY - r.top) / r.height)
}

function beginnen(e: PointerEvent): void {
	if (!props.aktiv) return
	leinwand.value?.setPointerCapture(e.pointerId)
	arbeit = {
		farbe: props.farbe ?? props.zeichnung?.farbe ?? '#ff4d4f',
		striche: [...(props.zeichnung?.striche ?? []), [punkt(e)]],
	}
	melden()
}

function weiter(e: PointerEvent): void {
	if (!props.aktiv || arbeit === null) return
	arbeit.striche[arbeit.striche.length - 1].push(punkt(e))
	melden()
}

function enden(): void {
	if (arbeit !== null) emit('fertig')
	arbeit = null
}

function melden(): void {
	if (arbeit) emit('aenderung', { farbe: arbeit.farbe, striche: arbeit.striche.map((s) => [...s]) })
}

watch(() => props.zeichnung, neuZeichnen, { deep: true })
onMounted(() => {
	neuZeichnen()
	if (leinwand.value) {
		beobachter = new ResizeObserver(neuZeichnen)
		beobachter.observe(leinwand.value)
	}
})
onBeforeUnmount(() => beobachter?.disconnect())
</script>

<style scoped>
.vr-zeichenebene {
	position: absolute;
	inset: 0;
	width: 100%;
	height: 100%;
	pointer-events: none;
	touch-action: none;
}
.vr-zeichenebene--aktiv {
	pointer-events: auto;
	cursor: crosshair;
	box-shadow: inset 0 0 0 2px var(--vr-akzent, #2ea7f0);
}
</style>
