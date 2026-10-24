<script setup lang="ts">
import { useI18n } from "@/composables/useI18n";
import { formatNumber } from "@/lib/utils";
import { useAppStore } from "@/stores/useAppStore";
import type { FiscalImportChoices, FiscalImportOverview, FiscalImportScan } from "@/types/api";
import { ArrowLeft, Eye, Loader2 } from "lucide-vue-next";

/**
 * What to bring from WebmaniaBR. The numbers come from the analysis with the default choices: product and
 * category NCM on, the store-wide NCM off, a valid NCM the product already has kept.
 */
const props = defineProps<{
  overview: FiscalImportOverview;
  scan: FiscalImportScan;
  isSubmitting: boolean;
  /** The summary of the last import is a step away: a button goes back to it. */
  hasSummary?: boolean;
}>();

const emit = defineEmits<{
  (e: "preview", choices: FiscalImportChoices): void;
  (e: "summary"): void;
}>();

const { t } = useI18n();
const appStore = useAppStore();
const number = (value: number) => formatNumber(value, appStore.locale);

/** The last choices made (a preview or an import) come back; otherwise the defaults. */
const form = reactive<FiscalImportChoices>({ ...(props.overview.run?.choices ?? props.overview.defaults) });

const counts = computed(() => props.scan.counts);
const productCount = computed(() => counts.value.byOrigin.product + counts.value.byOrigin.variations);
const offersGlobal = computed(() => props.overview.globalNcm?.valid === true);
const hasConflicts = computed(() => counts.value.keep + counts.value.replace > 0);

const conflictChoice = computed({
  get: () => (form.overwrite ? "overwrite" : "keep"),
  set: (value: string) => (form.overwrite = value === "overwrite"),
});

const hiddenChoice = computed({
  get: () => form.hidden,
  set: (value: string) => (form.hidden = value === "keep" ? "keep" : "webmania"),
});
</script>

<template>
  <section class="isce:flex isce:flex-col isce:gap-5" data-import-choices>
    <h3 class="isce:text-base isce:font-semibold isce:outline-none" tabindex="-1" data-import-step-title>
      {{ t("settings.import.choices.title", "What do you want to bring?") }}
    </h3>

    <div class="isce:flex isce:flex-col isce:gap-3">
      <div class="isce:flex isce:items-center isce:gap-3">
        <!-- The product NCM is the import itself: always on. -->
        <Checkbox id="squidcore-import-product" :model-value="true" disabled />
        <Label for="squidcore-import-product" class="isce:text-foreground">
          {{ t("settings.import.choices.product", "Product and variation NCM ({count})", { count: number(productCount) }) }}
        </Label>
      </div>

      <div v-if="scan.categoriesWithNcm > 0" class="isce:flex isce:items-center isce:gap-3">
        <Checkbox id="squidcore-import-category" v-model:model-value="form.useCategory" />
        <Label for="squidcore-import-category" class="isce:text-foreground">
          {{ t("settings.import.choices.category", "Use the category NCM on products without their own ({count})", { count: number(counts.byOrigin.category) }) }}
        </Label>
      </div>

      <div v-if="offersGlobal && overview.globalNcm" class="isce:flex isce:flex-col isce:gap-1">
        <div class="isce:flex isce:items-center isce:gap-3">
          <Checkbox id="squidcore-import-global" v-model:model-value="form.useGlobal" aria-describedby="squidcore-import-global-hint" />
          <Label for="squidcore-import-global" class="isce:text-foreground">
            {{
              t("settings.import.choices.global", "Store default NCM ({ncm}) for the remaining products (up to {count})", {
                ncm: overview.globalNcm.ncm,
                count: number(counts.missing),
              })
            }}
          </Label>
        </div>
        <p id="squidcore-import-global-hint" class="isce:pl-7 isce:text-xs isce:text-muted-foreground">
          {{ t("settings.import.choices.global_hint", "Recommended only when all your products share the same classification.") }}
        </p>
      </div>
    </div>

    <fieldset v-if="hasConflicts">
      <legend class="isce:mb-2 isce:font-medium">{{ t("settings.import.choices.conflict", "When the product already has an NCM") }}</legend>
      <RadioGroup v-model="conflictChoice" class="isce:gap-2">
        <div class="isce:flex isce:items-center isce:gap-3">
          <RadioGroupItem id="squidcore-import-keep" value="keep" />
          <Label for="squidcore-import-keep" class="isce:text-foreground">{{ t("settings.import.choices.keep", "Keep the current one (recommended)") }}</Label>
        </div>
        <div class="isce:flex isce:items-center isce:gap-3">
          <RadioGroupItem id="squidcore-import-overwrite" value="overwrite" />
          <Label for="squidcore-import-overwrite" class="isce:text-foreground">{{ t("settings.import.choices.overwrite", "Use the WebmaniaBR one") }}</Label>
        </div>
      </RadioGroup>
    </fieldset>

    <fieldset v-if="counts.hidden > 0">
      <legend class="isce:mb-2 isce:font-medium">
        {{ t("settings.import.choices.hidden", "Old NCM from Correios Automático ({count})", { count: number(counts.hidden) }) }}
      </legend>
      <RadioGroup v-model="hiddenChoice" class="isce:gap-2">
        <div class="isce:flex isce:items-center isce:gap-3">
          <RadioGroupItem id="squidcore-import-hidden-webmania" value="webmania" />
          <Label for="squidcore-import-hidden-webmania" class="isce:text-foreground">
            {{ t("settings.import.choices.hidden_webmania", "Use the WebmaniaBR one (the one on your invoices)") }}
          </Label>
        </div>
        <div class="isce:flex isce:items-center isce:gap-3">
          <RadioGroupItem id="squidcore-import-hidden-keep" value="keep" />
          <Label for="squidcore-import-hidden-keep" class="isce:text-foreground">
            {{ t("settings.import.choices.hidden_keep", "Keep the Correios Automático one") }}
          </Label>
        </div>
      </RadioGroup>
    </fieldset>

    <div class="isce:flex isce:flex-wrap isce:gap-3">
      <Button :disabled="isSubmitting" @click="emit('preview', { ...form })">
        <Loader2 v-if="isSubmitting" class="isce:animate-spin" />
        <Eye v-else />
        {{ t("settings.import.choices.preview", "See preview") }}
      </Button>
      <Button v-if="hasSummary" variant="outline" :disabled="isSubmitting" @click="emit('summary')">
        <ArrowLeft />
        {{ t("settings.import.result.show", "See the last import") }}
      </Button>
    </div>
  </section>
</template>
