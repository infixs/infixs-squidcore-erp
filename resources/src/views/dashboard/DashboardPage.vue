<script setup lang="ts">
import ActivityCard from "@/components/dashboard/ActivityCard.vue";
import NcmCoverageCard from "@/components/dashboard/NcmCoverageCard.vue";
import OnboardingCard from "@/components/dashboard/OnboardingCard.vue";
import ConnectionStatusBadge from "@/components/shared/ConnectionStatusBadge.vue";
import EnabledBadge from "@/components/shared/EnabledBadge.vue";
import HealthBanner from "@/components/shared/HealthBanner.vue";
import SummaryCard from "@/components/shared/SummaryCard.vue";
import SyncActivity from "@/components/shared/SyncActivity.vue";
import { useConnectionActions } from "@/composables/useConnectionActions";
import { useInvoiceLabels } from "@/composables/useInvoiceLabels";
import { useI18n } from "@/composables/useI18n";
import { useOAuthConnect } from "@/composables/useOAuthConnect";
import { formatDateTime, formatRelativeTime, hostOf } from "@/lib/utils";
import { toRoute } from "@/routes/helpers";
import { useActivityStore } from "@/stores/useActivityStore";
import { useAppStore } from "@/stores/useAppStore";
import { useStatusStore } from "@/stores/useStatusStore";
import { useDocumentVisibility, useIntervalFn, useNow } from "@vueuse/core";
import {
  ArrowUpDown,
  ExternalLink,
  FileText,
  Loader2,
  PlugZap,
  RefreshCw,
  Send,
  Settings,
} from "lucide-vue-next";
import { toast } from "vue-sonner";

/** The dashboard refreshes itself this often while the tab is visible. */
const AUTO_REFRESH_MS = 60_000;

const { t } = useI18n();
const appStore = useAppStore();
const statusStore = useStatusStore();
const activityStore = useActivityStore();
const { connection, oauthAvailable, sync, invoices, connectionState, isTesting, lastFetchedAt, ncmCoverage, fiscalImport } =
  storeToRefs(statusStore);
const { last30Days } = storeToRefs(activityStore);
const { testSavedConnection } = useConnectionActions();
const { isStarting, startOAuth } = useOAuthConnect();
const { delayLabel } = useInvoiceLabels();

const connectionRoute = toRoute("settings-connection");
const syncSettingsRoute = toRoute("settings-sync");
const syncRoute = toRoute("sync");
const invoicesRoute = toRoute("invoices");

const invoicesWaiting = computed(() => invoices.value?.waiting ?? 0);
/** Counted in the background: the card waits for a first count (or one on the way). */
const showCoverage = computed(() => !!ncmCoverage.value && (ncmCoverage.value.total > 0 || ncmCoverage.value.computing));
/** The card offers the import only while the notice on top of the page does not (dismissed or snoozed). */
const canImportFromCard = computed(
  () => !!fiscalImport.value?.suggest && !fiscalImport.value.notices.some((notice) => notice.id === "webmania-import-ncm"),
);
const isRefreshing = computed(() => statusStore.isLoading || activityStore.isLoading);

// "Updated 2 minutes ago" keeps counting while the page stays open.
const now = useNow({ interval: 30_000 });
const updatedLabel = computed(() =>
  lastFetchedAt.value
    ? t("dashboard.updated_at", "Updated {when}", {
        when: formatRelativeTime(lastFetchedAt.value, appStore.locale, now.value),
      })
    : "",
);

/** The API host only matters when wp-config.php points the plugin to another API. */
const connectionDetails = computed(() => {
  if (!connection.value) return "";

  const details: string[] = [];

  if (connection.value.apiUrlOverridden) {
    details.push(hostOf(connection.value.apiUrl));
  }

  if (connection.value.lastCheckAt) {
    details.push(
      t("connection.last_check", "Last check: {date}", {
        date: formatDateTime(connection.value.lastCheckAt, appStore.locale),
      }),
    );
  }

  return details.join(" · ");
});

const connectionMethod = computed(() => {
  if (connection.value?.method === "oauth") {
    return t("connection.method_oauth_short", "Method: Connect with SquidCore");
  }

  if (connection.value?.method === "token") {
    return t("connection.method_token_short", "Method: access token");
  }

  return "";
});

const authorizedLast30Days = computed(() => last30Days.value?.totals.invoicesAuthorized ?? null);

const statusError = () => toast.error(t("dashboard.load_error", "Could not load the status."));
const activityError = () => toast.error(t("dashboard.activity.load_error", "Could not load the activity."));

// The activity only exists for a connected store: load it when the connection appears, drop it when it goes.
watch(
  () => connection.value?.configured ?? null,
  (configured) => {
    if (configured === null) return;

    if (configured) {
      activityStore.load().catch(activityError);
    } else {
      activityStore.clear();
    }
  },
  { immediate: true },
);

/** The data stays on screen while it reloads; the automatic refresh fails quietly. */
async function refresh(silent = false) {
  try {
    await statusStore.fetchStatus();
  } catch {
    if (!silent) statusError();
    return;
  }

  if (!connection.value?.configured) return;

  try {
    await activityStore.refresh();
  } catch {
    if (!silent) activityError();
  }
}

const visibility = useDocumentVisibility();
const { pause, resume } = useIntervalFn(() => refresh(true), AUTO_REFRESH_MS, { immediate: true });

watch(visibility, (state) => {
  if (state !== "visible") {
    pause();
    return;
  }

  resume();

  const age = lastFetchedAt.value ? Date.now() - lastFetchedAt.value.getTime() : Infinity;
  if (age >= AUTO_REFRESH_MS) refresh(true);
});

onMounted(() => {
  statusStore.fetchStatus().catch(statusError);
});
</script>

<template>
  <div>
    <div class="isce:flex isce:flex-wrap isce:items-start isce:justify-between isce:gap-3">
      <div>
        <h1 class="isce:text-xl">{{ t("dashboard.title", "Dashboard") }}</h1>
        <p class="isce:text-muted-foreground">
          {{
            t(
              "dashboard.description",
              "Follow the connection of this store with SquidCore.",
            )
          }}
        </p>
      </div>
      <div class="isce:flex isce:items-center isce:gap-3">
        <span v-if="updatedLabel" class="isce:text-muted-foreground">{{ updatedLabel }}</span>
        <Button variant="outline" :disabled="isRefreshing" @click="refresh()">
          <Loader2 v-if="isRefreshing" class="isce:animate-spin" />
          <RefreshCw v-else />
          {{ t("global.refresh", "Refresh") }}
        </Button>
      </div>
    </div>

    <HealthBanner class="isce:mt-5" />

    <div
      v-if="!connection || !sync || !invoices"
      class="isce:mt-5 isce:grid isce:grid-cols-1 isce:gap-4 isce:lg:grid-cols-2 isce:2xl:grid-cols-4"
    >
      <Skeleton v-for="i in 4" :key="i" class="isce:h-48 isce:w-full" />
    </div>

    <OnboardingCard v-else-if="!connection.configured" class="isce:mt-5" />

    <template v-else>
      <ActivityCard class="isce:mt-5" />

      <div class="isce:mt-4 isce:grid isce:grid-cols-1 isce:gap-4 isce:lg:grid-cols-2 isce:2xl:grid-cols-4">
        <SummaryCard :title="t('dashboard.connection', 'Connection')" :icon="PlugZap">
          <div>
            <ConnectionStatusBadge
              v-if="connectionState !== 'loading'"
              :state="connectionState"
            />
          </div>
          <p
            v-if="connection.organization"
            class="isce:text-lg isce:font-semibold isce:leading-tight"
          >
            {{ connection.organization.name }}
          </p>
          <p v-if="connectionMethod" class="isce:text-muted-foreground">{{ connectionMethod }}</p>
          <p v-if="connectionDetails" class="isce:text-muted-foreground">
            {{ connectionDetails }}
          </p>
          <p
            v-if="!connection.connected && connection.lastError"
            class="isce:text-destructive isce:break-words"
          >
            {{ connection.lastError }}
          </p>
          <template #actions>
            <Button
              v-if="connection.needsReconnect && oauthAvailable"
              :disabled="isStarting"
              @click="startOAuth(true)"
            >
              <Loader2 v-if="isStarting" class="isce:animate-spin" />
              <PlugZap v-else />
              {{ t("connection.reconnect_with_squidcore", "Reconnect with SquidCore") }}
            </Button>
            <Button
              v-else
              variant="outline"
              :disabled="isTesting"
              @click="testSavedConnection"
            >
              <Loader2 v-if="isTesting" class="isce:animate-spin" />
              <RefreshCw v-else />
              {{ t("connection.test_connection", "Test connection") }}
            </Button>
          </template>
        </SummaryCard>

        <SummaryCard
          :title="t('dashboard.what_is_synced', 'What is synced')"
          :icon="ArrowUpDown"
        >
          <div class="isce:flex isce:items-center isce:justify-between isce:gap-3">
            <span>{{ t("sync.catalog", "Catalog (products and categories)") }}</span>
            <EnabledBadge :enabled="sync.catalog" />
          </div>
          <div class="isce:flex isce:items-center isce:justify-between isce:gap-3">
            <span>{{ t("sync.orders", "Orders") }}</span>
            <EnabledBadge :enabled="sync.orders" />
          </div>
          <template #actions>
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
          </template>
        </SummaryCard>

        <SummaryCard :title="t('dashboard.sends', 'Sends to SquidCore')" :icon="Send">
          <!-- Guarded: a cached bundle may meet a backend that does not count the day yet. -->
          <p v-if="sync.today" class="isce:font-medium">
            {{
              t("dashboard.today", "Today: {sent} sent · {failed} failures", {
                sent: sync.today.sent,
                failed: sync.today.failed,
              })
            }}
          </p>
          <SyncActivity :sync="sync" />
          <template #actions>
            <Button
              v-if="syncRoute"
              variant="outline"
              as="a"
              :href="syncRoute.href"
              @click="syncRoute.onClick"
            >
              {{ t("dashboard.view_sync", "View synchronization") }}
            </Button>
          </template>
        </SummaryCard>

        <SummaryCard :title="t('dashboard.invoices.title', 'Invoices')" :icon="FileText">
          <div class="isce:flex isce:items-center isce:justify-between isce:gap-3">
            <span>{{ t("dashboard.invoices.automatic", "Automatic emission") }}</span>
            <EnabledBadge :enabled="invoices.enabled" />
          </div>
          <p v-if="invoices.enabled" class="isce:text-muted-foreground">
            {{ delayLabel(invoices.delayHours) }}
          </p>
          <div class="isce:flex isce:items-center isce:justify-between isce:gap-3">
            <span>{{ t("dashboard.invoices.waiting", "Waiting for you") }}</span>
            <span
              class="isce:font-semibold isce:tabular-nums"
              :class="invoicesWaiting > 0 && 'isce:text-destructive'"
            >
              {{ invoicesWaiting }}
            </span>
          </div>
          <div class="isce:flex isce:items-center isce:justify-between isce:gap-3">
            <span>{{ t("dashboard.invoices.in_progress", "In progress") }}</span>
            <span class="isce:font-semibold isce:tabular-nums">{{ invoices.inProgress }}</span>
          </div>
          <div
            v-if="authorizedLast30Days !== null"
            class="isce:flex isce:items-center isce:justify-between isce:gap-3"
          >
            <span>{{ t("dashboard.invoices.authorized_30_days", "Authorized (30 days)") }}</span>
            <span class="isce:font-semibold isce:tabular-nums">{{ authorizedLast30Days }}</span>
          </div>
          <template #actions>
            <Button
              v-if="invoicesRoute"
              variant="outline"
              as="a"
              :href="invoicesRoute.href"
              @click="invoicesRoute.onClick"
            >
              {{ t("dashboard.invoices.view", "View invoices") }}
            </Button>
          </template>
        </SummaryCard>
      </div>
    </template>

    <NcmCoverageCard
      v-if="connection && sync && invoices && showCoverage && ncmCoverage"
      class="isce:mt-4"
      :coverage="ncmCoverage"
      :can-import="canImportFromCard"
    />

    <div class="isce:mt-8">
      <h2 class="isce:text-lg">{{ t("dashboard.shortcuts", "Shortcuts") }}</h2>
      <div class="isce:mt-3 isce:flex isce:flex-wrap isce:gap-3">
        <Button
          v-if="connectionRoute"
          variant="outline"
          as="a"
          :href="connectionRoute.href"
          @click="connectionRoute.onClick"
        >
          <PlugZap />
          {{ t("connection.settings", "Connection settings") }}
        </Button>
        <Button
          v-if="syncSettingsRoute"
          variant="outline"
          as="a"
          :href="syncSettingsRoute.href"
          @click="syncSettingsRoute.onClick"
        >
          <ArrowUpDown />
          {{ t("sync.options", "Sync options") }}
        </Button>
        <Button
          variant="outline"
          as="a"
          :href="appStore.appUrl"
          target="_blank"
          rel="noopener noreferrer"
        >
          <ExternalLink />
          {{ t("connection.open_squidcore", "Open SquidCore") }}
        </Button>
      </div>
    </div>
  </div>
</template>
