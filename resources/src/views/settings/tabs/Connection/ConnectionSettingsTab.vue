<script setup lang="ts">
import Input from "@/components/infixs/input/Input.vue";
import CheckResultAlert from "@/components/shared/CheckResultAlert.vue";
import ConnectionStatusBadge from "@/components/shared/ConnectionStatusBadge.vue";
import { useConnectionActions } from "@/composables/useConnectionActions";
import { useI18n } from "@/composables/useI18n";
import { useOAuthConnect } from "@/composables/useOAuthConnect";
import { resolveErrorMessage } from "@/lib/errors";
import { formatDateTime } from "@/lib/utils";
import { useAppStore } from "@/stores/useAppStore";
import { useSettingsStore } from "@/stores/useSettingsStore";
import { useStatusStore } from "@/stores/useStatusStore";
import type { CheckResult, OAuthResultCode } from "@/types/api";
import {
  ChevronDown,
  ExternalLink,
  Eye,
  EyeOff,
  Loader2,
  LogOut,
  PlugZap,
  RefreshCw,
} from "lucide-vue-next";
import { toast } from "vue-sonner";
import OptionsGroup from "@/components/shared/OptionsGroup.vue";
import ConnectionSettingsSkeleton from "./ConnectionSettingsSkeleton.vue";

const { t } = useI18n();
const appStore = useAppStore();
const settingsStore = useSettingsStore();
const statusStore = useStatusStore();
const {
  connection: settings,
  oauth,
  isLoading,
  hasLoaded,
  isSavingConnection,
} = storeToRefs(settingsStore);
const { connection: status, connectionState, isTesting } =
  storeToRefs(statusStore);
const { confirmDisconnect } = useConnectionActions();
const { isStarting, startOAuth } = useOAuthConnect();
const route = useRoute();
const router = useRouter();

const token = ref("");
const showToken = ref(false);
const showManual = ref(false);
const result = ref<CheckResult | null>(null);

const shouldShowSkeleton = computed(
  () => isLoading.value && !hasLoaded.value,
);

const isBusy = computed(
  () => isSavingConnection.value || isTesting.value || isStarting.value,
);

const isConfigured = computed(() => (settings.value?.method ?? "") !== "");

const connectLabel = computed(() =>
  settings.value?.method === "oauth"
    ? t("connection.reconnect_with_squidcore", "Reconnect with SquidCore")
    : t("connection.connect_with_squidcore", "Connect with SquidCore"),
);

const tokenPlaceholder = computed(() =>
  settings.value?.hasToken
    ? t("settings.connection.token_keep", "{mask} (leave blank to keep)", {
        mask: settings.value.tokenMask,
      })
    : "sq_pat_...",
);

onMounted(async () => {
  try {
    await settingsStore.fetchSettings(true);
  } catch {
    toast.error(t("settings.load_error", "Error loading settings"));
  }

  // The token form is for stores that cannot use the button, or already connect with a token.
  showManual.value = !oauth.value?.available || settings.value?.method === "token";

  showOAuthOutcome();
});

/**
 * A refused start (SquidCore does not offer the button at this address) opens the manual connection.
 */
async function connectWithSquidCore() {
  if (!(await startOAuth())) showManual.value = true;
}

/**
 * SquidCore sent the browser back here: tell how it went and drop the marker from the address.
 */
function showOAuthOutcome() {
  const code = route.query.squidcore_oauth;

  if (typeof code !== "string" || code === "") return;

  const lastResult = oauth.value?.lastResult;
  const message =
    lastResult && lastResult.code === code
      ? lastResult.message
      : fallbackOutcome(code as OAuthResultCode);

  if (code === "connected") {
    toast.success(message);
  } else if (code === "unconfirmed" || code === "denied") {
    toast.warning(message);
  } else {
    toast.error(message);
  }

  statusStore.fetchStatus().catch(() => {});

  const query = { ...route.query };
  delete query.squidcore_oauth;
  router.replace({ query });
}

function fallbackOutcome(code: OAuthResultCode): string {
  switch (code) {
    case "connected":
      return t("settings.connection.oauth_connected", "Store connected to SquidCore.");
    case "denied":
      return t(
        "settings.connection.oauth_denied",
        "The authorization was cancelled in SquidCore, so the store was not connected.",
      );
    case "expired":
      return t(
        "settings.connection.oauth_expired",
        "The connection request expired or was already used. Click Connect with SquidCore again.",
      );
    default:
      return t(
        "settings.connection.oauth_failed",
        "The store could not be connected to SquidCore. Try again.",
      );
  }
}

function failure(error: unknown): CheckResult {
  return {
    connected: false,
    message: resolveErrorMessage(error),
    checkedAt: null,
    organization: null,
  };
}

async function save() {
  result.value = null;

  try {
    result.value = await settingsStore.saveConnection({
      token: token.value.trim(),
    });
    token.value = "";

    if (result.value.connected) {
      toast.success(t("settings.saved", "Settings saved successfully"));
    } else {
      toast.warning(
        t(
          "settings.connection.saved_not_connected",
          "Settings saved, but the connection was not confirmed.",
        ),
      );
    }
  } catch (error) {
    result.value = failure(error);
    toast.error(t("settings.save_error", "Error saving settings"));
  }
}

/**
 * @param typedToken Blank tests the saved connection.
 */
async function test(typedToken = "") {
  result.value = null;

  try {
    result.value = await statusStore.testConnection({
      token: typedToken,
    });
  } catch (error) {
    result.value = failure(error);
  }
}
</script>

<template>
  <div class="isce:space-y-6">
    <div class="isce:space-y-1">
      <h2 class="isce:text-lg isce:font-semibold">
        {{ t("settings.connection.title", "SquidCore Connection") }}
      </h2>
      <p class="isce:text-sm isce:text-muted-foreground">
        {{
          t(
            "settings.connection.description",
            "Connect this store to your SquidCore organization: click Connect with SquidCore, sign in and authorize the store.",
          )
        }}
      </p>
    </div>

    <ConnectionSettingsSkeleton v-if="shouldShowSkeleton || !settings" />

    <template v-else>
      <OptionsGroup :title="t('settings.connection.status', 'Status')">
        <div class="isce:flex isce:flex-wrap isce:items-center isce:gap-3">
          <ConnectionStatusBadge
            v-if="connectionState !== 'loading'"
            :state="connectionState"
          />
          <Skeleton v-else class="isce:h-6 isce:w-28" />
          <span v-if="status?.organization" class="isce:font-medium">
            {{ status.organization.name }}
          </span>
        </div>
        <p
          v-if="status?.method"
          class="isce:text-xs isce:text-muted-foreground"
        >
          {{
            status.method === "oauth"
              ? t(
                  "settings.connection.method_oauth",
                  "Connection method: the SquidCore para WooCommerce app, authorized in SquidCore. It can also be revoked there, under Settings › Connected apps.",
                )
              : t(
                  "settings.connection.method_token",
                  "Connection method: access token (manual connection).",
                )
          }}
        </p>
        <p
          v-if="status?.lastCheckAt"
          class="isce:text-xs isce:text-muted-foreground"
        >
          {{
            t("connection.last_check", "Last check: {date}", {
              date: formatDateTime(status.lastCheckAt, appStore.locale),
            })
          }}
        </p>
        <p
          v-if="!result && status && !status.connected && status.lastError"
          class="isce:text-sm isce:text-destructive isce:break-words"
        >
          {{ status.lastError }}
        </p>
        <p
          v-if="status?.apiUrlOverridden"
          class="isce:text-xs isce:break-words"
          :class="
            status.apiUrlError
              ? 'isce:text-destructive'
              : 'isce:text-muted-foreground'
          "
        >
          {{
            t(
              "settings.connection.api_url_overridden",
              "Using the API set in wp-config.php (INFIXS_SQUIDCORE_API_URL): {url}",
              { url: status.apiUrl },
            )
          }}
        </p>
      </OptionsGroup>

      <OptionsGroup
        :title="t('connection.connect_with_squidcore', 'Connect with SquidCore')"
      >
        <div>
          <Button
            :disabled="isBusy || !oauth?.available"
            @click="connectWithSquidCore"
          >
            <Loader2 v-if="isStarting" class="isce:animate-spin" />
            <PlugZap v-else />
            {{ connectLabel }}
          </Button>
        </div>
        <p
          v-if="oauth?.available"
          class="isce:text-xs isce:text-muted-foreground"
        >
          {{
            t(
              "settings.connection.oauth_hint",
              "You sign in to SquidCore, choose the company and authorize this store. The plugin creates the WooCommerce REST API keys SquidCore needs, so no token is copied by hand.",
            )
          }}
        </p>
        <p
          v-else-if="oauth?.reason"
          class="isce:text-xs isce:text-muted-foreground isce:break-words"
        >
          {{ oauth.reason }}
        </p>
      </OptionsGroup>

      <div
        v-if="isConfigured"
        class="isce:flex isce:flex-wrap isce:gap-3 isce:justify-start"
      >
        <Button variant="outline" :disabled="isBusy" @click="test()">
          <Loader2 v-if="isTesting" class="isce:animate-spin" />
          <RefreshCw v-else />
          {{ t("connection.test_connection", "Test connection") }}
        </Button>
        <Button
          variant="ghost"
          class="isce:text-destructive isce:hover:text-destructive"
          :disabled="isBusy"
          @click="confirmDisconnect"
        >
          <LogOut />
          {{ t("connection.disconnect", "Disconnect") }}
        </Button>
      </div>

      <div class="isce:space-y-4 isce:border-t isce:pt-4">
        <button
          type="button"
          class="isce:inline-flex isce:items-center isce:gap-1 isce:text-sm isce:font-medium isce:text-foreground isce:hover:underline"
          :aria-expanded="showManual"
          aria-controls="squidcore-manual-connection"
          @click="showManual = !showManual"
        >
          <ChevronDown
            class="isce:size-4 isce:transition-transform"
            :class="showManual ? 'isce:rotate-180' : ''"
          />
          {{ t("settings.connection.manual_title", "Manual connection (token)") }}
        </button>

        <div
          v-show="showManual"
          id="squidcore-manual-connection"
          class="isce:space-y-4"
        >
          <p class="isce:text-xs isce:text-muted-foreground isce:max-w-3xl">
            {{
              t(
                "settings.connection.manual_description",
                "For stores that cannot use Connect with SquidCore: paste a plugin token generated in SquidCore. Saving a token replaces a connection made with Connect with SquidCore.",
              )
            }}
          </p>

          <OptionsGroup :title="t('settings.connection.token', 'Access token')">
            <Input
              id="squidcore-token"
              v-model="token"
              :type="showToken ? 'text' : 'password'"
              autocomplete="new-password"
              spellcheck="false"
              :label="t('settings.connection.token', 'Access token')"
              :placeholder="tokenPlaceholder"
            >
              <template #append>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  class="isce:mr-1"
                  :aria-label="
                    showToken
                      ? t('settings.connection.hide_token', 'Hide token')
                      : t('settings.connection.show_token', 'Show token')
                  "
                  @click="showToken = !showToken"
                >
                  <EyeOff v-if="showToken" />
                  <Eye v-else />
                </Button>
              </template>
            </Input>
            <p class="isce:text-xs isce:text-muted-foreground">
              {{
                t(
                  "settings.connection.token_hint",
                  "Generate it in SquidCore under Integrations › WooCommerce › Synchronization › Generate plugin token. The saved token is never shown again.",
                )
              }}
            </p>
            <a
              :href="appStore.integrationsUrl"
              target="_blank"
              rel="noopener noreferrer"
              class="isce:inline-flex isce:w-fit isce:items-center isce:gap-1 isce:text-xs isce:text-primary isce:hover:underline"
            >
              {{
                t(
                  "settings.connection.open_integrations",
                  "Open integrations in SquidCore",
                )
              }}
              <ExternalLink class="isce:size-3" />
            </a>
          </OptionsGroup>

          <div class="isce:flex isce:flex-wrap isce:gap-3 isce:justify-start">
            <Button variant="outline" :disabled="isBusy" @click="save">
              <Loader2 v-if="isSavingConnection" class="isce:animate-spin" />
              {{ t("settings.connection.save_token", "Save token") }}
            </Button>
            <Button
              v-if="token.trim() !== ''"
              variant="ghost"
              :disabled="isBusy"
              @click="test(token.trim())"
            >
              <RefreshCw />
              {{ t("settings.connection.test_token", "Test this token") }}
            </Button>
          </div>
        </div>
      </div>

      <CheckResultAlert v-if="result" :result="result" class="isce:max-w-3xl" />
    </template>
  </div>
</template>
