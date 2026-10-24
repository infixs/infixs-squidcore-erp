<script setup lang="ts">
import { useI18n } from "@/composables/useI18n";
import { useAppStore } from "@/stores/useAppStore";
import type { NumberingCheck } from "@/types/api";
import { ExternalLink, Receipt } from "lucide-vue-next";

/** SquidCore defaults when the company tax settings cannot be read (not connected yet). */
const DEFAULT_CFOP = { internal: "5102", interstate: "6102" };

/**
 * The CFOP does not come from WebmaniaBR: it lives in its tax class, in the Webmania cloud. The card tells which
 * CFOP SquidCore uses and where to adjust it, with the WebmaniaBR operation nature and origin to compare.
 */
const props = defineProps<{
  /** Null while the numbering check (which also reads the company defaults) is not loaded. */
  check: NumberingCheck | null;
}>();

const { t } = useI18n();
const appStore = useAppStore();

const company = computed(() => props.check?.companyDefaults ?? null);
const fiscalSettingsUrl = computed(() => props.check?.fiscalSettingsUrl || `${appStore.appUrl.replace(/\/+$/, "")}/settings/fiscal`);
const webmaniaDefaults = computed(() => props.check?.webmaniaDefaults ?? null);
</script>

<template>
  <section class="isce:flex isce:flex-col isce:gap-2 isce:rounded-md isce:border isce:border-input isce:p-4" data-cfop-card>
    <h4 class="isce:flex isce:items-center isce:gap-2 isce:font-semibold">
      <Receipt class="isce:size-4 isce:shrink-0 isce:text-muted-foreground" />
      {{ t("settings.import.cfop.title", "CFOP") }}
    </h4>
    <p>
      {{
        t(
          "settings.import.cfop.text",
          "WebmaniaBR keeps the CFOP in its tax class, outside WordPress. SquidCore uses {internal} (in-state) and {interstate} (out-of-state) by default, and you can adjust it in the company or in any product.",
          {
            internal: company?.cfopInternal || DEFAULT_CFOP.internal,
            interstate: company?.cfopInterstate || DEFAULT_CFOP.interstate,
          },
        )
      }}
    </p>
    <p v-if="webmaniaDefaults?.operationNature && webmaniaDefaults.origin !== null" class="isce:text-muted-foreground">
      {{
        t("settings.import.next.company_defaults", 'Check in SquidCore the operation nature ("{nature}") and the default origin ({origin}).', {
          nature: webmaniaDefaults.operationNature,
          origin: webmaniaDefaults.origin,
        })
      }}
    </p>
    <Button variant="outline" size="sm" class="isce:self-start" as="a" :href="fiscalSettingsUrl" target="_blank" rel="noopener noreferrer">
      <ExternalLink />
      {{ t("settings.import.cfop.action", "Check the tax defaults in SquidCore") }}
    </Button>
  </section>
</template>
