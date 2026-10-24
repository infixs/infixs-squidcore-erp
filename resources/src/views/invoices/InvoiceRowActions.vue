<script setup lang="ts">
import { useInvoiceActions } from "@/composables/useInvoiceActions";
import { useInvoiceLabels } from "@/composables/useInvoiceLabels";
import { stateOfStatus } from "@/lib/invoices";
import { useI18n } from "@/composables/useI18n";
import { useInvoiceStore } from "@/stores/useInvoiceStore";
import type { InvoiceRow } from "@/types/api";
import { Ban, Code2, ExternalLink, FileText, Loader2, MoreHorizontal, RefreshCw, RotateCcw } from "lucide-vue-next";

const props = defineProps<{
  row: InvoiceRow;
}>();

const emit = defineEmits<{
  (e: "changed"): void;
  (e: "cancel", row: InvoiceRow): void;
}>();

const { t } = useI18n();
const { emitLabel } = useInvoiceLabels();
const invoiceStore = useInvoiceStore();
const { emitInvoice, refreshInvoice } = useInvoiceActions();

const state = computed(() => stateOfStatus(props.row.status));
const hasOrder = computed(() => props.row.wcOrderId > 0);
const isBusy = computed(() => hasOrder.value && invoiceStore.isBusy(props.row.wcOrderId));
const hasActions = computed(
  () => !!props.row.downloads || !!props.row.orderEditUrl || props.row.canRefresh || props.row.canRetry || props.row.canCancel,
);

async function refresh() {
  if (await refreshInvoice(props.row.wcOrderId)) emit("changed");
}

function retry() {
  emitInvoice(props.row.wcOrderId, state.value, () => emit("changed"));
}
</script>

<template>
  <div class="isce:flex isce:justify-end">
    <DropdownMenu v-if="hasActions">
      <DropdownMenuTrigger as-child>
        <Button variant="ghost" size="icon" :disabled="isBusy" :aria-label="t('invoices.actions.menu', 'Invoice actions')">
          <Loader2 v-if="isBusy" class="isce:animate-spin" />
          <MoreHorizontal v-else />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <template v-if="row.downloads">
          <DropdownMenuItem as-child>
            <a :href="row.downloads.danfe" target="_blank" rel="noopener noreferrer">
              <FileText />
              {{ t("invoices.actions.danfe", "DANFE") }}
            </a>
          </DropdownMenuItem>
          <DropdownMenuItem as-child>
            <a :href="row.downloads.xml">
              <Code2 />
              {{ t("invoices.actions.xml", "XML") }}
            </a>
          </DropdownMenuItem>
          <DropdownMenuItem v-if="row.downloads.cancellationXml" as-child>
            <a :href="row.downloads.cancellationXml">
              <Code2 />
              {{ t("invoices.actions.cancellation_xml", "Cancellation XML") }}
            </a>
          </DropdownMenuItem>
        </template>
        <DropdownMenuItem v-if="row.orderEditUrl" as-child>
          <a :href="row.orderEditUrl">
            <ExternalLink />
            {{ t("invoices.actions.view_order", "View order") }}
          </a>
        </DropdownMenuItem>
        <DropdownMenuItem v-if="row.canRefresh" @select="refresh">
          <RefreshCw />
          {{ t("global.refresh", "Refresh") }}
        </DropdownMenuItem>
        <DropdownMenuItem v-if="row.canRetry" @select="retry">
          <RotateCcw />
          {{ emitLabel(state) }}
        </DropdownMenuItem>
        <template v-if="row.canCancel">
          <DropdownMenuSeparator />
          <DropdownMenuItem variant="destructive" @select="emit('cancel', row)">
            <Ban />
            {{ t("invoices.actions.cancel", "Cancel invoice") }}
          </DropdownMenuItem>
        </template>
      </DropdownMenuContent>
    </DropdownMenu>
  </div>
</template>
