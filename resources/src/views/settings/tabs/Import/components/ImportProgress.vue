<script setup lang="ts">
import { useFiscalImportLabels } from "@/composables/useFiscalImportLabels";
import { useI18n } from "@/composables/useI18n";
import { formatNumber } from "@/lib/utils";
import { useAppStore } from "@/stores/useAppStore";
import type { FiscalImportRun } from "@/types/api";
import { Loader2, Play, SlidersHorizontal, Square, Undo2 } from "lucide-vue-next";

/** Screen readers hear the progress at most this often, or every tenth of the way, and always at the end. */
const ANNOUNCE_EVERY_MS = 10_000;
const ANNOUNCE_EVERY_SHARE = 0.1;

/**
 * A run in progress (preview, import or undo) or one that stopped halfway. The work already done is kept, so a
 * stopped run continues from where it was, or a stopped import is undone. A stopped preview (nothing written) can
 * also be left for the choices or the summary of the last import.
 */
const props = defineProps<{
  run: FiscalImportRun;
  isStopping: boolean;
  isResuming: boolean;
  isUndoing: boolean;
  /** The summary of the last import is a step away: a stopped preview offers a button back to it. */
  hasSummary?: boolean;
}>();

const emit = defineEmits<{
  (e: "stop"): void;
  (e: "resume"): void;
  (e: "undo"): void;
  (e: "back"): void;
  (e: "summary"): void;
}>();

const { t } = useI18n();
const labels = useFiscalImportLabels();
const appStore = useAppStore();
const number = (value: number) => formatNumber(value, appStore.locale);

const isRunning = computed(() => props.run.status === "running");
/** The bar needs a positive maximum and a value inside it. */
const max = computed(() => Math.max(props.run.total, 1));
const processed = computed(() => Math.min(props.run.processed, max.value));

const progressText = computed(() => labels.progress(props.run.mode, number(processed.value), number(props.run.total)));
const stoppedTexts = computed(() => labels.stopped(props.run.mode, number(processed.value), number(props.run.total)));

/**
 * What the live region says. The tab drives a step every fraction of a second, so the visible counter changes all
 * the time; screen readers get it only now and then, and the final state at once.
 */
const announcement = ref("");
let announcedAt = 0;
let announcedProcessed = -1;

watch(
  [() => props.run.processed, () => props.run.status, () => props.run.mode],
  () => {
    if (!isRunning.value) {
      announcement.value = stoppedTexts.value.text;
      announcedProcessed = -1;
      return;
    }

    const now = Date.now();
    const step = Math.max(1, Math.ceil(props.run.total * ANNOUNCE_EVERY_SHARE));

    if (announcedProcessed < 0 || now - announcedAt >= ANNOUNCE_EVERY_MS || processed.value - announcedProcessed >= step) {
      announcement.value = progressText.value;
      announcedAt = now;
      announcedProcessed = processed.value;
    }
  },
  { immediate: true },
);
</script>

<template>
  <section class="isce:flex isce:flex-col isce:gap-4" :data-import-progress="run.status">
    <h3 class="isce:text-base isce:font-semibold isce:outline-none" tabindex="-1" data-import-step-title>
      {{ isRunning ? t("settings.import.progress.label", "Import progress") : stoppedTexts.title }}
    </h3>

    <div class="isce:flex isce:flex-col isce:gap-2">
      <Progress
        :model-value="processed"
        :max="max"
        :get-value-label="() => t('settings.import.progress.label', 'Import progress')"
        :get-value-text="() => progressText"
      />
      <p class="isce:flex isce:items-center isce:gap-2 isce:tabular-nums" data-import-counter>
        <Loader2 v-if="isRunning" class="isce:size-4 isce:animate-spin isce:text-primary" aria-hidden="true" />
        {{ isRunning ? progressText : stoppedTexts.text }}
      </p>
      <p class="isce:sr-only" role="status" aria-live="polite" data-import-announcement>{{ announcement }}</p>
      <p v-if="!isRunning && run.error" class="isce:text-destructive isce:break-words">{{ run.error }}</p>
    </div>

    <template v-if="isRunning">
      <p v-if="run.mode !== 'preview'" class="isce:text-muted-foreground">
        {{ t("settings.import.progress.leave_hint", "You can leave this screen: the import goes on in the background.") }}
      </p>
      <Button variant="outline" class="isce:self-start" :disabled="isStopping" @click="emit('stop')">
        <Loader2 v-if="isStopping" class="isce:animate-spin" />
        <Square v-else />
        {{ t("settings.import.progress.stop", "Stop") }}
      </Button>
    </template>

    <div v-else class="isce:flex isce:flex-wrap isce:gap-3">
      <Button :disabled="isResuming || isUndoing" @click="emit('resume')">
        <Loader2 v-if="isResuming" class="isce:animate-spin" />
        <Play v-else />
        {{ t("settings.import.stopped.resume", "Continue") }}
      </Button>
      <Button v-if="run.mode === 'apply' && run.undoable" variant="ghost" :disabled="isResuming || isUndoing" @click="emit('undo')">
        <Loader2 v-if="isUndoing" class="isce:animate-spin" />
        <Undo2 v-else />
        {{ t("settings.import.result.undo", "Undo this import") }}
      </Button>
      <template v-if="run.mode === 'preview'">
        <Button variant="outline" :disabled="isResuming" @click="emit('back')">
          <SlidersHorizontal />
          {{ t("settings.import.preview.change", "Change the choices") }}
        </Button>
        <Button v-if="hasSummary" variant="ghost" :disabled="isResuming" @click="emit('summary')">
          {{ t("settings.import.result.show", "See the last import") }}
        </Button>
      </template>
    </div>
  </section>
</template>
