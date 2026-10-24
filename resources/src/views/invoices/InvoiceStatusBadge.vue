<script setup lang="ts">
import type { BadgeVariants } from "@/components/ui/badge";
import { useInvoiceLabels } from "@/composables/useInvoiceLabels";
import { useI18n } from "@/composables/useI18n";
import type { InvoiceState } from "@/types/api";

const props = defineProps<{
  state: InvoiceState;
  /** Invoice issued in the SEFAZ test environment. */
  homologation?: boolean;
}>();

const { t } = useI18n();
const { stateLabel } = useInvoiceLabels();

const VARIANTS: Record<InvoiceState, BadgeVariants["variant"]> = {
  none: "outline",
  scheduled: "secondary",
  emitting: "secondary",
  processing: "secondary",
  authorized: "success",
  blocked: "warning",
  error: "destructive",
  denied: "destructive",
  failed: "destructive",
  cancelled: "outline",
  skipped: "outline",
};

const variant = computed(() => VARIANTS[props.state] ?? "outline");
</script>

<template>
  <span class="isce:inline-flex isce:flex-wrap isce:items-center isce:gap-1">
    <Badge :variant="variant">{{ stateLabel(state) }}</Badge>
    <Badge v-if="homologation" variant="outline">
      {{ t("invoices.test_environment", "Test environment") }}
    </Badge>
  </span>
</template>
