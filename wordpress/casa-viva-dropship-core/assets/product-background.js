/* Place the source photo above a generated backdrop. Only the backdrop is synthesized. */
window.cvProductBackground = function composeProduct(source, background, vertical, protectWhole = false) {
  // Social size: only the plain backdrop is stretched; the real photo keeps its own resolution.
  const canvas = document.createElement('canvas');
  canvas.width = 1080;
  canvas.height = vertical ? 1920 : 1080;
  const ctx = canvas.getContext('2d');
  ctx.drawImage(background, 0, 0, canvas.width, canvas.height);
  const layer = document.createElement('canvas');
  const sw = source.naturalWidth || source.width, sh = source.naturalHeight || source.height;
  const scale = Math.min(canvas.width * 0.90 / sw, canvas.height * 0.90 / sh, 1);
  const w = layer.width = Math.max(1, Math.round(sw * scale));
  const h = layer.height = Math.max(1, Math.round(sh * scale));
  const fg = layer.getContext('2d', {willReadFrequently:true});
  fg.drawImage(source, 0, 0, w, h);
  if (!protectWhole) {
    const pixels = fg.getImageData(0, 0, w, h), rgba = pixels.data, count = w * h;
    const paper = new Uint8Array(count);
    let hasAlpha = false, whiteEdge = 0, edge = 0;
    for (let i = 0; i < count; i++) {
      const k = i * 4;
      if (rgba[k + 3] < 255) hasAlpha = true;
      paper[i] = Math.min(rgba[k], rgba[k + 1], rgba[k + 2]) >= 250 ? 1 : 0;
      if (i < w || i >= count - w || i % w === 0 || i % w === w - 1) {
        edge++; whiteEdge += paper[i];
      }
    }
    // Existing alpha is authoritative. On a complex photograph preserve the full frame.
    if (!hasAlpha && whiteEdge > 0.65 * edge) {
      const queue = new Uint32Array(count);
      let head = 0, tail = 0;
      function visit(i) {
        if (paper[i] !== 1) return;
        paper[i] = 2; queue[tail++] = i;
      }
      for (let x = 0; x < w; x++) { visit(x); visit(count - w + x); }
      for (let y = 0; y < h; y++) { visit(y * w); visit(y * w + w - 1); }
      while (head < tail) {
        const i = queue[head++], x = i % w;
        if (x) visit(i - 1);
        if (x + 1 < w) visit(i + 1);
        if (i >= w) visit(i - w);
        if (i + w < count) visit(i + w);
      }
      // Preserve even pale details and a two-pixel collar. Feather only exterior paper.
      const distance = new Uint8Array(count);
      for (let i = 0; i < count; i++) {
        if (paper[i] !== 2) continue;
        distance[i] = Math.min(9, i % w ? distance[i - 1] + 1 : 9, i >= w ? distance[i - w] + 1 : 9);
      }
      for (let i = count - 1; i >= 0; i--) {
        if (paper[i] !== 2) continue;
        distance[i] = Math.min(distance[i], i % w < w - 1 ? distance[i + 1] + 1 : 9, i < count - w ? distance[i + w] + 1 : 9);
        rgba[i * 4 + 3] = Math.round(255 * Math.max(0, 1 - Math.max(0, distance[i] - 2) / 6));
      }
      fg.putImageData(pixels, 0, 0);
    }
  }
  ctx.drawImage(layer, Math.floor((canvas.width - w) / 2), Math.floor((canvas.height - h) / 2));
  return canvas;
};
