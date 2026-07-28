<script setup lang="ts">
import type { DialogContentEmits, DialogContentProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { X } from "@lucide/vue"
import { reactiveOmit } from "@vueuse/core"
import {
  DialogClose,
  DialogContent,
  DialogPortal,
  useForwardPropsEmits,
} from "reka-ui"
import { cn } from "@/lib/utils"
import { Button } from "@/components/ui/button"
import DialogOverlay from "./DialogOverlay.vue"

defineOptions({
  inheritAttrs: false,
})

const props = withDefaults(defineProps<DialogContentProps & { class?: HTMLAttributes["class"], showCloseButton?: boolean }>(), {
  showCloseButton: true,
})
const emits = defineEmits<DialogContentEmits>()

const delegatedProps = reactiveOmit(props, "class")

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <DialogPortal to="#admin-portal-root">
    <!--
      Center with grid on the overlay (not translate(-50%,-50%) on the panel).
      Translate + Leaflet/map children causes Chrome to blur dialog text.
    -->
    <DialogOverlay
      class="grid place-items-center overflow-y-auto p-4"
    >
      <DialogContent
        data-slot="dialog-content"
        v-bind="{ ...$attrs, ...forwarded }"
        :class="
          cn(
            'relative z-50 grid w-full max-w-[calc(100%-2rem)] gap-4 rounded-lg border bg-background p-6 shadow-lg sm:max-w-lg',
            props.class,
          )
        "
      >
        <slot />

        <DialogClose v-if="showCloseButton" as-child>
          <Button
            variant="ghost"
            size="icon-sm"
            class="absolute top-4 right-4 text-muted-foreground"
          >
            <X />
            <span class="sr-only">Close</span>
          </Button>
        </DialogClose>
      </DialogContent>
    </DialogOverlay>
  </DialogPortal>
</template>
