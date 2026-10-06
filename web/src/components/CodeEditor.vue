<script setup lang="ts">
import * as monaco from 'monaco-editor/esm/vs/editor/editor.api'
import 'monaco-editor/esm/vs/basic-languages/php/php.contribution'
import EditorWorker from 'monaco-editor/esm/vs/editor/editor.worker?worker'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

import { theme } from '../theme'

export interface Highlight {
  start: number
  end: number
  /** Scroll the range into view; off when the selection came from the editor itself. */
  reveal: boolean
}

const props = defineProps<{ modelValue: string; highlight: Highlight | null }>()
const emit = defineEmits<{
  'update:modelValue': [code: string]
  cursorLine: [line: number]
}>()

// Monaco needs a worker for its editor core. Only PHP tokenization is bundled, so no language workers.
self.MonacoEnvironment = { getWorker: () => new EditorWorker() }

const container = ref<HTMLElement>()
let editor: monaco.editor.IStandaloneCodeEditor | undefined
let decorations: monaco.editor.IEditorDecorationsCollection | undefined

onMounted(() => {
  monaco.editor.defineTheme('ariadne', {
    base: 'vs-dark',
    inherit: true,
    rules: [],
    colors: {
      'editor.background': theme.bg,
      'editorGutter.background': theme.bg,
      'editor.lineHighlightBackground': '#ffffff08',
      'editor.selectionBackground': `${theme.accent}33`,
      'editorLineNumber.foreground': theme.dim,
      'editorLineNumber.activeForeground': theme.muted,
      'editorCursor.foreground': theme.accent,
      'scrollbarSlider.background': '#ffffff14',
    },
  })

  editor = monaco.editor.create(container.value as HTMLElement, {
    value: props.modelValue,
    language: 'php',
    theme: 'ariadne',
    fontFamily: "'JetBrains Mono', ui-monospace, Menlo, monospace",
    padding: { top: 12 },
    renderLineHighlight: 'none',
    automaticLayout: true,
    minimap: { enabled: false },
    fontSize: 13,
    scrollBeyondLastLine: false,
  })
  decorations = editor.createDecorationsCollection()

  editor.onDidChangeModelContent(() => emit('update:modelValue', editor?.getValue() ?? ''))
  editor.onDidChangeCursorPosition((event) => emit('cursorLine', event.position.lineNumber))

  applyHighlight(props.highlight)
})

onBeforeUnmount(() => editor?.dispose())

watch(
  () => props.modelValue,
  (code) => {
    if (editor && editor.getValue() !== code) {
      editor.setValue(code)
    }
  },
)

watch(() => props.highlight, applyHighlight)

function applyHighlight(highlight: Highlight | null): void {
  if (!editor || !decorations) {
    return
  }

  if (!highlight) {
    decorations.clear()

    return
  }

  decorations.set([
    {
      range: new monaco.Range(highlight.start, 1, highlight.end, 1),
      options: { isWholeLine: true, className: 'ariadne-line-highlight' },
    },
  ])

  if (highlight.reveal) {
    editor.revealLinesInCenterIfOutsideViewport(highlight.start, highlight.end)
  }
}
</script>

<template>
  <div ref="container" class="code-editor" />
</template>

<style>
.code-editor {
  height: 100%;
  width: 100%;
}

.ariadne-line-highlight {
  background: rgba(var(--accent-rgb), 0.12);
  box-shadow: inset 2px 0 0 var(--accent);
}
</style>
