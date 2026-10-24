<script setup lang="ts">
import { FormField } from "@/components/ui/form";
import { useI18n } from "@/composables/useI18n";
import { useAppStore } from "@/stores/useAppStore";
import axios from "axios";
import { Frown, Loader2, MessageCircle, Smile } from "lucide-vue-next";
import { useForm } from "vee-validate";
import * as yup from "yup";

const open = ref(import.meta.env.DEV ? true : false);
const isLoading = ref(false);
const appStore = useAppStore();
const { t } = useI18n();
const deactivateLink = ref<string>();

const reasons = computed(() => [
  { value: "no-longer-needed", label: t("uninstall.reasons.no_longer_needed", "I no longer need the plugin") },
  { value: "found-better-plugin", label: t("uninstall.reasons.found_better_plugin", "I found a better plugin") },
  { value: "broke-my-site", label: t("uninstall.reasons.broke_my_site", "The plugin broke my site") },
  { value: "stopped-working", label: t("uninstall.reasons.stopped_working", "The plugin stopped working suddenly") },
  {
    value: "did-not-meet-expectations",
    label: t("uninstall.reasons.did_not_meet_expectations", "It did not meet my expectations"),
  },
  { value: "just-testing", label: t("uninstall.reasons.just_testing", "I am testing") },
  { value: "conflict-with-others", label: t("uninstall.reasons.conflict_with_others", "Conflict with other plugins") },
  { value: "other", label: t("uninstall.reasons.other", "Other") },
]);

const formSchema = yup.object().shape({
  reason: yup.string().required(t("uninstall.reason_required", "Select a reason.")),
  email: yup.string().email(t("uninstall.invalid_email", "Invalid email or leave empty.")),
  description: yup.string().max(1000),
});

const form = useForm<{
  reason: string;
  email: string;
  description: string;
}>({
  initialValues: {
    email: "",
    description: "",
  },
  validationSchema: formSchema,
});

const handleDeactivate = () => {
  if (deactivateLink.value) window.location.href = deactivateLink.value;
  else window.location.reload();
};

// Deactivates without sending anything: the feedback only goes out through "Submit & deactivate".
const skipAndDeactivate = () => {
  isLoading.value = true;
  handleDeactivate();
};

const onSubmit = form.handleSubmit(async (values) => {
  isLoading.value = true;
  const nonce = appStore.unistallNonce;

  if (!nonce) {
    handleDeactivate();
    return;
  }

  const formData = new URLSearchParams();
  formData.append("action", "infixs_squidcore_deactivate");
  formData.append("nonce", nonce);
  formData.append("reason", values.reason);
  formData.append("email", values.email);
  formData.append("description", values.description);

  try {
    await axios.post(window.ajaxurl, formData, {
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
      },
    });
  } catch (error) {
    console.error(error);
  } finally {
    handleDeactivate();
  }
});

onMounted(() => {
  if (!appStore.pluginBasename) return;

  // The folder name depends on how the plugin was installed (zip, clone), so the row is found by the real basename.
  const deactivateButton = document.querySelector<HTMLAnchorElement>(
    `tr[data-plugin="${CSS.escape(appStore.pluginBasename)}"] .deactivate a`,
  );

  if (deactivateButton) {
    deactivateLink.value = deactivateButton.href;
    deactivateButton.addEventListener("click", (e) => {
      e.preventDefault();
      open.value = true;
    });
  }
});
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="isce:sm:max-w-[min(48rem,calc(100%_-_2rem))]">
      <form @submit.prevent="onSubmit" class="isce:grid isce:gap-4">
        <DialogHeader>
          <DialogTitle>{{ t("uninstall.title", "Can we help you?") }}</DialogTitle>
          <DialogDescription>
            {{
              t(
                "uninstall.description",
                "It's a shame you are about to deactivate SquidCore. If you have any questions or issues, please contact support.",
              )
            }}
          </DialogDescription>
        </DialogHeader>
        <div class="isce:space-y-4">
          <FormField
            v-slot="{ componentField, errors, setErrors }"
            name="reason"
          >
            <FormItem>
              <FormControl>
                <Select
                  v-bind="componentField"
                  @update:model-value="() => setErrors([])"
                >
                  <SelectTrigger
                    class="isce:w-full"
                    :class="{ 'isce:border-red-500': errors.length > 0 }"
                  >
                    <SelectValue
                      :placeholder="t('uninstall.reason_placeholder', 'Select a reason')"
                      :class="[
                        !componentField.modelValue && 'isce:text-gray-400',
                      ]"
                    />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem
                      v-for="reason in reasons"
                      :key="reason.value"
                      :value="reason.value"
                    >
                      {{ reason.label }}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </FormControl>
              <FormMessage />
            </FormItem>
          </FormField>

          <FormField
            v-slot="{ componentField, errors, setErrors }"
            name="email"
          >
            <FormItem>
              <FormControl>
                <input
                  :placeholder="t('uninstall.email_placeholder', 'Your email (optional)')"
                  v-bind="componentField"
                  class="isce:flex isce:h-10 isce:w-full isce:rounded-md isce:border isce:border-input isce:bg-background isce:px-3 isce:py-2 isce:text-sm isce:ring-offset-background file:isce:border-0 file:isce:bg-transparent file:isce:text-sm file:isce:font-medium placeholder:isce:text-muted-foreground focus-visible:isce:outline-none focus-visible:isce:ring-2 focus-visible:isce:ring-ring focus-visible:isce:ring-offset-2 disabled:isce:cursor-not-allowed disabled:isce:opacity-50"
                  :class="{ 'isce:border-red-500': errors.length > 0 }"
                  @input="setErrors([])"
                />
              </FormControl>
              <FormMessage />
            </FormItem>
          </FormField>
          <FormField
            v-slot="{ componentField, errors, setErrors }"
            name="description"
          >
            <FormItem>
              <FormControl>
                <textarea
                  :placeholder="
                    t(
                      'uninstall.description_placeholder',
                      'Write a comment, suggestion or question (optional)',
                    )
                  "
                  v-bind="componentField"
                  maxlength="1000"
                  class="isce:flex isce:min-h-[80px] isce:w-full isce:rounded-md isce:border isce:border-input isce:bg-background isce:px-3 isce:py-2 isce:text-sm isce:ring-offset-background placeholder:isce:text-muted-foreground focus-visible:isce:outline-none focus-visible:isce:ring-2 focus-visible:isce:ring-ring focus-visible:isce:ring-offset-2 disabled:isce:cursor-not-allowed disabled:isce:opacity-50"
                  :class="{ 'isce:border-red-500': errors.length > 0 }"
                  @input="setErrors([])"
                ></textarea>
              </FormControl>
              <FormMessage />
            </FormItem>
          </FormField>
        </div>
        <!-- Four buttons: they wrap on narrow screens instead of overflowing the dialog. -->
        <DialogFooter class="isce:sm:flex-wrap">
          <Button
            v-if="appStore.supportUrl"
            @click="open = false"
            variant="secondary"
            as="a"
            :href="appStore.supportUrl"
            target="_blank"
          >
            <MessageCircle />
            {{ t("help.whatsapp_support", "WhatsApp Support") }}
          </Button>
          <Button @click.prevent="open = false">
            <Smile />
            {{ t("uninstall.changed_mind", "I changed my mind") }}
          </Button>
          <Button
            type="button"
            variant="ghost"
            :disabled="isLoading"
            @click="skipAndDeactivate"
          >
            {{ t("uninstall.skip_deactivate", "Skip & deactivate") }}
          </Button>
          <Button type="submit" variant="outline" :disabled="isLoading">
            <Frown v-if="!isLoading" />
            <Loader2 v-else class="isce:animate-spin" />
            {{ t("uninstall.submit_deactivate", "Submit & deactivate") }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
