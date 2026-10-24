<script setup lang="ts">
import { alertDialog } from "@/components/infixs/alert-dialog/useAlertDialog";
import WarningNotice from "@/components/shared/WarningNotice.vue";
import { useI18n } from "@/composables/useI18n";
import { resolveErrorMessage } from "@/lib/errors";
import { listCount } from "@/lib/fiscalImport";
import { useFiscalImportStore } from "@/stores/useFiscalImportStore";
import { useStatusStore } from "@/stores/useStatusStore";
import type { FiscalImportChoices, FiscalImportItemType, FiscalImportOverview, FiscalImportRun } from "@/types/api";
import { ArrowLeft, CheckCircle2, FileSearch, Loader2, RefreshCw } from "lucide-vue-next";
import { toast } from "vue-sonner";
import ImportChoices from "./components/ImportChoices.vue";
import ImportItemsDialog, { type ImportItemsList } from "./components/ImportItemsDialog.vue";
import ImportNextSteps from "./components/ImportNextSteps.vue";
import ImportPreview from "./components/ImportPreview.vue";
import ImportProgress from "./components/ImportProgress.vue";
import ImportResult from "./components/ImportResult.vue";
import ImportSourceCard from "./components/ImportSourceCard.vue";

type ImportView = "loading" | "error" | "none" | "analyzing" | "nothing" | "choices" | "preview" | "running" | "stopped" | "done";

/**
 * Settings › Import data: brings the NCM codes saved in WebmaniaBR to the SquidCore field, with a preview before
 * anything is written, progress the owner can leave, a summary with the next steps and an undo.
 */
const { t, tn } = useI18n();
const store = useFiscalImportStore();
const statusStore = useStatusStore();
const { overview, isLoading, loadError, busy, numbering } = storeToRefs(store);

/**
 * A step the owner asked for, over what the state of the import would show: the choices ("Import again", "Back"),
 * the summary of the last import (from a newer preview) or a preview just asked for. Null: the state decides.
 */
type ImportScreen = "choices" | "summary" | "preview";

const screen = ref<ImportScreen | null>(null);
const itemsList = ref<ImportItemsList | null>(null);
const root = ref<HTMLElement | null>(null);

/** Something can change with other choices: a conflict to overwrite, an old Correios NCM or the store-wide NCM. */
function hasChoices(value: FiscalImportOverview): boolean {
  if (!value.scan) return false;

  const counts = value.scan.counts;

  return counts.writes > 0 || counts.keep > 0 || counts.hidden > 0 || (value.globalNcm?.valid === true && counts.missing > 0);
}

/**
 * The run the summary is about: the import or undo that just finished or, behind a newer preview, the last import
 * while it can still be undone (an undone one has no summary left to show).
 */
const resultRun = computed<FiscalImportRun | null>(() => {
  const value = overview.value;
  const run = value?.run ?? null;

  if (run && run.status === "done" && run.mode !== "preview") return run;

  const last = value?.last ?? null;

  return last && (last.undoable || !run) ? last : null;
});

const view = computed<ImportView>(() => {
  const value = overview.value;

  if (!value) return loadError.value && !isLoading.value ? "error" : "loading";

  const run = value.run;
  const preview = run?.mode === "preview" ? run : null;

  if (run?.status === "running") return "running";
  // A paused import or undo waits to be continued; a paused preview can be left for the choices or the summary.
  if (run && (run.status === "stopped" || run.status === "failed") && (!preview || screen.value === null)) return "stopped";

  if (screen.value === "summary" && resultRun.value) return "done";

  if (screen.value !== "choices") {
    // A preview with products to import waits for the owner; one with nothing new gives way to the last import, unless
    // the owner just asked for it.
    if (preview?.status === "ready" && (preview.counts.writes > 0 || screen.value === "preview" || !resultRun.value)) return "preview";
    if (resultRun.value) return "done";
  }

  if (value.source.state === "none" || value.source.state === "installed_unused") return "none";
  if (!value.scan) return "analyzing";

  return hasChoices(value) ? "choices" : "nothing";
});

/** The summary is behind a newer preview: a way back to it shows on the other steps. */
const hasSummary = computed(() => resultRun.value !== null && view.value !== "done");

/** The lists of a run are kept only until the next run starts (a newer preview replaces those of the last import). */
const resultHasLists = computed(() => resultRun.value !== null && resultRun.value.id === overview.value?.run?.id);

function openList(run: FiscalImportRun, type: FiscalImportItemType) {
  itemsList.value = { runId: run.id, type, count: listCount(run.counts, type), overwrite: run.choices.overwrite };
}

// Focus follows the step: the title of the new state, once the first state is on screen.
watch(view, async (next, previous) => {
  if (previous === "loading" || next === "loading") return;

  await nextTick();
  root.value?.querySelector<HTMLElement>("[data-import-step-title]")?.focus();
});

// The numbering check also reads the company tax defaults (CFOP card): needed by the preview and the summary.
watch(
  [view, () => overview.value?.source.hasInvoiceHistory],
  ([current, hasInvoiceHistory]) => {
    const needed = hasInvoiceHistory || current === "preview" || current === "done";

    if (needed && !numbering.value && !store.isLoadingNumbering) store.fetchNumbering().catch(() => {});
  },
  { immediate: true },
);

/** Shows what went wrong and reads the state again: another admin may have started a run. */
function failed(error: unknown) {
  toast.error(resolveErrorMessage(error));
  store.fetchOverview().catch(() => {});
}

async function onPreview(choices: FiscalImportChoices) {
  try {
    await store.preview(choices);
    screen.value = "preview";
  } catch (error) {
    failed(error);
  }
}

async function onStart() {
  if (!overview.value?.run) return;

  try {
    await store.start(overview.value.run.id);
    screen.value = null;
  } catch (error) {
    failed(error);
  }
}

async function onStop() {
  if (!overview.value?.run) return;

  try {
    await store.stop(overview.value.run.id);
  } catch (error) {
    failed(error);
  }
}

async function onResume() {
  if (!overview.value?.run) return;

  try {
    await store.resume(overview.value.run.id);
  } catch (error) {
    failed(error);
  }
}

async function onAnalyze() {
  try {
    await store.scan();
  } catch (error) {
    failed(error);
  }
}

async function onPush() {
  if (!resultRun.value) return;

  try {
    const result = await store.push(resultRun.value.id);

    if (result.queued) {
      toast.success(t("settings.import.result.push_queued", "NCM codes queued to SquidCore."));
    } else if (result.blockedBy === "not_connected") {
      toast.info(t("settings.import.result.not_connected", "As soon as you connect, SquidCore receives these NCM codes."));
    } else if (result.blockedBy === "catalog_off") {
      toast.info(t("settings.import.result.catalog_off", 'Turn on "Sync catalog" to take these NCM codes to SquidCore.'));
    }
  } catch (error) {
    failed(error);
  }
}

/** Undo asks first: in this version the NCM already sent to SquidCore stays there. */
function onUndo(run: FiscalImportRun) {
  alertDialog.question(
    t("settings.import.undo.title", "Undo this import?"),
    run.pushedFilled > 0
      ? tn(
          "settings.import.undo.squidcore",
          {
            one: "In SquidCore, the product that already received the NCM keeps it: adjust it in the panel if needed.",
            many: "In SquidCore, the {count} products that already received the NCM keep it: adjust them in the panel if needed.",
          },
          run.pushedFilled,
        )
      : "",
    {
      description: t(
        "settings.import.undo.text",
        "Products go back to the NCM they had here in the store. The ones you edited afterwards stay as they are.",
      ),
      confirmText: t("settings.import.undo.confirm", "Undo"),
      onConfirm: async () => {
        await store.undo(run.id);
        screen.value = null;
      },
    },
  );
}

function onAgain() {
  screen.value = "choices";

  // A finished import cleared the analysis: ask for a new one when none is on the way.
  if (overview.value && !overview.value.scan && !overview.value.scanning) onAnalyze();
}

onMounted(async () => {
  try {
    await store.activate();
  } catch {
    return;
  }

  // Opening the tab clears the "import finished" notice of who started it: the other pages stop showing it.
  if (statusStore.fiscalImport?.notices.some((notice) => notice.id === "fiscal-import-done")) {
    statusStore.fetchStatus().catch(() => {});
  }
});

onBeforeUnmount(() => {
  store.deactivate();
});
</script>

<template>
  <div ref="root" class="isce:flex isce:flex-col isce:gap-6">
    <div class="isce:flex isce:flex-col isce:gap-1">
      <h2 class="isce:text-lg isce:font-semibold">{{ t("settings.import.title", "Import data") }}</h2>
      <p class="isce:text-sm isce:text-muted-foreground">
        {{ t("settings.import.description", "Bring the tax data you already have in another invoice plugin.") }}
      </p>
    </div>

    <div v-if="view === 'loading'" class="isce:max-w-3xl isce:flex isce:flex-col isce:gap-4">
      <Skeleton class="isce:h-28 isce:w-full" />
      <Skeleton class="isce:h-5 isce:w-72" />
      <Skeleton class="isce:h-5 isce:w-96" />
      <Skeleton class="isce:h-9 isce:w-40" />
    </div>

    <WarningNotice v-else-if="view === 'error'">
      {{ t("settings.import.load_error", "Could not load the import.") }} {{ loadError }}
      <template #action>
        <Button size="sm" variant="outline" :disabled="isLoading" @click="store.fetchOverview().catch(() => {})">
          <Loader2 v-if="isLoading" class="isce:animate-spin" />
          <RefreshCw v-else />
          {{ t("global.refresh", "Refresh") }}
        </Button>
      </template>
    </WarningNotice>

    <div v-else-if="overview" class="isce:max-w-3xl isce:flex isce:flex-col isce:gap-6">
      <section
        v-if="view === 'none'"
        class="isce:flex isce:items-start isce:gap-3 isce:rounded-md isce:border isce:border-input isce:p-5"
        data-import-view="none"
      >
        <FileSearch class="isce:mt-0.5 isce:size-5 isce:shrink-0 isce:text-muted-foreground" aria-hidden="true" />
        <div class="isce:flex isce:flex-col isce:gap-1">
          <h3 class="isce:text-base isce:font-semibold isce:outline-none" tabindex="-1" data-import-step-title>
            {{ t("settings.import.none.title", "No other invoice plugin with data to bring") }}
          </h3>
          <p class="isce:text-muted-foreground">
            {{ t("settings.import.none.text", "When the store uses WebmaniaBR, its NCM codes show up here, ready to import.") }}
          </p>
        </div>
      </section>

      <template v-else-if="view === 'analyzing' || view === 'nothing' || view === 'choices'">
        <ImportSourceCard :overview="overview" :titled="view === 'analyzing'" :is-scanning="busy === 'scan'" @analyze="onAnalyze" />

        <section
          v-if="view === 'nothing'"
          class="isce:flex isce:items-start isce:gap-3 isce:rounded-md isce:border isce:border-input isce:p-5"
          data-import-view="nothing"
        >
          <CheckCircle2 class="isce:mt-0.5 isce:size-5 isce:shrink-0 isce:text-success" aria-hidden="true" />
          <div class="isce:flex isce:flex-col isce:gap-1">
            <h3 class="isce:text-base isce:font-semibold isce:outline-none" tabindex="-1" data-import-step-title>
              {{ t("settings.import.nothing.title", "Your products already have their NCM") }}
            </h3>
            <p class="isce:text-muted-foreground">{{ t("settings.import.nothing.text", "Nothing new to bring from WebmaniaBR.") }}</p>
          </div>
        </section>

        <ImportChoices
          v-else-if="view === 'choices' && overview.scan"
          :overview="overview"
          :scan="overview.scan"
          :is-submitting="busy === 'preview'"
          :has-summary="hasSummary"
          @preview="onPreview"
          @summary="screen = 'summary'"
        />

        <Button v-if="view !== 'choices' && hasSummary" variant="outline" class="isce:self-start" @click="screen = 'summary'">
          <ArrowLeft />
          {{ t("settings.import.result.show", "See the last import") }}
        </Button>
      </template>

      <ImportPreview
        v-else-if="view === 'preview' && overview.run"
        :run="overview.run"
        :numbering="numbering"
        :is-starting="busy === 'start'"
        :has-summary="hasSummary"
        @start="onStart"
        @back="screen = 'choices'"
        @summary="screen = 'summary'"
        @list="(type) => overview?.run && openList(overview.run, type)"
      />

      <ImportProgress
        v-else-if="(view === 'running' || view === 'stopped') && overview.run"
        :run="overview.run"
        :is-stopping="busy === 'stop'"
        :is-resuming="busy === 'resume'"
        :is-undoing="busy === 'undo'"
        :has-summary="hasSummary"
        @stop="onStop"
        @resume="onResume"
        @undo="overview?.run && onUndo(overview.run)"
        @back="screen = 'choices'"
        @summary="screen = 'summary'"
      />

      <template v-else-if="view === 'done' && resultRun">
        <ImportResult
          :run="resultRun"
          :push="overview.push"
          :coverage="overview.coverage"
          :is-pushing="busy === 'push'"
          :is-undoing="busy === 'undo'"
          :has-lists="resultHasLists"
          @push="onPush"
          @again="onAgain"
          @undo="resultRun && onUndo(resultRun)"
          @list="(type) => resultRun && openList(resultRun, type)"
        />
        <ImportNextSteps :overview="overview" :numbering="numbering" />
      </template>
    </div>

    <ImportItemsDialog v-model:list="itemsList" />
  </div>
</template>
