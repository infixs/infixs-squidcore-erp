<script setup lang="ts">
import WarningNotice from "@/components/shared/WarningNotice.vue";
import { useInvoiceActions } from "@/composables/useInvoiceActions";
import { useInvoiceLabels } from "@/composables/useInvoiceLabels";
import { useI18n } from "@/composables/useI18n";
import { isNumberingAdvice } from "@/lib/invoices";
import { useInvoiceStore } from "@/stores/useInvoiceStore";
import type { OrderInvoice } from "@/types/api";
import { AlertTriangle, Ban, CheckCircle2, ExternalLink, RefreshCw, RotateCcw } from "lucide-vue-next";
import BlockingReasons from "./BlockingReasons.vue";
import InvoiceStatusBadge from "./InvoiceStatusBadge.vue";
import SefazAdviceHint from "./SefazAdviceHint.vue";

const emit = defineEmits<{
  (e: "cancel", invoice: OrderInvoice): void;
  (e: "changed"): void;
}>();

const { t } = useI18n();
const { emitLabel } = useInvoiceLabels();
const invoiceStore = useInvoiceStore();
const { pending, isLoadingPending, hasLoadedPending, pendingError } = storeToRefs(invoiceStore);
const { emitInvoice, refreshInvoice, fillNcm } = useInvoiceActions();

function retry(item: OrderInvoice) {
  emitInvoice(item.orderId, item.state, () => emit("changed"));
}

/** SEFAZ may have authorized the very invoice it says it already received: refreshing comes before issuing again. */
function refreshFirst(item: OrderInvoice): boolean {
  return item.advice?.kind === "already_received";
}

function canRefresh(item: OrderInvoice): boolean {
  return item.canRefresh && (!item.canEmit || refreshFirst(item));
}

async function refresh(item: OrderInvoice) {
  if (await refreshInvoice(item.orderId)) emit("changed");
}

/** The error stays on the card, so nothing else to report. */
function reload() {
  invoiceStore.fetchPending().catch(() => {});
}
</script>

<template>
  <section class="isce:rounded-md isce:border isce:border-input isce:shadow-xs">
    <header class="isce:flex isce:items-center isce:gap-2 isce:border-b isce:p-4">
      <AlertTriangle class="isce:size-4 isce:text-muted-foreground" />
      <h2 class="isce:text-sm isce:font-medium">
        {{ t("invoices.pending.title", "Waiting for you") }}
      </h2>
      <Badge v-if="pending.length && !pendingError" variant="warning">{{ pending.length }}</Badge>
    </header>

    <div v-if="!hasLoadedPending && !pendingError" class="isce:space-y-3 isce:p-4">
      <Skeleton v-for="i in 2" :key="i" class="isce:h-16 isce:w-full" />
    </div>

    <WarningNotice v-else-if="pendingError" class="isce:m-4">
      {{ t("invoices.pending.load_error", "Could not load the invoices waiting for you.") }} {{ pendingError }}
      <template #action>
        <Button size="sm" variant="outline" :disabled="isLoadingPending" @click="reload">
          <RefreshCw />
          {{ t("invoices.actions.retry", "Try again") }}
        </Button>
      </template>
    </WarningNotice>

    <p v-else-if="!pending.length" class="isce:flex isce:items-center isce:gap-2 isce:p-4 isce:text-muted-foreground">
      <CheckCircle2 class="isce:size-4 isce:text-success" />
      {{ t("invoices.pending.empty", "No invoice needs your attention.") }}
    </p>

    <ul v-else class="isce:divide-y">
      <li v-for="item in pending" :key="item.orderId" class="isce:flex isce:flex-col isce:gap-3 isce:p-4 isce:lg:flex-row isce:lg:items-start">
        <div class="isce:flex-1 isce:min-w-0 isce:space-y-2">
          <div class="isce:flex isce:flex-wrap isce:items-center isce:gap-2">
            <a :href="item.orderEditUrl" class="isce:font-medium isce:text-primary isce:underline-offset-4 isce:hover:underline">
              {{ t("invoices.order_number", "Order #{number}", { number: item.orderNumber }) }}
            </a>
            <span v-if="item.customerName" class="isce:text-muted-foreground">{{ item.customerName }}</span>
            <InvoiceStatusBadge :state="item.state" :homologation="item.invoice?.environment === 'homologation'" />
          </div>

          <p v-if="item.attentionMessage" class="isce:text-yellow-800">{{ item.attentionMessage }}</p>
          <p v-if="item.message && item.state !== 'authorized'" class="isce:break-words">{{ item.message }}</p>

          <!-- The numbering notice on top of the screen explains the cause and the steps once. -->
          <SefazAdviceHint v-if="item.advice" :advice="item.advice" :detailed="!isNumberingAdvice(item.advice)" />

          <BlockingReasons
            :reasons="item.reasons"
            :filling="invoiceStore.isBusy(item.orderId)"
            @fill-ncm="fillNcm(item.orderId)"
          />
        </div>

        <div class="isce:flex isce:flex-wrap isce:gap-2 isce:lg:justify-end">
          <Button
            v-if="item.canEmit"
            :variant="refreshFirst(item) ? 'outline' : 'default'"
            size="sm"
            :disabled="invoiceStore.isBusy(item.orderId)"
            @click="retry(item)"
          >
            <RotateCcw />
            {{ emitLabel(item.state) }}
          </Button>
          <Button
            v-if="canRefresh(item)"
            :variant="refreshFirst(item) ? 'default' : 'outline'"
            :class="{ 'isce:order-first': refreshFirst(item) }"
            size="sm"
            :disabled="invoiceStore.isBusy(item.orderId)"
            @click="refresh(item)"
          >
            <RefreshCw />
            {{ t("global.refresh", "Refresh") }}
          </Button>
          <Button
            v-if="item.canCancel"
            variant="outline"
            size="sm"
            :disabled="invoiceStore.isBusy(item.orderId)"
            @click="emit('cancel', item)"
          >
            <Ban />
            {{ t("invoices.actions.cancel", "Cancel invoice") }}
          </Button>
          <Button variant="ghost" size="sm" as="a" :href="item.orderEditUrl">
            <ExternalLink />
            {{ t("invoices.actions.view_order", "View order") }}
          </Button>
        </div>
      </li>
    </ul>
  </section>
</template>
