<template>
  <ul class="legend" aria-label="Legend">
    <li><span class="swatch call" />call</li>
    <li><span class="swatch group" />class</li>
    <li><span class="swatch unresolved" />unresolved</li>
  </ul>
</template>

<style>
.legend {
  position: absolute;
  bottom: 14px;
  left: 14px;
  z-index: 5;
  display: flex;
  gap: 16px;
  margin: 0;
  padding: 8px 14px;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: rgba(20, 21, 25, 0.85);
  color: var(--muted);
  font-size: 12px;
  list-style: none;
  backdrop-filter: blur(6px);
}

.legend li {
  display: flex;
  align-items: center;
  gap: 8px;
}

.swatch {
  width: 22px;
  height: 0;
  border-top: 2px solid var(--accent);
}

.swatch.group {
  height: 12px;
  border: 1.5px solid var(--border-strong);
  border-radius: 4px;
  background: rgba(255, 255, 255, 0.03);
}

.swatch.unresolved {
  height: 10px;
  border: 1.5px dashed var(--muted);
  border-radius: 4px;
}
</style>
