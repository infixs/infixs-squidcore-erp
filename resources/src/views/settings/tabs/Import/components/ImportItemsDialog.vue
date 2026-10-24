<script setup lang="ts">
import { useFiscalImportLabels } from "@/composables/useFiscalImportLabels";
import type { FiscalImportItemType } from "@/types/api";
import ImportItemsTable, { ITEMS_PAGE_PARAM, ITEMS_PER_PAGE_PARAM } from "./ImportItemsTable.vue";

export interface ImportItemsList {
  runId: string;
  type: FiscalImportItemType;
  /** Real number of products in the list (the counter of the run). */
  count: number;
  /** Conflict choice of the run: the conflict list then holds products that switch. */
  overwrite: boolean;
}

/** One list of a run (to review, left out, still without NCM…) in a dialog over the Import tab. */
const list = defineModel<ImportItemsList | null>("list", { required: true });

const { listTitle } = useFiscalImportLabels();
const route = useRoute();
const router = useRouter();

/** The table keeps its page in the URL; a closed list must not leave it there for the next one. */
function onOpenChange(open: boolean) {
  if (open) return;

  list.value = null;

  const paging = [ITEMS_PAGE_PARAM, ITEMS_PER_PAGE_PARAM];

  if (paging.some((key) => route.query[key] !== undefined)) {
    router.replace({ query: Object.fromEntries(Object.entries(route.query).filter(([key]) => !paging.includes(key))) });
  }
}
</script>

<template>
  <Dialog :open="list !== null" @update:open="onOpenChange">
    <!-- The title says it all: no description, so none is announced. -->
    <DialogContent class="isce:sm:max-w-4xl isce:max-h-[90vh] isce:overflow-y-auto" :aria-describedby="undefined">
      <DialogHeader v-if="list">
        <DialogTitle>{{ listTitle(list.type, list.overwrite) }}</DialogTitle>
      </DialogHeader>
      <ImportItemsTable v-if="list" :key="`${list.runId}-${list.type}`" :run-id="list.runId" :type="list.type" :count="list.count" />
    </DialogContent>
  </Dialog>
</template>
