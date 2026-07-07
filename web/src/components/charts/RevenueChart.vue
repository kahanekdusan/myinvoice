<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  Chart,
  BarController,
  BarElement,
  LineController,
  LineElement,
  PointElement,
  CategoryScale,
  LinearScale,
  Tooltip,
  Legend,
} from 'chart.js'

Chart.register(BarController, BarElement, LineController, LineElement, PointElement, CategoryScale, LinearScale, Tooltip, Legend)

const props = defineProps<{
  months: Array<{ ym: string; total: number }>
  prevYear: Array<{ ym: string; total: number }>
  currency: string
}>()

const canvas = ref<HTMLCanvasElement | null>(null)
let chart: Chart | null = null

const { locale } = useI18n()

/** Label „04/2026" — měsíc/rok podle aktuální řady (months). */
function labelFor(ym: string): string {
  const [y, m] = ym.split('-')
  return `${m}/${y}`
}

function build() {
  if (!canvas.value) return
  if (chart) chart.destroy()

  const labels = props.months.map(p => labelFor(p.ym))
  const data = props.months.map(p => p.total)
  const prevData = props.prevYear.map(p => p.total)

  chart = new Chart(canvas.value, {
    type: 'bar',
    data: {
      labels,
      datasets: [
        {
          label: `${props.currency}`,
          data,
          backgroundColor: '#5C45A0',
          borderRadius: 4,
        },
        {
          label: `${props.currency} (-1y)`,
          data: prevData,
          type: 'line',
          borderColor: '#A99CD8',
          backgroundColor: 'transparent',
          borderWidth: 2,
          tension: 0.3,
          pointRadius: 3,
          pointBackgroundColor: '#A99CD8',
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: {
          position: 'bottom',
          labels: { font: { size: 11 }, boxWidth: 12, color: '#5A5470' },
        },
        tooltip: {
          backgroundColor: '#15131D',
          callbacks: {
            // Prev-year tooltip ukazuje, ze kterého měsíce předchozího roku hodnota pochází.
            title: (items) => {
              if (!items.length) return ''
              const i = items[0].dataIndex
              const cur = props.months[i]?.ym
              const prev = props.prevYear[i]?.ym
              return cur && prev ? `${labelFor(cur)} · ${labelFor(prev)}` : (items[0].label ?? '')
            },
            label: (ctx) => `${ctx.dataset.label}: ${formatVal(ctx.parsed.y ?? 0)} ${props.currency}`,
          },
        },
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: { color: '#7A748C', font: { size: 11 }, callback: (v) => formatTick(Number(v)) },
          grid: { color: '#E7E3EE' },
        },
        x: {
          ticks: { color: '#7A748C', font: { size: 11 } },
          grid: { display: false },
        },
      },
    },
  })
}

function formatVal(n: number): string {
  return new Intl.NumberFormat('cs-CZ', { maximumFractionDigits: 0 }).format(n)
}

function formatTick(n: number): string {
  if (n >= 1_000_000) return (n / 1_000_000).toFixed(1) + 'M'
  if (n >= 1_000) return (n / 1_000).toFixed(0) + 'k'
  return n.toString()
}

onMounted(build)
onBeforeUnmount(() => chart?.destroy())
watch(() => [props.months, props.prevYear, props.currency, locale.value], build, { deep: true })
</script>

<template>
  <div class="relative h-64">
    <canvas ref="canvas"></canvas>
  </div>
</template>
