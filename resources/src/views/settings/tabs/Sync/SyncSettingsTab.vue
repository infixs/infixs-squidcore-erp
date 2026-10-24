<script setup lang="ts">
import { useI18n } from "@/composables/useI18n";
import { useAppStore } from "@/stores/useAppStore";
import { useSettingsStore } from "@/stores/useSettingsStore";
import type { SyncOptions } from "@/types/api";
import { Loader2 } from "lucide-vue-next";
import { toast } from "vue-sonner";
import OptionsGroup from "@/components/shared/OptionsGroup.vue";
import WarningNotice from "@/components/shared/WarningNotice.vue";

const { t } = useI18n();
const appStore = useAppStore();
const settingsStore = useSettingsStore();
const { sync, isLoading, hasLoaded, isSavingSync } = storeToRefs(settingsStore);

const form = ref<SyncOptions>({ ...sync.value });

const shouldShowSkeleton = computed(
  () => isLoading.value && !hasLoaded.value,
);

watch(sync, (value) => {
  form.value = { ...value };
});

onMounted(async () => {
  try {
    await settingsStore.fetchSettings(true);
  } catch {
    toast.error(t("settings.load_error", "Error loading settings"));
  }
});

async function save() {
  try {
    await settingsStore.saveSync(form.value);
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
        {{ t("settings.sync.title", "Sync options") }}
      </h2>
      <p class="isce:text-sm isce:text-muted-foreground">
        {{
          t(
            "settings.sync.description",
            "Choose what this store sends to SquidCore.",
          )
        }}
      </p>
    </div>

    <WarningNotice v-if="!appStore.hasWooCommerce">
      {{
        t(
          "sync.woocommerce_inactive",
          "WooCommerce is not active. The sync starts once WooCommerce is activated.",
        )
      }}
    </WarningNotice>

    <div v-if="shouldShowSkeleton" class="isce:space-y-6">
      <div v-for="i in 2" :key="i" class="isce:flex isce:gap-6">
        <Skeleton class="isce:h-5 isce:w-40" />
        <div class="isce:flex-1 isce:max-w-[500px] isce:space-y-2">
          <Skeleton class="isce:h-5 isce:w-72" />
          <Skeleton class="isce:h-4 isce:w-80" />
        </div>
      </div>
    </div>

    <template v-else>
      <OptionsGroup :title="t('settings.sync.catalog', 'Catalog')">
        <div class="isce:flex isce:items-center isce:gap-3">
          <Checkbox id="squidcore-sync-catalog" v-model:model-value="form.catalog" />
          <Label for="squidcore-sync-catalog" class="isce:text-foreground">
            {{ t("settings.sync.catalog_label", "Sync catalog (products and categories)") }}
          </Label>
        </div>
        <p class="isce:text-xs isce:text-muted-foreground">
          {{
            t(
              "settings.sync.catalog_hint",
              "Products, variations and categories created, changed or deleted in the store.",
            )
          }}
        </p>
      </OptionsGroup>

      <OptionsGroup :title="t('settings.sync.orders', 'Orders')">
        <div class="isce:flex isce:items-center isce:gap-3">
          <Checkbox id="squidcore-sync-orders" v-model:model-value="form.orders" />
          <Label for="squidcore-sync-orders" class="isce:text-foreground">
            {{ t("settings.sync.orders_label", "Sync orders") }}
          </Label>
        </div>
        <p class="isce:text-xs isce:text-muted-foreground">
          {{
            t(
              "settings.sync.orders_hint",
              "New orders, status changes and confirmed payments.",
            )
          }}
        </p>
      </OptionsGroup>

      <div class="isce:flex isce:gap-3 isce:justify-start isce:pt-4">
        <Button :disabled="isSavingSync" @click="save">
          <Loader2 v-if="isSavingSync" class="isce:animate-spin" />
          {{ t("global.save", "Save") }}
        </Button>
      </div>
    </template>
  </div>
</template>
