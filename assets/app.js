/* ============================================================
   Pulse · 前端交互 + 轻量 Canvas 折线图（无第三方依赖）
   ============================================================ */
(function () {
  'use strict';

  /* ---------------- 通用工具 ---------------- */

  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.prototype.slice.call((root || document).querySelectorAll(sel));

  function formatBytes(bytes, precision) {
    if (bytes === null || bytes === undefined || isNaN(bytes)) return '—';
    const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    let v = Number(bytes);
    let i = 0;
    while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
    const dec = i === 0 ? 0 : (precision === undefined ? 1 : precision);
    return parseFloat(v.toFixed(dec)) + ' ' + units[i];
  }

  function formatSpeed(bps) {
    if (bps === null || bps === undefined || isNaN(bps)) return '—';
    return formatBytes(bps) + '/s';
  }

  function formatPercent(v) {
    if (v === null || v === undefined || isNaN(v)) return '—';
    return Number(v).toFixed(1) + '%';
  }

  /* ---------------- 复制 / 确认 ---------------- */

  document.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-copy]');
    if (!btn) return;
    const target = $(btn.getAttribute('data-copy'));
    if (!target) return;

    const text = target.textContent.trim();
    const done = function () {
      const original = btn.textContent;
      btn.textContent = '已复制';
      setTimeout(function () { btn.textContent = original; }, 1600);
    };

    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done).catch(fallback);
    } else {
      fallback();
    }

    function fallback() {
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); done(); } catch (err) { /* ignore */ }
      document.body.removeChild(ta);
    }
  });

  /* ---------------- 自绘弹窗（不使用浏览器原生 confirm/alert） ---------------- */

  const Modal = (function () {
    let active = null;

    function build(options) {
      const mask = document.createElement('div');
      mask.className = 'modal-mask';
      mask.setAttribute('role', 'dialog');
      mask.setAttribute('aria-modal', 'true');

      const box = document.createElement('div');
      box.className = 'modal';

      const title = document.createElement('h2');
      title.className = 'modal-title';
      title.textContent = options.title || '请确认';
      box.appendChild(title);

      if (options.message) {
        const body = document.createElement('div');
        body.className = 'modal-body';
        // 仅支持纯文本，避免把动态内容当 HTML 注入
        body.textContent = options.message;
        box.appendChild(body);
      }

      if (options.note) {
        const note = document.createElement('div');
        note.className = 'modal-note';
        note.textContent = options.note;
        box.appendChild(note);
      }

      const actions = document.createElement('div');
      actions.className = 'modal-actions';

      const cancel = document.createElement('button');
      cancel.type = 'button';
      cancel.className = 'btn btn-ghost';
      cancel.textContent = options.cancelText || '取消';

      const ok = document.createElement('button');
      ok.type = 'button';
      ok.className = 'btn ' + (options.danger ? 'modal-danger' : 'btn-primary');
      ok.textContent = options.okText || '确定';

      actions.appendChild(cancel);
      actions.appendChild(ok);
      box.appendChild(actions);
      mask.appendChild(box);

      return { mask: mask, ok: ok, cancel: cancel, box: box };
    }

    function close(result) {
      if (!active) return;
      const current = active;
      active = null;
      current.mask.classList.remove('is-open');
      document.removeEventListener('keydown', current.onKey, true);
      setTimeout(function () {
        if (current.mask.parentNode) current.mask.parentNode.removeChild(current.mask);
      }, 170);
      if (current.resolve) current.resolve(result);
    }

    function open(options) {
      return new Promise(function (resolve) {
        const parts = build(options);
        const onKey = function (e) {
          if (e.key === 'Escape') { e.preventDefault(); close(false); }
          else if (e.key === 'Enter') { e.preventDefault(); close(true); }
        };

        parts.ok.addEventListener('click', function () { close(true); });
        parts.cancel.addEventListener('click', function () { close(false); });
        parts.mask.addEventListener('mousedown', function (e) {
          if (e.target === parts.mask) close(false);
        });
        document.addEventListener('keydown', onKey, true);

        document.body.appendChild(parts.mask);
        // 强制一帧后再加 is-open，保证过渡动画生效
        requestAnimationFrame(function () { parts.mask.classList.add('is-open'); });

        active = { mask: parts.mask, onKey: onKey, resolve: resolve };
        parts.ok.focus();
      });
    }

    return {
      confirm: function (message, options) {
        return open(Object.assign({
          title: '请确认',
          message: message,
          okText: '确定',
          cancelText: '取消'
        }, options || {}));
      },
      alert: function (message, options) {
        return open(Object.assign({
          title: '提示',
          message: message,
          okText: '知道了'
        }, options || {}));
      }
    };
  })();

  window.PulseModal = Modal;

  /* ---------------- 危险操作确认（自绘弹窗） ---------------- */

  $$('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      // 已经确认过一次的提交直接放行
      if (form.dataset.confirmed === '1') return;
      e.preventDefault();

      Modal.confirm(form.getAttribute('data-confirm'), {
        title: form.getAttribute('data-confirm-title') || '请确认',
        okText: form.getAttribute('data-confirm-ok') || '确定',
        danger: form.querySelector('.btn-danger') !== null
      }).then(function (ok) {
        if (!ok) return;
        form.dataset.confirmed = '1';
        // 用 requestSubmit 触发真正的提交（会走浏览器的表单校验）
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          form.submit();
        }
      });
    });
  });


  /* ---------------- 图表主题 ---------------- */

  const THEME = {
    grid: 'rgba(255,255,255,.055)',
    axis: 'rgba(255,255,255,.10)',
    label: '#61728c',
    crosshair: 'rgba(255,255,255,.18)'
  };

  const PALETTE = {
    cpu:  { line: '#4f8cff', fill: 'rgba(79,140,255,.20)' },
    mem:  { line: '#6ee7f0', fill: 'rgba(110,231,240,.18)' },
    disk: { line: '#ffb547', fill: 'rgba(255,181,71,.16)' },
    up:   { line: '#6ee7f0', fill: 'rgba(110,231,240,.14)' },
    down: { line: '#a78bfa', fill: 'rgba(167,139,250,.14)' }
  };

  /* ---------------- 折线图 ---------------- */

  function LineChart(canvas, options) {
    this.canvas = canvas;
    this.ctx = canvas.getContext('2d');
    this.options = options || {};
    this.series = [];
    this.labels = [];
    this.hoverIndex = -1;
    this.padding = { top: 12, right: 14, bottom: 24, left: 48 };
    this.bind();
    this.resize();
  }

  LineChart.prototype.bind = function () {
    const self = this;

    this.canvas.addEventListener('mousemove', function (e) {
      const rect = self.canvas.getBoundingClientRect();
      const x = e.clientX - rect.left;
      self.hoverIndex = self.indexAt(x);
      self.draw();
    });

    this.canvas.addEventListener('mouseleave', function () {
      self.hoverIndex = -1;
      self.draw();
    });

    if (window.ResizeObserver) {
      let raf = null;
      new ResizeObserver(function () {
        if (raf) cancelAnimationFrame(raf);
        raf = requestAnimationFrame(function () { self.resize(); });
      }).observe(this.canvas.parentElement);
    } else {
      window.addEventListener('resize', function () { self.resize(); });
    }
  };

  LineChart.prototype.setData = function (labels, series, fullLabels) {
    this.rawLabels = fullLabels || labels || [];
    this.labels = labels || [];
    this.series = series || [];
    this.draw();
  };

  LineChart.prototype.resize = function () {
    const rect = this.canvas.parentElement.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;
    const w = Math.max(120, rect.width);
    const h = Math.max(80, rect.height);
    this.canvas.width = Math.round(w * dpr);
    this.canvas.height = Math.round(h * dpr);
    this.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    this.width = w;
    this.height = h;
    this.draw();
  };

  LineChart.prototype.plotArea = function () {
    const p = this.padding;
    return {
      x: p.left,
      y: p.top,
      w: Math.max(10, this.width - p.left - p.right),
      h: Math.max(10, this.height - p.top - p.bottom)
    };
  };

  LineChart.prototype.indexAt = function (px) {
    const area = this.plotArea();
    const count = this.labels.length;
    if (count < 1 || px < area.x - 6 || px > area.x + area.w + 6) return -1;
    const step = count > 1 ? area.w / (count - 1) : 0;
    if (count === 1) return 0;
    const idx = Math.round((px - area.x) / step);
    return Math.max(0, Math.min(count - 1, idx));
  };

  LineChart.prototype.draw = function () {
    const ctx = this.ctx;
    const area = this.plotArea();
    const labels = this.labels;
    const series = this.series;

    ctx.clearRect(0, 0, this.width, this.height);

    // ---- 计算 Y 轴范围 ----
    let max = 0;
    let hasValue = false;
    series.forEach(function (s) {
      s.data.forEach(function (v) {
        if (v !== null && v !== undefined && !isNaN(v)) {
          hasValue = true;
          if (v > max) max = v;
        }
      });
    });

    const opts = this.options;
    let yMax;
    if (opts.fixedMax !== undefined && opts.fixedMax !== null) {
      yMax = opts.fixedMax;
    } else if (opts.unit === 'bps') {
      yMax = niceBytes(max);
    } else if (!hasValue || max <= 0) {
      yMax = 10;
    } else {
      yMax = niceCeil(max * 1.18);
    }

    const yOf = (v) => area.y + area.h - (Math.min(v, yMax) / yMax) * area.h;

    // ---- 网格与 Y 轴刻度 ----
    ctx.font = '10px ui-monospace, Menlo, Consolas, monospace';
    ctx.textBaseline = 'middle';
    const ticks = 4;
    for (let i = 0; i <= ticks; i++) {
      const value = (yMax / ticks) * i;
      const y = Math.round(yOf(value)) + 0.5;

      ctx.beginPath();
      ctx.strokeStyle = i === 0 ? THEME.axis : THEME.grid;
      ctx.lineWidth = 1;
      ctx.moveTo(area.x, y);
      ctx.lineTo(area.x + area.w, y);
      ctx.stroke();

      ctx.fillStyle = THEME.label;
      ctx.textAlign = 'right';
      const text = opts.unit === 'bps' ? formatBytes(value, 0) : String(Math.round(value)) + (opts.unit === '%' ? '%' : '');
      ctx.fillText(text, area.x - 8, y);
    }
    if (!labels.length) {
      ctx.fillStyle = THEME.label;
      ctx.textAlign = 'center';
      ctx.font = '12px -apple-system, "Segoe UI", "Microsoft YaHei", sans-serif';
      ctx.fillText('暂无数据', area.x + area.w / 2, area.y + area.h / 2);
      return;
    }

    // ---- X 轴标签 ----
    ctx.textBaseline = 'top';
    const maxLabels = Math.max(2, Math.floor(area.w / 78));
    const stride = Math.max(1, Math.ceil(labels.length / maxLabels));
    for (let i = 0; i < labels.length; i += stride) {
      const x = labels.length > 1 ? area.x + (area.w * i) / (labels.length - 1) : area.x + area.w / 2;
      ctx.fillStyle = THEME.label;
      if (labels.length === 1) {
        ctx.textAlign = 'center';
      } else {
        ctx.textAlign = i === 0 ? 'left' : (i + stride >= labels.length ? 'right' : 'center');
      }
      ctx.fillText(labels[i], x, area.y + area.h + 7);
    }

    // ---- 折线 ----
    const self = this;
    series.forEach(function (s) {
      const color = PALETTE[s.key] || PALETTE.cpu;
      const points = [];

      s.data.forEach(function (v, i) {
        if (v === null || v === undefined || isNaN(v)) {
          points.push(null);
          return;
        }
        const x = s.data.length > 1 ? area.x + (area.w * i) / (s.data.length - 1) : area.x + area.w / 2;
        points.push({ x: x, y: yOf(v) });
      });

      // 断点分段绘制，避免把缺失值连成直线
      const segments = [];
      let current = [];
      points.forEach(function (p) {
        if (p === null) {
          if (current.length) { segments.push(current); current = []; }
        } else {
          current.push(p);
        }
      });
      if (current.length) segments.push(current);

      // 面积填充
      segments.forEach(function (seg) {
        if (seg.length < 2) return;
        const grad = ctx.createLinearGradient(0, area.y, 0, area.y + area.h);
        grad.addColorStop(0, color.fill);
        grad.addColorStop(1, 'rgba(0,0,0,0)');

        ctx.beginPath();
        ctx.moveTo(seg[0].x, area.y + area.h);
        seg.forEach(function (p) { ctx.lineTo(p.x, p.y); });
        ctx.lineTo(seg[seg.length - 1].x, area.y + area.h);
        ctx.closePath();
        ctx.fillStyle = grad;
        ctx.fill();
      });

      // 线条
      ctx.lineWidth = 2;
      ctx.lineJoin = 'round';
      ctx.lineCap = 'round';
      ctx.strokeStyle = color.line;
      ctx.shadowColor = color.line;
      ctx.shadowBlur = 8;

      segments.forEach(function (seg) {
        ctx.beginPath();
        if (seg.length === 1) {
          ctx.arc(seg[0].x, seg[0].y, 1.6, 0, Math.PI * 2);
          ctx.fillStyle = color.line;
          ctx.fill();
          return;
        }
        seg.forEach(function (p, i) {
          if (i === 0) ctx.moveTo(p.x, p.y);
          else ctx.lineTo(p.x, p.y);
        });
        ctx.stroke();
      });

      ctx.shadowBlur = 0;
    });

    // ---- 悬停十字线与数值 ----
    const idx = this.hoverIndex;
    if (idx >= 0 && idx < labels.length) {
      const x = labels.length > 1 ? area.x + (area.w * idx) / (labels.length - 1) : area.x + area.w / 2;

      ctx.beginPath();
      ctx.strokeStyle = THEME.crosshair;
      ctx.lineWidth = 1;
      ctx.setLineDash([3, 3]);
      ctx.moveTo(x, area.y);
      ctx.lineTo(x, area.y + area.h);
      ctx.stroke();
      ctx.setLineDash([]);

      const lines = [(this.rawLabels && this.rawLabels[idx]) || labels[idx]];
      series.forEach(function (s) {
        const v = s.data[idx];
        if (v === null || v === undefined || isNaN(v)) return;
        const text = self.options.unit === 'bps' ? formatSpeed(v) : formatPercent(v);
        lines.push(s.name + ' ' + text);

        const y = yOf(v);
        ctx.beginPath();
        ctx.arc(x, y, 3.2, 0, Math.PI * 2);
        ctx.fillStyle = (PALETTE[s.key] || PALETTE.cpu).line;
        ctx.fill();
        ctx.strokeStyle = '#0b0f16';
        ctx.lineWidth = 2;
        ctx.stroke();
      });

      // 提示框
      ctx.font = '11px -apple-system, "Segoe UI", "Microsoft YaHei", sans-serif';
      let boxW = 0;
      lines.forEach(function (t) { boxW = Math.max(boxW, ctx.measureText(t).width); });
      boxW += 18;
      const boxH = lines.length * 16 + 10;

      let bx = x + 12;
      if (bx + boxW > area.x + area.w) bx = x - boxW - 12;
      let by = area.y + 6;

      ctx.fillStyle = 'rgba(10,16,26,.94)';
      ctx.strokeStyle = 'rgba(255,255,255,.10)';
      ctx.lineWidth = 1;
      roundRect(ctx, bx, by, boxW, boxH, 7);
      ctx.fill();
      ctx.stroke();

      ctx.textAlign = 'left';
      ctx.textBaseline = 'middle';
      lines.forEach(function (t, i) {
        ctx.fillStyle = i === 0 ? '#8fa1bd' : '#e7edf7';
        ctx.fillText(t, bx + 9, by + 13 + i * 16);
      });
    }
  };

  function roundRect(ctx, x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
  }

  function niceCeil(value) {
    if (value <= 0) return 10;
    const exp = Math.floor(Math.log10(value));
    const base = Math.pow(10, exp);
    const norm = value / base;
    let step;
    if (norm <= 1) step = 1;
    else if (norm <= 2) step = 2;
    else if (norm <= 2.5) step = 2.5;
    else if (norm <= 5) step = 5;
    else step = 10;
    return step * base;
  }

  /** 字节量级取整：把最大值向上取到 1/2/2.5/5/10 × 1024^n，便于显示整数刻度 */
  function niceBytes(value) {
    if (value <= 0) return 1024;
    const units = [1, 1024, 1024 ** 2, 1024 ** 3, 1024 ** 4];
    let unit = units[0];
    for (let i = units.length - 1; i >= 0; i--) {
      if (value >= units[i]) { unit = units[i]; break; }
    }
    return niceCeil(value / unit) * unit;
  }

  /* ---------------- 页面初始化：详情页图表 ---------------- */

  function initDetailCharts() {
    const payload = window.PULSE_CHART;
    if (!payload) return;

    const definitions = [
      { id: 'chart-cpu',  series: [{ key: 'cpu', name: 'CPU', dataKey: 'cpu' }], unit: '%', fixedMax: 100 },
      { id: 'chart-mem',  series: [{ key: 'mem', name: '内存', dataKey: 'mem' }], unit: '%', fixedMax: 100 },
      { id: 'chart-disk', series: [{ key: 'disk', name: '硬盘', dataKey: 'disk' }], unit: '%', fixedMax: 100 },
      {
        id: 'chart-net',
        unit: 'bps',
        series: [
          { key: 'up', name: '上行', dataKey: 'up' },
          { key: 'down', name: '下行', dataKey: 'down' }
        ]
      }
    ];

    const charts = {};

    function apply(data) {
      definitions.forEach(function (def) {
        const canvas = document.getElementById(def.id);
        if (!canvas) return;
        if (!charts[def.id]) {
          charts[def.id] = new LineChart(canvas, { unit: def.unit, fixedMax: def.fixedMax });
        }
        // labels 用于坐标轴（只显示时间），fullLabels 用于悬停提示（带日期）
        charts[def.id].setData(
          data.labels,
          def.series.map(function (s) {
            return { key: s.key, name: s.name, data: data[s.dataKey] || [] };
          }),
          data.fullLabels
        );
      });
    }

    apply(payload.data || {});

    // ---- 自动刷新（30s）----
    const seriesUrl = payload.seriesUrl;
    if (!seriesUrl) return;

    setInterval(function () {
      if (document.hidden) return;
      fetch(seriesUrl, {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin'
      })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (json) {
          if (!json || !json.ok) return;
          apply(json.data);
          updateStats(json.data);
        })
        .catch(function () { /* 静默失败，等待下一轮 */ });
    }, 30000);

    function updateStats(data) {
      const last = function (arr) {
        if (!arr || !arr.length) return null;
        for (let i = arr.length - 1; i >= 0; i--) {
          if (arr[i] !== null && arr[i] !== undefined) return arr[i];
        }
        return null;
      };
      const set = function (id, text) {
        const el = document.getElementById(id);
        if (el) el.textContent = text;
      };
      set('stat-cpu', formatPercent(last(data.cpu)));
      set('stat-mem', formatPercent(last(data.mem)));
      if (!payload.swapDisabled) {
        set('stat-swap', formatPercent(last(data.swap)));
      }
      set('stat-disk', formatPercent(last(data.disk)));
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDetailCharts);
  } else {
    initDetailCharts();
  }
})();

/* ============================================================
   安装向导：浏览器实测伪静态是否可用
   ------------------------------------------------------------
   服务端无法可靠地探测 rewrite（自请求容易死锁），
   因此由浏览器真实发一次 fetch 才能得出真实结论。
   ============================================================ */
(function () {
  const form = document.getElementById('install-form');
  if (!form) return;

  const flag = document.getElementById('rewrite-ok');
  const button = form.querySelector('button[type="submit"]');
  const probeUrl = form.getAttribute('data-probe');
  const redirectInput = form.querySelector('input[name="oidc_redirect_uri"]');
  const initialRedirect = redirectInput ? redirectInput.value : '';

  if (!probeUrl || !flag || !button) return;

  function normalizeRedirect(url, rewrite) {
    if (!url) return url;
    if (rewrite) {
      return url.replace('/index.php/auth/oidc/callback', '/auth/oidc/callback');
    }
    if (url.indexOf('/index.php/') === -1) {
      return url.replace('/auth/oidc/callback', '/index.php/auth/oidc/callback');
    }
    return url;
  }

  const originalText = button.textContent;
  button.disabled = true;
  button.textContent = '正在准备…';

  fetch(probeUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
    .then(function (r) {
      // 只要这个地址返回了 JSON（哪怕 503 未安装），就说明请求被入口脚本接管了，
      // 即 mod_rewrite 正常工作。
      const type = r.headers.get('content-type') || '';
      return type.indexOf('application/json') !== -1;
    })
    .then(function (isJson) {
      flag.value = isJson ? '1' : '0';
      if (redirectInput && redirectInput.value === initialRedirect) {
        redirectInput.value = normalizeRedirect(initialRedirect, isJson);
      }
    })
    .catch(function () {
      flag.value = '0';
    })
    .finally(function () {
      button.disabled = false;
      button.textContent = originalText;
    });
})();

/* ============================================================
   安装向导：OIDC 字段按启用状态显示/隐藏
   ============================================================ */
(function () {
  const toggle = document.getElementById('oidc-enabled');
  const fields = document.getElementById('oidc-fields');
  if (!toggle || !fields) return;

  const sync = function () {
    fields.hidden = !toggle.checked;
  };

  toggle.addEventListener('change', sync);
  sync();
})();
