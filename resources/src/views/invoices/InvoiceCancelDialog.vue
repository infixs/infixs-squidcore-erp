<script setup lang="ts">
import { JUSTIFICATION_MAX_LENGTH, JUSTIFICATION_MIN_LENGTH } from "@/lib/invoices";
import { useI18n } from "@/composables/useI18n";
import { resolveErrorMessage } from "@/lib/errors";
import { useInvoiceStore } from "@/stores/useInvoiceStore";
import type { OrderInvoice } from "@/types/api";
import { Loader2 } from "lucide-vue-next";

export interface InvoiceCancelTarget {
  orderId: number;
  orderNumber: string;
  invoiceNumber: string;
}

const props = defineProps<{
  target: InvoiceCancelTarget | null;
}>();

const emit = defineEmits<{
  (e: "close"): void;
  (e: "cancelled", invoice: OrderInvoice): void;
}>();

const { t } = useI18n();
const invoiceStore = useInvoiceStore();

const justification = ref("");
const error = ref("");
const isSubmitting = ref(false);

const length = computed(() => justification.value.trim().length);
const isValid = computed(() => length.value >= JUSTIFICATION_MIN_LENGTH && length.value <= JUSTIFICATION_MAX_LENGTH);

watch(
  () => props.target,
  () => {
    justification.value = "";
    error.value = "";
  },
);

function onOpenChange(open: boolean) {
  if (!open && !isSubmitting.value) emit("close");
}

async function submit() {
  if (!props.target || !isValid.value) return;

  isSubmitting.value = true;
  error.value = "";

  try {
    const invoice = await invoiceStore.cancel(props.target.orderId, justification.value.trim());
    emit("cancelled", invoice);
  } catch (exception) {
    error.value = resolveErrorMessage(exception);
  } finally {
    isSubmitting.value = false;
  }
}
</script>

<template>
  <Dialog :open="target !== null" @update:open="onOpenChange">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>{{ t("invoices.cancel.title", "Cancel invoice") }}</DialogTitle>
        <DialogDescription v-if="target">
          {{
            t("invoices.cancel.description", "Invoice {number} of order #{order}. SEFAZ accepts the cancellation up to 24 hours after the authorization.", {
              number: target.invoiceNumber,
              order: target.orderNumber,
            })
          }}
        </DialogDescription>
      </DialogHeader>

      <div class="isce:space-y-2">
        <Label for="squidcore-invoice-justification" class="isce:text-foreground">
          {{ t("invoices.cancel.reason", "Reason for the cancellation (sent to SEFAZ)") }}
        </Label>
        <Textarea
          id="squidcore-invoice-justification"
          v-model="justification"
          :maxlength="JUSTIFICATION_MAX_LENGTH"
          rows="3"
          :disabled="isSubmitting"
        />
        <p class="isce:text-xs" :class="isValid || length === 0 ? 'isce:text-muted-foreground' : 'isce:text-destructive'">
          {{ length }}/{{ JUSTIFICATION_MAX_LENGTH }} ·
          {{ t("invoices.cancel.min_length", "At least {count} characters.", { count: JUSTIFICATION_MIN_LENGTH }) }}
        </p>
        <p v-if="error" role="alert" class="isce:text-sm isce:text-destructive isce:break-words">{{ error }}</p>
      </div>

      <DialogFooter>
        <Button variant="outline" :disabled="isSubmitting" @click="emit('close')">
          {{ t("invoices.cancel.back", "Back") }}
        </Button>
        <Button variant="destructive" :disabled="!isValid || isSubmitting" @click="submit">
          <Loader2 v-if="isSubmitting" class="isce:animate-spin" />
          {{ t("invoices.cancel.confirm", "Cancel invoice") }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
