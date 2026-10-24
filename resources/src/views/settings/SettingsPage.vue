<script setup lang="ts">
import { computed } from "vue";
import Tab from "@/components/infixs/tab/Tab.vue";
import TabItem from "@/components/infixs/tab/TabItem.vue";
import { useI18n } from "@/composables/useI18n";

const { t } = useI18n();

const tabs = computed(() => [
  {
    id: "connection",
    label: t("settings.tabs.connection", "Connection"),
    path: "/settings/connection",
  },
  {
    id: "sync",
    label: t("settings.tabs.sync", "Synchronization"),
    path: "/settings/sync",
  },
  {
    id: "invoices",
    label: t("settings.tabs.invoices", "Invoices"),
    path: "/settings/invoices",
  },
  {
    id: "import",
    label: t("settings.tabs.import", "Import data"),
    path: "/settings/import",
  },
]);
</script>

<template>
  <div class="isce:w-full">
    <div class="isce:flex isce:gap-6">
      <div class="isce:w-40 isce:shrink-0">
        <Tab direction="vertical">
          <TabItem
            v-for="tab in tabs"
            :key="tab.id"
            :to="tab.path"
            :name="tab.path"
            :virtual-path="true"
            class="isce:[&>a]:pr-5"
          >
            {{ tab.label }}
          </TabItem>
        </Tab>
      </div>

      <div class="isce:flex-1 isce:min-w-0">
        <RouterView v-slot="{ Component }">
          <Transition name="isce-fade" mode="out-in">
            <component :is="Component" />
          </Transition>
        </RouterView>
      </div>
    </div>
  </div>
</template>
