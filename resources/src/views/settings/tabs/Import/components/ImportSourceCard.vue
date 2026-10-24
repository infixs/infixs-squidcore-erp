<script setup lang="ts">
import { useFiscalImportLabels } from "@/composables/useFiscalImportLabels";
import { useI18n } from "@/composables/useI18n";
import { formatNumber } from "@/lib/utils";
import { useAppStore } from "@/stores/useAppStore";
import type { FiscalImportOverview } from "@/types/api";
import { Database, Loader2, RefreshCw } from "lucide-vue-next";

/**
 * What was found in WebmaniaBR: the plugin state, how many products and categories have an NCM there and, while
 * the background analysis runs, its progress.
 */
const props = defineProps<{
  overview: FiscalImportOverview;
  /** The card title is the title of the step (focus target). */
  titled?: boolean;
  isScanning?: boolean;
}>();

const emit = defineEmits<{
  (e: "analyze"): void;
}>();

const { t, tn } = useI18n();
const appStore = useAppStore();
const { sourceState } = useFiscalImportLabels();

const number = (value: number) => formatNumber(value, appStore.locale);

const scan = computed(() => props.overview.scan);
/** No scan yet: either it runs now (with numbers) or it was just scheduled (no numbers yet). */
const isAnalyzing = computed(() => props.overview.scanning !== null || scan.value === null);
const scanning = computed(() => props.overview.scanning);

const progressText = computed(() =>
  scanning.value && scanning.value.total > 0
    ? t("settings.import.source.analyzing", "Analyzing your products… {processed} of {total}", {
        processed: number(Math.min(scanning.value.processed, scanning.value.total)),
        total: number(scanning.value.total),
      })
    : t("settings.import.source.analyzing_short", "Analyzing your products…"),
);
</script>

<template>
  <section class="isce:flex isce:flex-col isce:gap-3 isce:rounded-md isce:border isce:border-input isce:p-4 isce:shadow-xs" data-import-source>
    <div class="isce:flex isce:flex-wrap isce:items-center isce:gap-2">
      <Database class="isce:size-4 isce:shrink-0 isce:text-muted-foreground" aria-hidden="true" />
      <h3
        class="isce:text-base isce:font-semibold isce:outline-none"
        :tabindex="titled ? -1 : undefined"
        :data-import-step-title="titled ? '' : undefined"
      >
        {{ t("settings.import.source.name", "WebmaniaBR (Nota Fiscal Eletrônica WooCommerce)") }}
      </h3>
      <Badge :variant="overview.source.active ? 'success' : 'secondary'">{{ sourceState(overview.source.state) }}</Badge>
    </div>

    <ul v-if="scan" class="isce:flex isce:flex-wrap isce:gap-x-6 isce:gap-y-1">
      <li>
        {{ tn("settings.import.source.products", { one: "1 product with NCM", many: "{count} products with NCM" }, scan.productsWithNcm) }}
      </li>
      <li v-if="scan.categoriesWithNcm > 0">
        {{ tn("settings.import.source.categories", { one: "1 category with NCM", many: "{count} categories with NCM" }, scan.categoriesWithNcm) }}
      </li>
      <li v-if="overview.coverage.total > 0 && overview.coverage.withoutNcm > 0" class="isce:text-muted-foreground">
        {{ t("coverage.title", "Product NCM") }}:
        {{ tn("coverage.missing", { one: "1 without NCM", many: "{count} without NCM" }, overview.coverage.withoutNcm) }}
      </li>
    </ul>

    <div v-if="isAnalyzing" class="isce:flex isce:flex-col isce:gap-2">
      <p role="status" aria-live="polite" class="isce:flex isce:items-center isce:gap-2">
        <Loader2 class="isce:size-4 isce:animate-spin isce:text-primary" aria-hidden="true" />
        {{ progressText }}
      </p>
      <Progress
        v-if="scanning && scanning.total > 0"
        :model-value="Math.min(scanning.processed, scanning.total)"
        :max="scanning.total"
        :get-value-label="() => t('settings.import.source.analyzing_short', 'Analyzing your products…')"
        :get-value-text="() => progressText"
      />
      <Progress v-else :model-value="null" class="isce:animate-pulse" :get-value-label="() => progressText" />
    </div>

    <Button v-else variant="ghost" size="sm" class="isce:self-start" :disabled="isScanning" @click="emit('analyze')">
      <Loader2 v-if="isScanning" class="isce:animate-spin" />
      <RefreshCw v-else />
      {{ t("settings.import.source.analyze_again", "Analyze again") }}
    </Button>
  </section>
</template>
