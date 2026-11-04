<script setup lang="ts">
import type { SefazAdvice } from "@/types/api";
import { ExternalLink, Lightbulb } from "lucide-vue-next";

/**
 * A rejection the store owner can fix, in short: what happened and the screen of SquidCore that fixes it. `detailed`
 * adds the cause and the steps, for when no notice on the screen explains them.
 */
defineProps<{
  advice: SefazAdvice;
  detailed?: boolean;
}>();
</script>

<template>
  <div class="isce:space-y-1 isce:text-xs" :data-advice="advice.kind">
    <p class="isce:flex isce:flex-wrap isce:items-center isce:gap-x-2 isce:gap-y-1">
      <span class="isce:inline-flex isce:items-center isce:gap-1 isce:font-medium isce:text-yellow-800">
        <Lightbulb class="isce:size-3.5 isce:shrink-0" />
        {{ advice.title }}
      </span>
      <a
        v-if="advice.actionUrl"
        :href="advice.actionUrl"
        target="_blank"
        rel="noopener noreferrer"
        class="isce:inline-flex isce:items-center isce:gap-1 isce:text-primary isce:underline-offset-4 isce:hover:underline"
      >
        {{ advice.actionLabel }}
        <ExternalLink class="isce:size-3" />
      </a>
    </p>
    <template v-if="detailed">
      <p class="isce:text-muted-foreground">{{ advice.cause }}</p>
      <ol class="isce:list-decimal isce:space-y-0.5 isce:pl-5 isce:text-muted-foreground">
        <li v-for="(step, index) in advice.steps" :key="index">{{ step }}</li>
      </ol>
    </template>
  </div>
</template>
