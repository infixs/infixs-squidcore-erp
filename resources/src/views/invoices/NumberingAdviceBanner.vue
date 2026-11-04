<script setup lang="ts">
import { useI18n } from "@/composables/useI18n";
import { isNumberingAdvice } from "@/lib/invoices";
import type { OrderInvoice, SefazAdvice } from "@/types/api";
import { ExternalLink, ListOrdered } from "lucide-vue-next";

/**
 * One notice for every invoice rejected by its number (used by another system, or voided): the cause, the numbers and
 * the steps once, so the rows only name the problem. Nothing when no advice is about the numbering.
 */
const props = defineProps<{
  invoices: OrderInvoice[];
}>();

const { t, tn } = useI18n();

const rejected = computed(() => props.invoices.filter((item) => isNumberingAdvice(item.advice)));
const advices = computed(() => rejected.value.map((item) => item.advice).filter(isNumberingAdvice));
const several = computed(() => advices.value.length > 1);

/** Each kind once: the cause of its invoice when it is the only one (numbers included), otherwise the cause of several. */
const causes = computed(() => {
  const byKind = new Map<string, SefazAdvice[]>();

  for (const advice of advices.value) byKind.set(advice.kind, [...(byKind.get(advice.kind) ?? []), advice]);

  return [...byKind.values()].map((group) => (group.length === 1 ? group[0].cause : group[0].summary));
});

/** Numbers of the rejected invoices by series ("Series 1: no. 1, 2, 5"), the series the steps talk about. */
const numbersBySeries = computed(() => {
  const bySeries = new Map<string, string[]>();

  for (const item of rejected.value) {
    if (!item.invoice?.series || !item.invoice.number) continue;

    bySeries.set(item.invoice.series, [...(bySeries.get(item.invoice.series) ?? []), item.invoice.number]);
  }

  const byValue = (a: string, b: string) => Number(a) - Number(b);

  return [...bySeries.entries()]
    .sort(([a], [b]) => byValue(a, b))
    .map(([series, numbers]) => ({ series, numbers: [...numbers].sort(byValue).join(", ") }));
});

const first = computed(() => advices.value[0] ?? null);
const steps = computed(() => (several.value ? first.value?.summarySteps : first.value?.steps) ?? []);
</script>

<template>
  <section
    v-if="first"
    class="isce:flex isce:flex-col isce:gap-3 isce:rounded-lg isce:border isce:border-yellow-200 isce:bg-yellow-50 isce:p-4 isce:text-sm isce:text-yellow-900"
    data-numbering-advice
  >
    <div class="isce:space-y-1">
      <p class="isce:flex isce:items-center isce:gap-2 isce:font-semibold">
        <ListOrdered class="isce:size-4 isce:shrink-0" />
        {{
          tn(
            "invoices.numbering_advice.title",
            { one: "1 NF-e was rejected because of the numbering", many: "{count} NF-e were rejected because of the numbering" },
            advices.length,
          )
        }}
      </p>
      <template v-if="several">
        <p v-for="group in numbersBySeries" :key="group.series" class="isce:pl-6 isce:font-medium">
          {{ t("invoices.numbering_advice.numbers", "Series {series}: no. {numbers}", group) }}
        </p>
      </template>
    </div>

    <p v-for="cause in causes" :key="cause">{{ cause }}</p>

    <ol class="isce:list-decimal isce:space-y-1 isce:pl-5">
      <li v-for="(step, index) in steps" :key="index">{{ step }}</li>
    </ol>

    <div v-if="first.actionUrl">
      <Button size="sm" as="a" :href="first.actionUrl" target="_blank" rel="noopener noreferrer">
        <ExternalLink />
        {{ first.actionLabel }}
      </Button>
    </div>
  </section>
</template>
