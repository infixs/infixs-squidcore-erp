<script setup lang="ts">
import AiNcmAction from "@/components/fiscal/AiNcmAction.vue";
import NumberingCard from "@/components/fiscal/NumberingCard.vue";
import OptionsGroup from "@/components/shared/OptionsGroup.vue";
import WarningNotice from "@/components/shared/WarningNotice.vue";
import { useInvoiceLabels } from "@/composables/useInvoiceLabels";
import { AI_NCM_FOCUS, INVOICE_DELAY_HOURS } from "@/lib/invoices";
import { useI18n } from "@/composables/useI18n";
import { resolveErrorMessage } from "@/lib/errors";
import { useStatusPolling } from "@/composables/useStatusPolling";
import { useOAuthConnect } from "@/composables/useOAuthConnect";
import { toRoute } from "@/routes/helpers";
import { useAppStore } from "@/stores/useAppStore";
import { useFiscalImportStore } from "@/stores/useFiscalImportStore";
import { useSettingsStore } from "@/stores/useSettingsStore";
import { useStatusStore } from "@/stores/useStatusStore";
import type { AiNcmState, InvoiceSettings } from "@/types/api";
import type { AcceptableValue } from "reka-ui";
import { ExternalLink, Info, ListFilter, Loader2, PlugZap, RefreshCw } from "lucide-vue-next";
import { toast } from "vue-sonner";

const { t, tn } = useI18n();
const { delayLabel } = useInvoiceLabels();
const appStore = useAppStore();
const statusStore = useStatusStore();
const settingsStore = useSettingsStore();
const fiscalImportStore = useFiscalImportStore();
const { connection, ncmCoverage, isTesting } = storeToRefs(statusStore);
const { numbering } = storeToRefs(fiscalImportStore);
const route = useRoute();
const router = useRouter();
const { invoices, integrations, sync, isLoading, hasLoaded, isSavingInvoices } = storeToRefs(settingsStore);

const connectionRoute = toRoute("settings-connection");
const { isStarting, startOAuth } = useOAuthConnect();

const form = ref<InvoiceSettings>({ ...invoices.value });

const shouldShowSkeleton = computed(() => isLoading.value && !hasLoaded.value);
const correios = computed(() => integrations.value.correiosAutomatico);
const aiNcmAvailable = computed(() => connection.value?.aiNcmAvailable ?? false);
/** Waiting only applies to invoices issued automatically, by a PRO that can wait. */
const canWaitForInvoice = computed(() => correios.value.supportsWait && form.value.enabled);
/** Guarded: a cached bundle may meet a backend without the WebmaniaBR detection. */
const webmania = computed(() => integrations.value.webmaniaBR ?? null);
/** WebmaniaBR also issues automatically: two invoices per order unless one side is turned off. */
const isDoubleEmission = computed(() => !!webmania.value?.autoEmission && form.value.enabled);
/** Follows the form, so the action matches the checkbox before saving. */
const aiState = computed<AiNcmState>(() => (!aiNcmAvailable.value ? "unavailable" : form.value.autoFillNcmWithAi ? "on" : "off"));
/** A new count is on its way (after an import, for example): the number shown would be the old one. */
const isCountingNcm = computed(() => ncmCoverage.value?.computing ?? false);

useStatusPolling(() => isCountingNcm.value);

watch(invoices, (value) => {
  form.value = { ...value };
});

/** Asks SquidCore again whether the organization has an AI, right after connecting OpenAI or Claude there. */
async function recheckAi() {
  try {
    await statusStore.testConnection();
  } catch (error) {
    toast.error(resolveErrorMessage(error));
    return;
  }

  if (aiNcmAvailable.value) {
    toast.success(t("settings.invoices.ai_ncm_found", "SquidCore AI found: you can turn on the NCM fill now."));
  } else {
    toast.info(
      t(
        "settings.invoices.ai_ncm_still_missing",
        "No AI connected in SquidCore yet. Connect OpenAI or Claude in Integrations and check again.",
      ),
    );
  }
}

function onDelayChange(value: AcceptableValue) {
  form.value.delayHours = Number(value);
}

// The numbering where SquidCore continues only matters to a store with WebmaniaBR invoices.
watch(
  () => webmania.value?.hasInvoiceHistory ?? false,
  (hasInvoiceHistory) => {
    if (hasInvoiceHistory && !numbering.value) fiscalImportStore.fetchNumbering().catch(() => {});
  },
  { immediate: true },
);

/**
 * "Turn on automatic AI filling" elsewhere in the app lands here with `focus=ai-ncm`: point at the option, once the
 * settings and the connection (which enables the option) are known.
 */
async function focusRequestedOption() {
  if (route.query.focus !== AI_NCM_FOCUS || !hasLoaded.value || !connection.value) return;

  await nextTick();
  const option = document.getElementById("squidcore-invoices-ai-ncm");
  option?.scrollIntoView({ block: "center" });
  option?.focus();

  const query = Object.fromEntries(Object.entries(route.query).filter(([key]) => key !== "focus"));
  router.replace({ query });
}

watch([() => route.query.focus, hasLoaded, connection], focusRequestedOption);

onMounted(async () => {
  try {
    await settingsStore.fetchSettings(true);
  } catch {
    toast.error(t("settings.load_error", "Error loading settings"));
  }

  focusRequestedOption();
});

async function save() {
  try {
    await settingsStore.saveInvoices(form.value);
    toast.success(t("settings.saved", "Settings saved successfully"));
  } catch {
    toast.error(t("settings.save_error", "Error saving settings"));
  }
}
</script>

<template>
  <div class="isce:space-y-6">
    <div class="isce:space-y-1">
      <h2 class="isce:text-lg isce:font-semibold">
        {{ t("settings.invoices.title", "Invoices (NF-e)") }}
      </h2>
      <p class="isce:text-sm isce:text-muted-foreground">
        {{ t("settings.invoices.description", "SquidCore issues the NF-e of the paid orders with the tax data of your organization.") }}
      </p>
    </div>

    <WarningNotice v-if="!appStore.hasWooCommerce">
      {{ t("invoices.woocommerce_inactive", "WooCommerce is not active. Invoices are issued for WooCommerce orders.") }}
    </WarningNotice>

    <WarningNotice v-if="connection && !connection.connected">
      {{ t("settings.invoices.not_connected", "This store is not connected to SquidCore. No invoice is issued until the connection is confirmed.") }}
      <template #action>
        <Button v-if="connectionRoute" size="sm" as="a" :href="connectionRoute.href" @click="connectionRoute.onClick">
          {{ t("connection.connect", "Connect") }}
        </Button>
      </template>
    </WarningNotice>

    <WarningNotice v-if="connection?.invoicePermission === false && connection.method === 'oauth'">
      {{
        t(
          "settings.invoices.missing_permission_oauth",
          "The SquidCore connection cannot issue invoices: the NF-e permissions were not approved. Reconnect with SquidCore and approve every permission.",
        )
      }}
      <template #action>
        <Button size="sm" :disabled="isStarting" @click="startOAuth(true)">
          <Loader2 v-if="isStarting" class="isce:animate-spin" />
          <PlugZap v-else />
          {{ t("connection.reconnect_with_squidcore", "Reconnect with SquidCore") }}
        </Button>
      </template>
    </WarningNotice>

    <WarningNotice v-else-if="connection?.invoicePermission === false">
      {{
        t(
          "settings.invoices.missing_permission",
          "The saved access token cannot issue invoices: it was generated before NF-e support. Generate a new token in SquidCore and save it in Connection.",
        )
      }}
      <template #action>
        <Button size="sm" as="a" :href="appStore.integrationsUrl" target="_blank" rel="noopener noreferrer">
          <ExternalLink />
          {{ t("help.generate_token", "Generate token in SquidCore") }}
        </Button>
      </template>
    </WarningNotice>

    <WarningNotice v-if="hasLoaded && !sync.orders && form.enabled">
      {{
        t(
          "settings.invoices.orders_sync_off",
          "Order sync is turned off. Each order is still sent to SquidCore right before its invoice is issued.",
        )
      }}
    </WarningNotice>

    <div v-if="shouldShowSkeleton" class="isce:space-y-6">
      <div v-for="i in 4" :key="i" class="isce:flex isce:gap-6">
        <Skeleton class="isce:h-5 isce:w-40" />
        <div class="isce:flex-1 isce:max-w-[500px] isce:space-y-2">
          <Skeleton class="isce:h-5 isce:w-72" />
          <Skeleton class="isce:h-4 isce:w-80" />
        </div>
      </div>
    </div>

    <template v-else>
      <OptionsGroup :title="t('settings.invoices.automatic', 'Automatic emission')">
        <div class="isce:flex isce:items-center isce:gap-3">
          <Checkbox id="squidcore-invoices-enabled" v-model:model-value="form.enabled" />
          <Label for="squidcore-invoices-enabled" class="isce:text-foreground">
            {{ t("settings.invoices.enabled_label", "Issue the NF-e automatically after the payment") }}
          </Label>
        </div>
        <p class="isce:text-xs isce:text-muted-foreground">
          {{
            t(
              "settings.invoices.enabled_hint",
              "Turned off, invoices are issued only when you click \"Issue now\" on the order screen.",
            )
          }}
        </p>
        <WarningNotice v-if="isDoubleEmission && webmania" data-double-emission>
          <strong>{{ t("notices.double_emission.title", "One invoice per order") }}.</strong>
          {{ t("notices.double_emission.text", "To keep one invoice per order, leave the automatic emission on in SquidCore only.") }}
          <template #action>
            <Button size="sm" variant="outline" as="a" :href="webmania.settingsUrl">
              {{ t("notices.double_emission.action", "Open WebmaniaBR settings") }}
            </Button>
          </template>
        </WarningNotice>
        <NumberingCard v-if="webmania?.hasInvoiceHistory && numbering" :check="numbering" />
      </OptionsGroup>

      <OptionsGroup :title="t('settings.invoices.when', 'When to issue')">
        <Select :model-value="String(form.delayHours)" :disabled="!form.enabled" @update:model-value="onDelayChange">
          <SelectTrigger id="squidcore-invoices-delay" class="isce:w-72" :aria-label="t('settings.invoices.when', 'When to issue')">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem v-for="hours in INVOICE_DELAY_HOURS" :key="hours" :value="String(hours)">
              {{ delayLabel(hours) }}
            </SelectItem>
          </SelectContent>
        </Select>
        <p class="isce:text-xs isce:text-muted-foreground">
          {{
            t(
              "settings.invoices.delay_hint",
              "Waiting gives time to change or cancel the order before the invoice exists. Orders cancelled while waiting get no invoice.",
            )
          }}
        </p>
      </OptionsGroup>

      <OptionsGroup :title="t('settings.invoices.ncm', 'Missing NCM')">
        <div
          v-if="ncmCoverage && (isCountingNcm || (ncmCoverage.total > 0 && ncmCoverage.withoutNcm > 0))"
          class="isce:flex isce:flex-wrap isce:items-center isce:gap-x-3 isce:gap-y-1"
          data-ncm-missing
        >
          <span v-if="isCountingNcm" class="isce:flex isce:items-center isce:gap-2 isce:text-muted-foreground" data-ncm-counting>
            <Loader2 class="isce:size-3.5 isce:animate-spin" aria-hidden="true" />
            {{ t("coverage.computing", "Counting your products…") }}
          </span>
          <span v-else class="isce:font-medium">
            {{
              tn(
                "settings.invoices.ncm_missing",
                { one: "1 product is still without NCM.", many: "{count} products are still without NCM." },
                ncmCoverage.withoutNcm,
              )
            }}
          </span>
          <Button variant="link" size="sm" class="isce:h-auto isce:px-0" as="a" :href="ncmCoverage.listUrl">
            <ListFilter />
            {{ t("coverage.view_missing", "See products without NCM") }}
          </Button>
        </div>
        <div class="isce:flex isce:items-center isce:gap-3">
          <!-- Without AI the option has no effect: shown off, while the saved value is kept. -->
          <Checkbox
            id="squidcore-invoices-ai-ncm"
            :model-value="aiNcmAvailable && form.autoFillNcmWithAi"
            :disabled="!aiNcmAvailable"
            @update:model-value="(value) => (form.autoFillNcmWithAi = value === true)"
          />
          <Label
            for="squidcore-invoices-ai-ncm"
            class="isce:text-foreground"
            :class="!aiNcmAvailable && 'isce:opacity-60'"
          >
            {{ t("settings.invoices.ai_ncm_label", "Fill the missing NCM with the AI of SquidCore") }}
          </Label>
        </div>
        <p v-if="aiNcmAvailable" class="isce:text-xs isce:text-muted-foreground">
          {{
            t(
              "settings.invoices.ai_ncm_hint",
              "When a product has no NCM, the AI suggests one and the emission is tried once more. Review the suggestions in SquidCore.",
            )
          }}
        </p>
        <div v-else class="isce:flex isce:flex-wrap isce:items-center isce:gap-x-3 isce:gap-y-1">
          <p class="isce:flex isce:items-start isce:gap-2 isce:text-xs isce:text-muted-foreground">
            <Info class="isce:mt-0.5 isce:size-3.5 isce:shrink-0" />
            {{
              t(
                "settings.invoices.ai_ncm_unavailable",
                "Available after you connect OpenAI or Claude in SquidCore. Then test the connection again here.",
              )
            }}
          </p>
          <Button
            type="button"
            variant="link"
            size="sm"
            class="isce:h-auto isce:p-0 isce:text-xs"
            :disabled="isTesting"
            @click="recheckAi"
          >
            <Loader2 v-if="isTesting" class="isce:size-3.5 isce:animate-spin" />
            <RefreshCw v-else class="isce:size-3.5" />
            {{ t("settings.invoices.ai_ncm_recheck", "Check again") }}
          </Button>
        </div>
        <!-- With the AI available, the checkbox above is the action itself. -->
        <div v-if="aiState === 'unavailable'">
          <AiNcmAction :state="aiState" />
        </div>
      </OptionsGroup>

      <OptionsGroup :title="t('settings.invoices.customer', 'Customer')">
        <div class="isce:flex isce:items-center isce:gap-3">
          <Checkbox id="squidcore-invoices-email" v-model:model-value="form.customerEmail" />
          <Label for="squidcore-invoices-email" class="isce:text-foreground">
            {{ t("settings.invoices.email_label", "DANFE and XML links in the completed order e-mail") }}
          </Label>
        </div>
        <p class="isce:text-xs isce:text-muted-foreground">
          {{ t("settings.invoices.email_hint", "The customer always finds the invoice in the order details of My Account.") }}
        </p>
      </OptionsGroup>

      <OptionsGroup v-if="correios.active" :title="t('settings.invoices.correios', 'Correios Automático')">
        <div class="isce:flex isce:items-center isce:gap-3">
          <!-- Without the PRO, or without the automatic emission, the option has no effect: shown off, while the saved value is kept. -->
          <Checkbox
            id="squidcore-invoices-correios-wait"
            :model-value="canWaitForInvoice && form.correiosWait"
            :disabled="!canWaitForInvoice"
            @update:model-value="(value) => (form.correiosWait = value === true)"
          />
          <Label
            for="squidcore-invoices-correios-wait"
            class="isce:text-foreground"
            :class="!canWaitForInvoice && 'isce:opacity-60'"
          >
            {{ t("settings.invoices.correios_wait_label", "Wait for the invoice before the automatic pre-posting of Correios") }}
          </Label>
        </div>
        <p v-if="correios.supportsWait" class="isce:text-xs isce:text-muted-foreground">
          {{
            t(
              "settings.invoices.correios_wait_hint",
              "The pre-posting goes out with the number and key of the invoice as soon as it is authorized.",
            )
          }}
        </p>
        <p v-else-if="!correios.proActive" class="isce:flex isce:items-start isce:gap-2 isce:text-xs isce:text-muted-foreground">
          <Info class="isce:mt-0.5 isce:size-3.5 isce:shrink-0" />
          {{ t("settings.invoices.correios_wait_needs_pro", "Requires Correios Automático PRO, which makes the automatic pre-posting.") }}
        </p>
        <p v-else class="isce:flex isce:items-start isce:gap-2 isce:text-xs isce:text-muted-foreground">
          <Info class="isce:mt-0.5 isce:size-3.5 isce:shrink-0" />
          {{
            t(
              "settings.invoices.correios_wait_unavailable",
              "Needs a version of Correios Automático PRO that can wait for the invoice. Update Correios Automático PRO to use it.",
            )
          }}
        </p>
      </OptionsGroup>

      <div class="isce:flex isce:gap-3 isce:justify-start isce:pt-4">
        <Button :disabled="isSavingInvoices" @click="save">
          <Loader2 v-if="isSavingInvoices" class="isce:animate-spin" />
          {{ t("global.save", "Save") }}
        </Button>
      </div>
    </template>
  </div>
</template>
