<script setup lang="ts">
import EnabledBadge from "@/components/shared/EnabledBadge.vue";
import SummaryCard from "@/components/shared/SummaryCard.vue";
import WarningNotice from "@/components/shared/WarningNotice.vue";
import { useI18n } from "@/composables/useI18n";
import { useSyncLabels } from "@/composables/useSyncLabels";
import { isFailureAfterLastSuccess } from "@/lib/sync";
import { formatDateTime } from "@/lib/utils";
import { toRoute } from "@/routes/helpers";
import { useAppStore } from "@/stores/useAppStore";
import { useStatusStore } from "@/stores/useStatusStore";
import {
  CheckCircle2,
  Hourglass,
  Loader2,
  RefreshCw,
  Settings,
  XCircle,
} from "lucide-vue-next";
import { toast } from "vue-sonner";
import OptionsGroup from "@/components/shared/OptionsGroup.vue";

const { t } = useI18n();
const { topicLabel } = useSyncLabels();
const appStore = useAppStore();
const statusStore = useStatusStore();
const { connection, sync, isLoading } = storeToRefs(statusStore);

const failureNeedsAttention = computed(() => (sync.value ? isFailureAfterLastSuccess(sync.value) : false));

const connectionRoute = toRoute("settings-connection");
const syncSettingsRoute = toRoute("settings-sync");

const pausedReason = computed(() => {
  if (!sync.value || !connection.value) return null;

  if (!sync.value.woocommerceActive) {
    return t(
      "sync.woocommerce_inactive",
      "WooCommerce is not active. The sync starts once WooCommerce is activated.",
    );
  }

  if (!connection.value.configured) {
    return t(
      "sync.paused_not_connected",
      "The sync is stopped: connect this store to SquidCore to start sending changes.",
    );
  }

  if (!connection.value.connected) {
    return t(
      "sync.paused_not_confirmed",
      "The sync is stopped: the connection to SquidCore is not confirmed. Fix it and test the connection again.",
    );
  }

  if (!sync.value.catalog && !sync.value.orders) {
    return t(
      "sync.paused_all_disabled",
      "The sync is stopped: catalog and orders are both disabled.",
    );
  }

  return null;
});

const catalogEvents = computed(() => [
  t("sync.events.products", "Products created, changed, moved to trash or deleted"),
  t("sync.events.variations", "Variations, sent together with their product"),
  t("sync.events.categories", "Product categories created, changed or deleted"),
]);

const orderEvents = computed(() => [
  t("sync.events.new_orders", "New orders"),
  t("sync.events.order_status", "Order status changes"),
  t("sync.events.payments", "Confirmed payments"),
]);

function refresh() {
  statusStore.fetchStatus().catch(() => {
    toast.error(t("sync.load_error", "Could not load the sync status."));
  });
}

onMounted(refresh);
</script>

<template>
  <div>
    <div class="isce:flex isce:flex-wrap isce:items-start isce:justify-between isce:gap-3">
      <div>
        <h1 class="isce:text-xl">{{ t("sync.title", "Synchronization") }}</h1>
        <p class="isce:text-muted-foreground">
          {{
            t(
              "sync.description",
              "Store changes are sent to SquidCore as soon as they happen.",
            )
          }}
        </p>
      </div>
      <Button variant="outline" :disabled="isLoading" @click="refresh">
        <Loader2 v-if="isLoading" class="isce:animate-spin" />
        <RefreshCw v-else />
        {{ t("global.refresh", "Refresh") }}
      </Button>
    </div>

    <WarningNotice v-if="pausedReason" class="isce:mt-5">
      {{ pausedReason }}
      <template #action>
        <Button
          v-if="connection && !connection.connected && connectionRoute"
          size="sm"
          as="a"
          :href="connectionRoute.href"
          @click="connectionRoute.onClick"
        >
          {{ t("connection.connect", "Connect") }}
        </Button>
      </template>
    </WarningNotice>

    <WarningNotice v-if="sync?.lastSuccess?.ignored" class="isce:mt-5">
      {{
        t(
          "sync.ignored_warning",
          "SquidCore received the last send but discarded it: the Store → SquidCore direction is turned off in the WooCommerce integration in SquidCore.",
        )
      }}
    </WarningNotice>

    <div
      v-if="!sync"
      class="isce:mt-5 isce:grid isce:grid-cols-1 isce:gap-4 isce:lg:grid-cols-3"
    >
      <Skeleton v-for="i in 3" :key="i" class="isce:h-32 isce:w-full" />
    </div>

    <div
      v-else
      class="isce:mt-5 isce:grid isce:grid-cols-1 isce:gap-4 isce:lg:grid-cols-3"
    >
      <SummaryCard :title="t('sync.pending', 'Waiting in queue')" :icon="Hourglass">
        <p class="isce:text-3xl isce:font-semibold isce:tabular-nums">
          {{ sync.pending }}
        </p>
        <p class="isce:text-muted-foreground">
          {{ t("sync.pending_hint", "Changes waiting to be sent, retries included.") }}
        </p>
      </SummaryCard>

      <SummaryCard
        :title="t('sync.last_success', 'Last successful send')"
        :icon="CheckCircle2"
      >
        <template v-if="sync.lastSuccess">
          <p class="isce:text-base isce:font-semibold">
            {{ topicLabel(sync.lastSuccess.topic, sync.lastSuccess.entityId) }}
          </p>
          <p class="isce:text-muted-foreground">
            {{ formatDateTime(sync.lastSuccess.at, appStore.locale) }}
          </p>
        </template>
        <p v-else class="isce:text-muted-foreground">
          {{ t("sync.no_success", "No send recorded yet") }}
        </p>
      </SummaryCard>

      <SummaryCard :title="t('sync.last_failure', 'Last failure')" :icon="XCircle">
        <template v-if="sync.lastFailure">
          <p class="isce:text-base isce:font-semibold">
            {{ topicLabel(sync.lastFailure.topic, sync.lastFailure.entityId) }}
          </p>
          <p class="isce:text-muted-foreground">
            {{ formatDateTime(sync.lastFailure.at, appStore.locale) }}
          </p>
          <p
            class="isce:break-words"
            :class="failureNeedsAttention ? 'isce:text-destructive' : 'isce:text-muted-foreground'"
          >
            {{ sync.lastFailure.message }}
          </p>
        </template>
        <p v-else class="isce:text-muted-foreground">
          {{ t("sync.no_failure", "No failure recorded") }}
        </p>
      </SummaryCard>
    </div>

    <div v-if="sync" class="isce:mt-8 isce:space-y-6">
      <div class="isce:flex isce:flex-wrap isce:items-center isce:justify-between isce:gap-3">
        <h2 class="isce:text-lg">{{ t("dashboard.what_is_synced", "What is synced") }}</h2>
        <Button
          v-if="syncSettingsRoute"
          variant="outline"
          as="a"
          :href="syncSettingsRoute.href"
          @click="syncSettingsRoute.onClick"
        >
          <Settings />
          {{ t("sync.change_options", "Change options") }}
        </Button>
      </div>

      <OptionsGroup :title="t('sync.catalog', 'Catalog (products and categories)')">
        <div>
          <EnabledBadge :enabled="sync.catalog" />
        </div>
        <ul class="isce:list-disc isce:pl-5 isce:text-muted-foreground isce:space-y-1">
          <li v-for="event in catalogEvents" :key="event">{{ event }}</li>
        </ul>
      </OptionsGroup>

      <OptionsGroup :title="t('sync.orders', 'Orders')">
        <div>
          <EnabledBadge :enabled="sync.orders" />
        </div>
        <ul class="isce:list-disc isce:pl-5 isce:text-muted-foreground isce:space-y-1">
          <li v-for="event in orderEvents" :key="event">{{ event }}</li>
        </ul>
      </OptionsGroup>

      <OptionsGroup :title="t('sync.how_it_works', 'How it works')">
        <p class="isce:text-muted-foreground">
          {{
            t(
              "sync.how_it_works_text",
              "Each change enters a queue and is sent within a few seconds. If SquidCore cannot be reached, the plugin tries again after 1 minute and after 5 minutes.",
            )
          }}
        </p>
      </OptionsGroup>
    </div>
  </div>
</template>
