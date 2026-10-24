<script setup lang="ts">
import { useI18n } from "@/composables/useI18n";
import { formatNumber } from "@/lib/utils";
import { useAppStore } from "@/stores/useAppStore";
import type { FiscalImportItemType, FiscalImportRun, NumberingCheck } from "@/types/api";
import { AlertTriangle, ArrowLeft, CheckCircle2, Download, Info, Loader2, SlidersHorizontal } from "lucide-vue-next";
import CfopCard from "./CfopCard.vue";

interface PreviewRow {
  id: string;
  label: string;
  count: number;
  /** Extra line under the label. */
  hint?: string;
  /** The list behind the counter, when there is one. */
  list: FiscalImportItemType | null;
}

/**
 * What the import would do with the choices made. Nothing is written until "Import"; when nothing would be, the
 * preview says so and leads back to the choices or to the summary of the last import.
 */
const props = defineProps<{
  run: FiscalImportRun;
  numbering: NumberingCheck | null;
  isStarting: boolean;
  /** The summary of the last import is a step away: a button goes back to it. */
  hasSummary?: boolean;
}>();

const emit = defineEmits<{
  (e: "start"): void;
  (e: "back"): void;
  (e: "summary"): void;
  (e: "list", type: FiscalImportItemType): void;
}>();

const { t, tn } = useI18n();
const appStore = useAppStore();
const number = (value: number) => formatNumber(value, appStore.locale);

const counts = computed(() => props.run.counts);
/** No product would get or change its NCM with these choices. */
const nothingNew = computed(() => counts.value.writes === 0);

const rows = computed<PreviewRow[]>(() => {
  const c = counts.value;
  const all: PreviewRow[] = [
    {
      id: "fill",
      label: t("settings.import.preview.fill", "Will get an NCM"),
      count: c.fill,
      hint:
        c.fillFixesInvalid > 0
          ? tn(
              "settings.import.preview.fixes_invalid",
              { one: "1 of them fixes an incomplete NCM", many: "{count} of them fix an incomplete NCM" },
              c.fillFixesInvalid,
            )
          : undefined,
      list: null,
    },
    { id: "replace", label: t("settings.import.preview.replace", "Will switch to the WebmaniaBR NCM"), count: c.replace, list: "conflict" },
    { id: "same", label: t("settings.import.preview.same", "Already the same"), count: c.same, list: null },
    { id: "keep", label: t("settings.import.preview.keep", "Already have another NCM and keep it"), count: c.keep, list: "conflict" },
    { id: "hidden", label: t("settings.import.preview.hidden", "Old NCM from Correios Automático"), count: c.hidden, list: "hidden" },
    { id: "review", label: t("settings.import.preview.review", "For you to review"), count: c.review, list: "review" },
    {
      id: "skipped",
      label: t("settings.import.preview.skipped", "Left out"),
      count: c.skipped,
      hint: t(
        "settings.import.preview.skipped_hint",
        "Services, grouped or external products and products whose categories have different NCM codes.",
      ),
      list: "skipped",
    },
    { id: "missing", label: t("settings.import.preview.missing", "Still without NCM"), count: c.missing, list: "missing" },
  ];

  // "Will get an NCM" always shows, even at zero: it is the answer to the question of the screen.
  return all.filter((row) => row.id === "fill" || row.count > 0);
});

function openList(row: PreviewRow) {
  if (row.list) emit("list", row.list);
}
</script>

<template>
  <section class="isce:flex isce:flex-col isce:gap-5" data-import-preview>
    <div v-if="nothingNew" class="isce:flex isce:items-start isce:gap-3" data-preview-nothing>
      <CheckCircle2 class="isce:mt-0.5 isce:size-5 isce:shrink-0 isce:text-success" aria-hidden="true" />
      <div class="isce:flex isce:flex-col isce:gap-1">
        <h3 class="isce:text-base isce:font-semibold isce:outline-none" tabindex="-1" data-import-step-title>
          {{ t("settings.import.preview.nothing_title", "Nothing new to import") }}
        </h3>
        <p class="isce:text-muted-foreground">{{ t("settings.import.preview.nothing_text", "With these choices, no product gets a new NCM.") }}</p>
      </div>
    </div>
    <h3 v-else class="isce:text-base isce:font-semibold isce:outline-none" tabindex="-1" data-import-step-title>
      {{ t("settings.import.preview.title", "Preview: nothing was saved yet") }}
    </h3>

    <dl class="isce:rounded-md isce:border isce:border-input">
      <div
        v-for="row in rows"
        :key="row.id"
        class="isce:flex isce:flex-wrap isce:items-center isce:justify-between isce:gap-x-4 isce:gap-y-1 isce:border-t isce:border-input isce:px-4 isce:py-3 isce:first:border-t-0"
        :data-preview-row="row.id"
      >
        <dt class="isce:min-w-0 isce:flex-1">
          <span :class="row.id === 'fill' && 'isce:font-semibold'">{{ row.label }}</span>
          <span v-if="row.hint" class="isce:block isce:text-xs isce:text-muted-foreground">{{ row.hint }}</span>
        </dt>
        <dd class="isce:flex isce:items-center isce:gap-3">
          <span class="isce:font-semibold isce:tabular-nums" :class="row.id === 'fill' && 'isce:text-lg'">{{ number(row.count) }}</span>
          <Button v-if="row.list && row.count > 0" variant="link" size="sm" class="isce:h-auto isce:px-0" @click="openList(row)">
            {{ t("settings.import.preview.view_list", "See list") }}<span class="isce:sr-only">: {{ row.label }}</span>
          </Button>
        </dd>
      </div>
    </dl>

    <Alert v-if="counts.catchAll" variant="warning" data-preview-catch-all>
      <AlertTriangle />
      <AlertDescription>
        {{
          t(
            "settings.import.preview.catch_all",
            "{ncm} would go to {count} of the {total} products. Check that it is the right classification for all of them.",
            { ncm: counts.catchAll.ncm, count: number(counts.catchAll.count), total: number(counts.catchAll.of) },
          )
        }}
      </AlertDescription>
    </Alert>

    <ul v-if="counts.ignoredInWebmania > 0 || counts.virtual > 0" class="isce:flex isce:flex-col isce:gap-1 isce:text-muted-foreground">
      <li v-if="counts.ignoredInWebmania > 0" class="isce:flex isce:items-start isce:gap-2">
        <Info class="isce:mt-0.5 isce:size-3.5 isce:shrink-0" aria-hidden="true" />
        {{
          tn(
            "settings.import.preview.ignored",
            {
              one: "1 product was left out of the WebmaniaBR invoices; it gets the NCM too.",
              many: "{count} products were left out of the WebmaniaBR invoices; they get the NCM too.",
            },
            counts.ignoredInWebmania,
          )
        }}
      </li>
      <li v-if="counts.virtual > 0" class="isce:flex isce:items-start isce:gap-2">
        <Info class="isce:mt-0.5 isce:size-3.5 isce:shrink-0" aria-hidden="true" />
        {{ tn("settings.import.preview.virtual", { one: "1 virtual product gets the NCM too.", many: "{count} virtual products get the NCM too." }, counts.virtual) }}
      </li>
    </ul>

    <CfopCard v-if="!nothingNew" :check="numbering" />

    <div v-if="nothingNew" class="isce:flex isce:flex-wrap isce:gap-3">
      <Button v-if="hasSummary" @click="emit('summary')">
        <ArrowLeft />
        {{ t("settings.import.result.show", "See the last import") }}
      </Button>
      <Button variant="outline" @click="emit('back')">
        <SlidersHorizontal />
        {{ t("settings.import.preview.change", "Change the choices") }}
      </Button>
    </div>

    <div v-else class="isce:flex isce:flex-wrap isce:gap-3">
      <Button :disabled="isStarting" @click="emit('start')">
        <Loader2 v-if="isStarting" class="isce:animate-spin" />
        <Download v-else />
        {{ tn("settings.import.preview.import", { one: "Import 1 product", many: "Import {count} products" }, counts.writes) }}
      </Button>
      <Button variant="outline" :disabled="isStarting" @click="emit('back')">
        <ArrowLeft />
        {{ t("settings.import.preview.back", "Back") }}
      </Button>
      <Button v-if="hasSummary" variant="ghost" :disabled="isStarting" @click="emit('summary')">
        {{ t("settings.import.result.show", "See the last import") }}
      </Button>
    </div>
  </section>
</template>
