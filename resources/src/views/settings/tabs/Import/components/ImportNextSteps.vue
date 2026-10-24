<script setup lang="ts">
import AiNcmAction from "@/components/fiscal/AiNcmAction.vue";
import NumberingCard from "@/components/fiscal/NumberingCard.vue";
import WarningNotice from "@/components/shared/WarningNotice.vue";
import { useI18n } from "@/composables/useI18n";
import type { FiscalImportOverview, NumberingCheck } from "@/types/api";
import { ArrowRight, Flag, IdCard, ListFilter, Sparkles } from "lucide-vue-next";
import CfopCard from "./CfopCard.vue";

/**
 * What is left to move the invoices from WebmaniaBR to SquidCore safely. Each step shows while its condition holds
 * and goes away by itself once solved; nothing is stored as "done".
 */
const props = defineProps<{
  overview: FiscalImportOverview;
  numbering: NumberingCheck | null;
}>();

const { t, tn } = useI18n();

const checks = computed(() => props.overview.checks);
const coverage = computed(() => props.overview.coverage);

/** WebmaniaBR can go once one invoice per order, the numbering and the customer document are sorted out. */
const canFinish = computed(
  () =>
    props.overview.source.active &&
    !checks.value.doubleEmission &&
    !checks.value.checkoutFields &&
    (props.numbering?.alerts.length ?? 0) === 0,
);
</script>

<template>
  <section class="isce:flex isce:flex-col isce:gap-3" data-import-next>
    <h3 class="isce:text-base isce:font-semibold">{{ t("settings.import.next.title", "Next steps") }}</h3>

    <WarningNotice v-if="checks.doubleEmission" data-next="double-emission">
      <strong>{{ t("notices.double_emission.title", "One invoice per order") }}.</strong>
      {{ t("notices.double_emission.text", "To keep one invoice per order, leave the automatic emission on in SquidCore only.") }}
      <template #action>
        <Button size="sm" variant="outline" as="a" :href="overview.source.settingsUrl">
          {{ t("notices.double_emission.action", "Open WebmaniaBR settings") }}
        </Button>
      </template>
    </WarningNotice>

    <NumberingCard v-if="numbering" :check="numbering" />

    <div v-if="checks.checkoutFields" class="isce:flex isce:items-start isce:gap-3 isce:rounded-md isce:border isce:border-input isce:p-4" data-next="checkout-fields">
      <IdCard class="isce:mt-0.5 isce:size-4 isce:shrink-0 isce:text-muted-foreground" aria-hidden="true" />
      <p>
        {{
          t(
            "settings.import.next.checkout_fields",
            "WebmaniaBR asks for the CPF/CNPJ at checkout today. Before deactivating it, activate Infixs Checkout Fields or Brazilian Market so the invoices keep the customer document.",
          )
        }}
      </p>
    </div>

    <CfopCard :check="numbering" />

    <div
      v-if="coverage.computing || coverage.withoutNcm > 0"
      class="isce:flex isce:flex-col isce:gap-3 isce:rounded-md isce:border isce:border-input isce:p-4"
      data-next="missing"
    >
      <p class="isce:flex isce:items-start isce:gap-3">
        <Sparkles class="isce:mt-0.5 isce:size-4 isce:shrink-0 isce:text-muted-foreground" aria-hidden="true" />
        <!-- While the products are counted again, no number: the last one is from before the import. -->
        {{
          coverage.computing
            ? t("settings.import.next.missing_counting", "SquidCore can suggest, when issuing, the NCM of the products still without one.")
            : tn(
                "settings.import.next.missing",
                {
                  one: "SquidCore can suggest the NCM of the product still without one when issuing.",
                  many: "SquidCore can suggest the NCM of the {count} products still without one when issuing.",
                },
                coverage.withoutNcm,
              )
        }}
      </p>
      <div class="isce:flex isce:flex-wrap isce:items-center isce:gap-2 isce:pl-7">
        <AiNcmAction :state="coverage.ai" />
        <Button variant="ghost" size="sm" as="a" :href="coverage.listUrl">
          <ListFilter />
          {{ t("coverage.view_missing", "See products without NCM") }}
        </Button>
      </div>
    </div>

    <div v-if="canFinish" class="isce:flex isce:flex-col isce:gap-3 isce:rounded-md isce:border isce:border-input isce:p-4" data-next="finish">
      <p class="isce:flex isce:items-center isce:gap-3 isce:font-semibold">
        <Flag class="isce:size-4 isce:shrink-0 isce:text-muted-foreground" aria-hidden="true" />
        {{ t("settings.import.next.finish_title", "Finish the migration") }}
      </p>
      <p class="isce:pl-7">
        {{
          t(
            "settings.import.next.finish_text",
            "Once your invoices go out through SquidCore, deactivate WebmaniaBR. Its NCM codes stay saved in case you want to import again.",
          )
        }}
      </p>
      <div class="isce:pl-7">
        <Button size="sm" variant="outline" as="a" :href="overview.urls.plugins">
          {{ t("settings.import.next.finish_action", "Go to Plugins") }}
          <ArrowRight />
        </Button>
      </div>
    </div>
  </section>
</template>
