<script setup lang="ts">
import { useI18n } from "@/composables/useI18n";
import type { BlockingReason } from "@/types/api";
import { Loader2, Sparkles } from "lucide-vue-next";

const props = defineProps<{
  reasons: BlockingReason[];
  /** An NCM fill is on the way. */
  filling?: boolean;
}>();

const emit = defineEmits<{
  (e: "fill-ncm"): void;
}>();

const NCM_REASON = "product.fiscal.ncm";

const { t } = useI18n();

const aiFillable = computed(() => props.reasons.some((reason) => reason.aiFillable));

/** Missing NCM that the AI cannot fill: the organization has no OpenAI or Claude connected. */
const showAiHint = computed(() => !aiFillable.value && props.reasons.some((reason) => reason.code === NCM_REASON));
</script>

<template>
  <div v-if="reasons.length" class="isce:space-y-2">
    <ul class="isce:list-disc isce:space-y-1 isce:pl-5">
      <li v-for="(reason, index) in reasons" :key="`${reason.code}-${index}`">
        {{ reason.message }}
        <a
          v-if="reason.productEditUrl"
          :href="reason.productEditUrl"
          target="_blank"
          rel="noopener noreferrer"
          class="isce:text-primary isce:underline-offset-4 isce:hover:underline"
        >
          {{ t("invoices.edit_product", "Edit {name}", { name: reason.productName }) }}
        </a>
      </li>
    </ul>

    <Button v-if="aiFillable" variant="outline" size="sm" :disabled="filling" @click="emit('fill-ncm')">
      <Loader2 v-if="filling" class="isce:animate-spin" />
      <Sparkles v-else />
      {{ t("invoices.fill_ncm_ai", "Fill with AI") }}
    </Button>

    <p v-else-if="showAiHint" class="isce:text-xs isce:text-muted-foreground">
      {{ t("invoices.ai_hint", "Tip: connect OpenAI or Claude in SquidCore to fill the missing NCM with AI.") }}
    </p>
  </div>
</template>
