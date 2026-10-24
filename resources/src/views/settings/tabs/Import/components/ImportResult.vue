<script setup lang="ts">
import { useI18n } from "@/composables/useI18n";
import { formatDateTime, formatNumber } from "@/lib/utils";
import { toRoute } from "@/routes/helpers";
import { useAppStore } from "@/stores/useAppStore";
import type { FiscalImportItemType, FiscalImportPush, FiscalImportRun, NcmCoverageStatus } from "@/types/api";
import { CheckCircle2, CloudUpload, Loader2, PlugZap, RotateCcw, Settings, Undo2 } from "lucide-vue-next";

/**
 * Summary of the last finished import (or undo): what changed in the store, how the NCM reach SquidCore and what
 * is left for the store owner.
 */
const props = withDefaults(
  defineProps<{
    run: FiscalImportRun;
    push: FiscalImportPush;
    coverage: NcmCoverageStatus;
    isPushing: boolean;
    isUndoing: boolean;
    /** The lists of the run are still kept (a newer preview replaces them): the "See list" links show. */
    hasLists?: boolean;
  }>(),
  { hasLists: true },
);

const emit = defineEmits<{
  (e: "push"): void;
  (e: "again"): void;
  (e: "undo"): void;
  (e: "list", type: FiscalImportItemType): void;
}>();

const { t, tn } = useI18n();
const appStore = useAppStore();
const connectionRoute = toRoute("settings-connection");
const syncSettingsRoute = toRoute("settings-sync");

const number = (value: number) => formatNumber(value, appStore.locale);
const counts = computed(() => props.run.counts);
const isUndo = computed(() => props.run.mode === "undo");

const title = computed(() =>
  isUndo.value
    ? tn("settings.import.undo.done", { one: "1 product went back to its previous NCM.", many: "{count} products went back to their previous NCM." }, counts.value.writes)
    : tn("settings.import.result.done", { one: "Done! 1 product now has an NCM.", many: "Done! {count} products now have an NCM." }, counts.value.writes),
);

/** The push button only helps when SquidCore can receive the NCM now and nothing is being sent. */
const canPush = computed(() => !isUndo.value && !props.push.pending && !props.push.running);
</script>

<template>
  <section class="isce:flex isce:flex-col isce:gap-4" :data-import-result="run.mode">
    <h3 class="isce:flex isce:items-center isce:gap-2 isce:text-base isce:font-semibold isce:outline-none" tabindex="-1" data-import-step-title>
      <CheckCircle2 class="isce:size-5 isce:shrink-0 isce:text-success" aria-hidden="true" />
      {{ title }}
    </h3>

    <ul class="isce:flex isce:flex-col isce:gap-2">
      <template v-if="!isUndo">
        <li v-if="push.pending && push.blockedBy === 'not_connected'" class="isce:flex isce:flex-wrap isce:items-center isce:gap-3" data-push="not_connected">
          <span>{{ t("settings.import.result.not_connected", "As soon as you connect, SquidCore receives these NCM codes.") }}</span>
          <Button v-if="connectionRoute" size="sm" variant="outline" as="a" :href="connectionRoute.href" @click="connectionRoute.onClick">
            <PlugZap />
            {{ t("connection.connect", "Connect") }}
          </Button>
        </li>
        <li v-else-if="push.pending && push.blockedBy === 'catalog_off'" class="isce:flex isce:flex-wrap isce:items-center isce:gap-3" data-push="catalog_off">
          <span>{{ t("settings.import.result.catalog_off", 'Turn on "Sync catalog" to take these NCM codes to SquidCore.') }}</span>
          <Button v-if="syncSettingsRoute" size="sm" variant="outline" as="a" :href="syncSettingsRoute.href" @click="syncSettingsRoute.onClick">
            <Settings />
            {{ t("sync.change_options", "Change options") }}
          </Button>
        </li>
        <li v-else-if="push.queued > 0" class="isce:flex isce:items-center isce:gap-2" data-push="queued">
          <Loader2 v-if="push.running" class="isce:size-4 isce:animate-spin isce:text-primary" aria-hidden="true" />
          {{ tn("settings.import.result.queued", { one: "1 product is in the queue to SquidCore.", many: "{count} products are in the queue to SquidCore." }, push.queued) }}
        </li>

        <li v-if="counts.review > 0" class="isce:flex isce:flex-wrap isce:items-center isce:gap-x-3" data-result="review">
          <span>{{ tn("settings.import.result.review", { one: "1 product is left for you to review.", many: "{count} products are left for you to review." }, counts.review) }}</span>
          <Button v-if="hasLists" variant="link" size="sm" class="isce:h-auto isce:px-0" @click="emit('list', 'review')">
            {{ t("settings.import.preview.view_list", "See list") }}<span class="isce:sr-only">: {{ t("settings.import.preview.review", "For you to review") }}</span>
          </Button>
        </li>
        <li v-if="counts.changedDuring > 0" class="isce:flex isce:flex-wrap isce:items-center isce:gap-x-3" data-result="changed">
          <span>
            {{
              tn(
                "settings.import.result.changed",
                {
                  one: "1 product was saved during the import: run it again to include it.",
                  many: "{count} products were saved during the import: run it again to include them.",
                },
                counts.changedDuring,
              )
            }}
          </span>
          <Button v-if="hasLists" variant="link" size="sm" class="isce:h-auto isce:px-0" @click="emit('list', 'changed')">
            {{ t("settings.import.preview.view_list", "See list") }}<span class="isce:sr-only">: {{ t("settings.import.reasons.edited_during_import", "Saved during the import.") }}</span>
          </Button>
        </li>
        <!-- The count after the import runs in the background: until it lands, "now" would be the old number. -->
        <li v-if="run.coverageBefore !== null && coverage.computing" class="isce:flex isce:items-center isce:gap-2 isce:tabular-nums" data-result="coverage-counting">
          <Loader2 class="isce:size-4 isce:animate-spin isce:text-primary" aria-hidden="true" />
          {{ t("settings.import.result.coverage_counting", "Without NCM: {before} before; counting the products now…", { before: number(run.coverageBefore) }) }}
        </li>
        <li v-else-if="run.coverageBefore !== null && coverage.computedAt" class="isce:tabular-nums" data-result="coverage">
          {{ t("settings.import.result.coverage", "Without NCM: {before} before, {after} now", { before: number(run.coverageBefore), after: number(coverage.withoutNcm) }) }}
        </li>
      </template>

      <li v-else-if="counts.keptEdited > 0">
        {{ tn("settings.import.undo.kept", { one: "1 product edited later was kept.", many: "{count} products edited later were kept." }, counts.keptEdited) }}
      </li>

      <li v-if="run.finishedAt" class="isce:text-muted-foreground">
        {{
          isUndo
            ? t("settings.import.result.last_undo", "Undone on {date}", { date: formatDateTime(run.finishedAt, appStore.locale) })
            : t("settings.import.result.last_run", "Last import: {date}", { date: formatDateTime(run.finishedAt, appStore.locale) })
        }}
      </li>
    </ul>

    <div class="isce:flex isce:flex-wrap isce:gap-3">
      <Button v-if="canPush" :disabled="isPushing" @click="emit('push')">
        <Loader2 v-if="isPushing" class="isce:animate-spin" />
        <CloudUpload v-else />
        {{ t("settings.import.result.push", "Send NCM to SquidCore") }}
      </Button>
      <Button variant="outline" @click="emit('again')">
        <RotateCcw />
        {{ t("settings.import.result.again", "Import again") }}
      </Button>
      <Button v-if="!isUndo && run.undoable" variant="ghost" :disabled="isUndoing" @click="emit('undo')">
        <Loader2 v-if="isUndoing" class="isce:animate-spin" />
        <Undo2 v-else />
        {{ t("settings.import.result.undo", "Undo this import") }}
      </Button>
    </div>
  </section>
</template>
