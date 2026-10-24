<script lang="ts">
/** Page and size of the list live in the URL, like the other lists of the app (see ImportItemsDialog). */
export const ITEMS_PAGE_PARAM = "p";
export const ITEMS_PER_PAGE_PARAM = "perPage";
</script>

<script setup lang="ts">
import { createColumnHelper, createDataTableManager, type DataTableV2Translations } from "@/components/infixs/data-table/data-table";
import TableManager from "@/components/infixs/data-table/TableManager.vue";
import { useFiscalImportLabels } from "@/composables/useFiscalImportLabels";
import { useI18n } from "@/composables/useI18n";
import { formatNumber } from "@/lib/utils";
import { wpRest, wpRoutes } from "@/lib/wprest";
import { useAppStore } from "@/stores/useAppStore";
import type { FiscalImportItem, FiscalImportItemType } from "@/types/api";
import { ExternalLink } from "lucide-vue-next";

/** Products of one list of a run, paginated by the plugin (at most 500 kept per list). */
const props = defineProps<{
  runId: string;
  type: FiscalImportItemType;
  /** Real number of products in the list; more than the rows kept when the list was cut. */
  count: number;
}>();

const { t } = useI18n();
const appStore = useAppStore();
const { reason, outcome, origin } = useFiscalImportLabels();

const table = createDataTableManager<FiscalImportItem>(wpRest, {
  endpoint: wpRoutes.fiscalImport.items(props.runId),
  pageParamKey: ITEMS_PAGE_PARAM,
  perPageParamKey: ITEMS_PER_PAGE_PARAM,
  params: { type: props.type },
});

const isTruncated = computed(() => !table.props.loading && table.props.totalRows > 0 && props.count > table.props.totalRows);

const tableTranslations = computed<DataTableV2Translations>(() => ({
  showingItems: (showing, total, page, totalPages) =>
    t("table.showing", "Showing {showing} of {total} · Page {page} of {pages}", { showing, total, page, pages: Math.max(1, totalPages) }),
}));

const muted = "isce:block isce:text-xs isce:text-muted-foreground";

/** Why the product is in the list; the review of variations and the skipped categories list what differs. */
function why(item: FiscalImportItem) {
  const text = item.reason ? reason(item) : outcome(item);
  const details = item.variations.length
    ? item.variations.map((variation) => `${variation.name}: ${variation.ncm || "—"}${variation.origin ? ` (${origin(variation.origin)})` : ""}`)
    : item.categories.map((category) => `${category.name}: ${category.ncm || "—"}`);

  return h("div", { class: "isce:max-w-80 isce:flex isce:flex-col isce:gap-1 isce:whitespace-normal" }, [
    h("p", text),
    details.length ? h("ul", { class: "isce:list-disc isce:pl-4 isce:text-xs isce:text-muted-foreground" }, details.map((line) => h("li", line))) : null,
  ]);
}

const { field, columnHelper } = createColumnHelper<FiscalImportItem>();

const columns = [
  field("name", t("settings.import.items.product", "Product"), { truncate: true }),
  field("current", t("settings.import.items.current", "Current NCM"), { class: "isce:tabular-nums" }, ({ row }) => row.original.current || "—"),
  columnHelper.display({
    id: "webmania",
    header: t("settings.import.items.webmania", "In WebmaniaBR"),
    cell: ({ row }) =>
      h("span", { class: "isce:tabular-nums" }, [
        row.original.webmania || "—",
        row.original.origin ? h("span", { class: muted }, origin(row.original.origin)) : null,
      ]),
  }),
  columnHelper.display({
    id: "reason",
    header: t("settings.import.items.reason", "Why"),
    cell: ({ row }) => why(row.original),
  }),
  columnHelper.display({
    id: "actions",
    header: "",
    cell: ({ row }) =>
      h(
        "a",
        {
          href: row.original.editUrl,
          target: "_blank",
          rel: "noopener noreferrer",
          class: "isce:inline-flex isce:items-center isce:gap-1 isce:whitespace-nowrap isce:text-primary isce:underline-offset-4 isce:hover:underline",
        },
        [t("settings.import.items.edit", "Edit product"), h(ExternalLink, { class: "isce:size-3.5", "aria-hidden": "true" })],
      ),
    maxSize: Number.MIN_SAFE_INTEGER,
    enableSorting: false,
    enableHiding: false,
  }),
];
</script>

<template>
  <div class="isce:flex isce:flex-col isce:gap-3">
    <TableManager
      :data-table="table"
      :columns="columns"
      :translations="tableTranslations"
      hide-search
      :empty-message="t('settings.import.items.empty', 'Nothing here.')"
    />
    <p v-if="isTruncated" class="isce:text-xs isce:text-muted-foreground">
      {{ t("settings.import.items.truncated", "Showing the first {count}.", { count: formatNumber(table.props.totalRows, appStore.locale) }) }}
    </p>
  </div>
</template>
