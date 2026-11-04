<script setup lang="ts">
import { createColumnHelper, createDataTableManager, type DataTableV2Translations } from "@/components/infixs/data-table/data-table";
import TableManager from "@/components/infixs/data-table/TableManager.vue";
import SearchInput from "@/components/infixs/search-input/SearchInput.vue";
import InvoiceUsageMeter from "@/components/plan-limit/InvoiceUsageMeter.vue";
import PlanLimitBanner from "@/components/plan-limit/PlanLimitBanner.vue";
import WarningNotice from "@/components/shared/WarningNotice.vue";
import { useI18n } from "@/composables/useI18n";
import { usePlanLimit } from "@/composables/usePlanLimit";
import { resolveErrorMessage } from "@/lib/errors";
import { stateOfStatus } from "@/lib/invoices";
import { formatDateTime, formatMoney } from "@/lib/utils";
import { wpRest, wpRoutes } from "@/lib/wprest";
import { toRoute } from "@/routes/helpers";
import { useAppStore } from "@/stores/useAppStore";
import { useInvoiceStore } from "@/stores/useInvoiceStore";
import { useStatusStore } from "@/stores/useStatusStore";
import type { InvoiceRow, InvoiceStatus, OrderInvoice, UpstreamErrorData } from "@/types/api";
import type { AcceptableValue } from "reka-ui";
import axios from "axios";
import { ExternalLink, Loader2, RefreshCw, Settings } from "lucide-vue-next";
import { toast } from "vue-sonner";
import InvoiceCancelDialog, { type InvoiceCancelTarget } from "./InvoiceCancelDialog.vue";
import InvoiceRowActions from "./InvoiceRowActions.vue";
import InvoiceStatusBadge from "./InvoiceStatusBadge.vue";
import NumberingAdviceBanner from "./NumberingAdviceBanner.vue";
import PendingInvoicesCard from "./PendingInvoicesCard.vue";
import SefazAdviceHint from "./SefazAdviceHint.vue";

const ALL_STATUSES = "all";

const { t } = useI18n();
const appStore = useAppStore();
const statusStore = useStatusStore();
const invoiceStore = useInvoiceStore();
const { connection } = storeToRefs(statusStore);
const { usage, usagePlansUrl } = usePlanLimit();
const route = useRoute();
const router = useRouter();

const connectionRoute = toRoute("settings-connection");
const invoiceSettingsRoute = toRoute("settings-invoices");

/** Read by every list request, so the filters survive page changes. */
const filters = reactive({ status: "", search: "" });
const listError = ref<{ message: string; missingPermission: boolean } | null>(null);
const cancelTarget = ref<InvoiceCancelTarget | null>(null);

const table = createDataTableManager<InvoiceRow>(wpRest, {
  endpoint: wpRoutes.invoices.list,
  pageParamKey: "p",
  perPageParamKey: "perPage",
  params: filters,
});

const listPage = table.list;

table.list = async (options) => {
  listError.value = null;

  try {
    await listPage(options);
  } catch (error) {
    table.props.data = [];
    table.props.totalRows = 0;
    listError.value = {
      message: resolveErrorMessage(error),
      missingPermission: axios.isAxiosError<{ data?: UpstreamErrorData }>(error) && error.response?.data?.data?.upstreamStatus === 403,
    };
  }
};

const isConnected = computed(() => connection.value?.connected ?? false);

const statusOptions = computed<{ value: string; label: string }[]>(() => [
  { value: ALL_STATUSES, label: t("invoices.filters.all", "All statuses") },
  { value: "AUTHORIZED", label: t("invoices.states.authorized", "Authorized") },
  { value: "DRAFT", label: t("invoices.states.emitting", "Sending") },
  { value: "PROCESSING", label: t("invoices.states.processing", "Processing") },
  { value: "ERROR", label: t("invoices.states.error", "Rejected") },
  { value: "DENIED", label: t("invoices.states.denied", "Denied") },
  { value: "CANCELLED", label: t("invoices.states.cancelled", "Cancelled") },
]);

const tableTranslations = computed<DataTableV2Translations>(() => ({
  showingItems: (showing, total, page, totalPages) =>
    t("table.showing", "Showing {showing} of {total} · Page {page} of {pages}", { showing, total, page, pages: Math.max(1, totalPages) }),
}));

const { field, columnHelper } = createColumnHelper<InvoiceRow>();

const columns = [
  field("number", t("invoices.columns.number", "No. / Series"), { skeleton: { width: 70 } }, ({ row }) =>
    row.original.number ? `${row.original.number} / ${row.original.series}` : "—",
  ),
  columnHelper.display({
    id: "order",
    header: t("invoices.columns.order", "Order"),
    cell: ({ row }) =>
      row.original.orderEditUrl
        ? h("a", { href: row.original.orderEditUrl, class: "isce:text-primary isce:underline-offset-4 isce:hover:underline" }, `#${row.original.orderNumber}`)
        : `#${row.original.orderNumber}`,
  }),
  field("recipientName", t("invoices.columns.customer", "Customer"), { truncate: true }),
  field("total", t("invoices.columns.total", "Amount"), { class: "isce:tabular-nums" }, ({ row }) => formatMoney(row.original.total, appStore.locale)),
  columnHelper.display({
    id: "status",
    header: t("invoices.columns.status", "Status"),
    cell: ({ row }) =>
      h("div", { class: "isce:space-y-1" }, [
        h(InvoiceStatusBadge, { state: stateOfStatus(row.original.status), homologation: row.original.environment === "homologation" }),
        row.original.sefazMessage && (row.original.status === "ERROR" || row.original.status === "DENIED")
          ? h("p", { class: "isce:max-w-64 isce:whitespace-normal isce:text-xs isce:text-muted-foreground" }, row.original.sefazMessage)
          : null,
        row.original.advice ? h(SefazAdviceHint, { advice: row.original.advice, class: "isce:max-w-64 isce:whitespace-normal" }) : null,
      ]),
  }),
  field("issuedAt", t("invoices.columns.authorized_at", "Authorized on"), { skeleton: { width: 110 } }, ({ row }) =>
    row.original.status === "AUTHORIZED" || row.original.status === "CANCELLED" ? formatDateTime(row.original.issuedAt, appStore.locale) || "—" : "—",
  ),
  columnHelper.display({
    id: "actions",
    header: t("invoices.columns.actions", "Actions"),
    cell: ({ row }) =>
      h(InvoiceRowActions, {
        row: row.original,
        onChanged: reloadAll,
        onCancel: (target: InvoiceRow) => openCancel(target.wcOrderId, target.orderNumber, target.number),
      }),
    maxSize: Number.MIN_SAFE_INTEGER,
    enableSorting: false,
    enableHiding: false,
  }),
];

const statusFilter = computed(() => filters.status || ALL_STATUSES);

/** A new filter starts again from the first page. */
function applyFilters() {
  if (route.query.p && route.query.p !== "1") {
    router.push({ query: { ...route.query, p: 1 } });
    return;
  }

  table.props.pagination.currentPage = 1;
  table.list();
}

function onStatusChange(value: AcceptableValue) {
  filters.status = value === ALL_STATUSES ? "" : String(value as InvoiceStatus);
  applyFilters();
}

function onSearch(query: string) {
  filters.search = query.trim();
  applyFilters();
}

function onClearSearch() {
  filters.search = "";
  applyFilters();
}

function openCancel(orderId: number, orderNumber: string, invoiceNumber: string) {
  cancelTarget.value = { orderId, orderNumber, invoiceNumber };
}

function onCancelled(invoice: OrderInvoice) {
  cancelTarget.value = null;
  toast.success(t("invoices.toast.cancelled", "Invoice {number} cancelled.", { number: invoice.invoice?.number ?? "" }));
  if (isConnected.value) table.list();
}

/** A failure stays in the store and is shown by the pending card. */
function loadPending() {
  if (!appStore.hasWooCommerce) return;

  invoiceStore.fetchPending().catch(() => {});
}

function reloadAll() {
  loadPending();
  if (isConnected.value) table.list();
}

/** "Refresh" also reads the use of the month from SquidCore again. */
function refreshAll() {
  reloadAll();
  if (isConnected.value) statusStore.refreshInvoiceUsage().catch(() => {});
}

onMounted(async () => {
  loadPending();

  try {
    await statusStore.fetchStatus();
  } catch {
    toast.error(t("dashboard.load_error", "Could not load the status."));
  }

  if (isConnected.value) table.list();
});
</script>

<template>
  <div>
    <div class="isce:flex isce:flex-wrap isce:items-start isce:justify-between isce:gap-3">
      <div>
        <h1 class="isce:text-xl">{{ t("invoices.title", "Invoices") }}</h1>
        <p class="isce:text-muted-foreground">
          {{ t("invoices.description", "NF-e of the store orders, issued by SquidCore.") }}
        </p>
      </div>
      <div class="isce:flex isce:flex-wrap isce:gap-2">
        <Button
          v-if="invoiceSettingsRoute"
          variant="outline"
          as="a"
          :href="invoiceSettingsRoute.href"
          @click="invoiceSettingsRoute.onClick"
        >
          <Settings />
          {{ t("invoices.settings", "Invoice settings") }}
        </Button>
        <Button
          variant="outline"
          :disabled="table.props.loading || invoiceStore.isLoadingPending || statusStore.isRefreshingUsage"
          @click="refreshAll"
        >
          <Loader2 v-if="table.props.loading || invoiceStore.isLoadingPending || statusStore.isRefreshingUsage" class="isce:animate-spin" />
          <RefreshCw v-else />
          {{ t("global.refresh", "Refresh") }}
        </Button>
      </div>
    </div>

    <WarningNotice v-if="!appStore.hasWooCommerce" class="isce:mt-5">
      {{ t("invoices.woocommerce_inactive", "WooCommerce is not active. Invoices are issued for WooCommerce orders.") }}
    </WarningNotice>

    <WarningNotice v-else-if="connection && !connection.connected" class="isce:mt-5">
      {{ t("invoices.not_connected", "Connect this store to SquidCore to issue invoices and see them here.") }}
      <template #action>
        <Button v-if="connectionRoute" size="sm" as="a" :href="connectionRoute.href" @click="connectionRoute.onClick">
          {{ t("connection.connect", "Connect") }}
        </Button>
      </template>
    </WarningNotice>

    <PlanLimitBanner v-if="appStore.hasWooCommerce" class="isce:mt-5" @finished="reloadAll" />

    <NumberingAdviceBanner
      v-if="appStore.hasWooCommerce"
      class="isce:mt-5"
      :invoices="invoiceStore.pending"
    />

    <InvoiceUsageMeter
      v-if="appStore.hasWooCommerce && isConnected && usage"
      class="isce:mt-5 isce:rounded-md isce:border isce:border-input isce:p-4 isce:shadow-xs"
      :usage="usage"
      :plans-url="usagePlansUrl"
    />

    <PendingInvoicesCard
      v-if="appStore.hasWooCommerce"
      class="isce:mt-5"
      @changed="reloadAll"
      @cancel="(item) => openCancel(item.orderId, item.orderNumber, item.invoice?.number ?? '')"
    />

    <div v-if="isConnected" class="isce:mt-8 isce:space-y-3">
      <h2 class="isce:text-lg">{{ t("invoices.issued", "Issued in SquidCore") }}</h2>

      <WarningNotice v-if="listError">
        {{ listError.message }}
        <template #action>
          <Button
            v-if="listError.missingPermission"
            size="sm"
            as="a"
            :href="appStore.integrationsUrl"
            target="_blank"
            rel="noopener noreferrer"
          >
            <ExternalLink />
            {{ t("settings.connection.open_integrations", "Open integrations in SquidCore") }}
          </Button>
        </template>
      </WarningNotice>

      <TableManager
        v-else
        :data-table="table"
        :columns="columns"
        :immediate="false"
        :translations="tableTranslations"
        hide-search
        :empty-message="
          filters.status || filters.search
            ? t('invoices.empty_filtered', 'No invoice matches the filters.')
            : t('invoices.empty', 'No invoice issued yet.')
        "
      >
        <template #header>
          <div class="isce:flex isce:flex-wrap isce:items-center isce:gap-3">
            <Select :model-value="statusFilter" @update:model-value="onStatusChange">
              <SelectTrigger class="isce:w-48" :aria-label="t('invoices.filters.status', 'Status')">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem v-for="option in statusOptions" :key="option.value" :value="option.value">
                  {{ option.label }}
                </SelectItem>
              </SelectContent>
            </Select>
            <SearchInput
              :loading="table.props.loading && filters.search !== ''"
              :translations="{
                placeholder: t('invoices.filters.search', 'Number, customer or order'),
                button: t('invoices.filters.search_button', 'Search'),
              }"
              @search="onSearch"
              @clear="onClearSearch"
            />
          </div>
        </template>
      </TableManager>
    </div>

    <InvoiceCancelDialog :target="cancelTarget" @close="cancelTarget = null" @cancelled="onCancelled" />
  </div>
</template>
