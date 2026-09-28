(function (window, document) {
  'use strict';

  if (typeof Chart === 'undefined') { return; }

  var css = getComputedStyle(document.documentElement);

  function token(name, fallback) {
    var v = css.getPropertyValue(name);
    return (v && v.trim()) || fallback;
  }

  var INK        = token('--ink', '#131D3B');
  var INK_MUTED  = token('--ink-muted', '#5A6785');
  var INK_FAINT  = token('--ink-faint', '#8B96AE');
  var HAIRLINE   = token('--hairline', '#E6EAF2');
  var SOFT       = token('--hairline-soft', '#EFF2F8');
  var SURFACE    = token('--surface', '#FFFFFF');
  var ORANGE     = token('--orange-500', '#FF4F01');

  var FONT = '"Inter", "Segoe UI", system-ui, -apple-system, sans-serif';

  var reduceMotion = window.matchMedia &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  var PALETTE = [
    ORANGE, '#2563EB', '#059669', '#B45309',
    token('--navy-400', '#6B7FA8'), '#DC2626',
    token('--navy-600', '#33456B'), token('--orange-300', '#FF9970')
  ];

  Chart.defaults.font.family = FONT;
  Chart.defaults.font.size = 11.5;
  Chart.defaults.color = INK_FAINT;
  Chart.defaults.animation.duration = reduceMotion ? 0 : 520;
  Chart.defaults.animation.easing = 'easeOutQuart';
  Chart.defaults.plugins.legend.display = false;

  Chart.defaults.plugins.tooltip = Object.assign({}, Chart.defaults.plugins.tooltip, {
    backgroundColor: SURFACE,
    titleColor: INK,
    bodyColor: INK_MUTED,
    borderColor: HAIRLINE,
    borderWidth: 1,
    cornerRadius: 10,
    padding: { top: 9, right: 12, bottom: 9, left: 12 },
    titleFont: { family: FONT, size: 12, weight: '700' },
    bodyFont: { family: FONT, size: 12, weight: '500' },
    displayColors: false,
    caretSize: 5
  });

  var barValues = {
    id: 'attsBarValues',
    afterDatasetsDraw: function (chart) {
      if (chart.config.type !== 'bar') { return; }
      if (chart.options.attsHideValues) { return; }

      var isHorizontal = chart.config.options && chart.config.options.indexAxis === 'y';
      var ctx = chart.ctx;
      ctx.save();
      ctx.font = '700 11px ' + FONT;
      ctx.fillStyle = INK_MUTED;
      if (isHorizontal) {
        ctx.textAlign = 'left';
        ctx.textBaseline = 'middle';
      } else {
        ctx.textAlign = 'center';
        ctx.textBaseline = 'bottom';
      }

      chart.data.datasets.forEach(function (dataset, di) {
        var meta = chart.getDatasetMeta(di);
        if (meta.hidden) { return; }
        meta.data.forEach(function (bar, i) {
          var value = dataset.data[i];
          if (value === null || value === undefined || value === 0) { return; }
          if (isHorizontal) {
            ctx.fillText(String(value), bar.x + 6, bar.y);
          } else {
            ctx.fillText(String(value), bar.x, bar.y - 5);
          }
        });
      });
      ctx.restore();
    }
  };

  var donutCentre = {
    id: 'attsDonutCentre',
    afterDraw: function (chart) {
      var cap = chart.options.attsCentre;
      if (!cap || chart.config.type !== 'doughnut') { return; }

      var meta = chart.getDatasetMeta(0);
      if (!meta.data.length) { return; }

      var ctx = chart.ctx;
      var x = meta.data[0].x;
      var y = meta.data[0].y;

      ctx.save();
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillStyle = INK;
      ctx.font = '800 22px ' + FONT;
      ctx.fillText(cap.value, x, y - 7);
      ctx.fillStyle = INK_FAINT;
      ctx.font = '600 10.5px ' + FONT;
      ctx.fillText(String(cap.label).toUpperCase(), x, y + 13);
      ctx.restore();
    }
  };

  Chart.register(barValues, donutCentre);

  function colours(count, given) {
    var source = PALETTE;
    if (typeof given === 'string' && given) { source = [given]; }
    else if (given && given.length) { source = given; }

    var out = [];
    for (var i = 0; i < count; i++) { out.push(source[i % source.length]); }
    return out;
  }

  function drawEmpty(canvas, message) {
    var ctx = canvas.getContext('2d');
    var w = canvas.clientWidth || canvas.width;
    var h = canvas.clientHeight || canvas.height;

    canvas.width = w * (window.devicePixelRatio || 1);
    canvas.height = h * (window.devicePixelRatio || 1);
    ctx.scale(window.devicePixelRatio || 1, window.devicePixelRatio || 1);

    ctx.clearRect(0, 0, w, h);
    ctx.fillStyle = INK_FAINT;
    ctx.font = '500 12.5px ' + FONT;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(message || 'No data to chart yet', w / 2, h / 2);
    return null;
  }

  function hasData(values) {
    return values && values.length && values.some(function (v) { return Number(v) > 0; });
  }

  var ATTS = window.ATTS || (window.ATTS = {});

  ATTS.charts = {
    palette: PALETTE,

    bar: function (canvas, opts) {
      canvas = typeof canvas === 'string' ? document.getElementById(canvas) : canvas;
      if (!canvas) { return null; }
      opts = opts || {};

      var existingChart = Chart.getChart(canvas);
      if (existingChart) {
        existingChart.destroy();
      }

      var hasAnyData = false;
      if (opts.datasets && opts.datasets.length) {
        hasAnyData = opts.datasets.some(function (ds) { return hasData(ds.data); });
      } else {
        hasAnyData = hasData(opts.data);
      }

      if (!hasAnyData) { return drawEmpty(canvas, opts.empty); }

      var horizontal = opts.horizontal === true;
      var isStacked = opts.stacked === true;

      var scalesConfig = horizontal ? {
        x: {
          stacked: isStacked,
          beginAtZero: true,
          grid: { color: SOFT, drawTicks: false, drawBorder: false, borderDash: [3, 3] },
          ticks: {
            color: INK_FAINT,
            font: { size: 10.5 },
            precision: 0
          }
        },
        y: {
          stacked: isStacked,
          grid: { display: false, drawBorder: true, borderColor: HAIRLINE },
          ticks: {
            color: INK_MUTED,
            font: { size: 11, weight: '600' },
            padding: 8,
            autoSkip: false,
            callback: function (value) {
              var text = this.getLabelForValue(value);
              return (typeof text === 'string' && text.length > 20) ? text.slice(0, 18) + '…' : text;
            }
          }
        }
      } : {
        x: {
          stacked: isStacked,
          grid: { display: false, drawBorder: true, borderColor: HAIRLINE },
          ticks: {
            color: INK_FAINT,
            font: { size: 11, weight: '600' },
            maxRotation: opts.maxRotation !== undefined ? opts.maxRotation : 0,
            autoSkip: opts.autoSkip !== undefined ? opts.autoSkip : false,
            callback: function (value) {
              var text = this.getLabelForValue(value);
              return text.length > 14 ? text.slice(0, 13) + '…' : text;
            }
          }
        },
        y: {
          stacked: isStacked,
          beginAtZero: true,
          grid: { color: SOFT, drawTicks: false, drawBorder: false, borderDash: [3, 3] },
          ticks: {
            color: INK_FAINT,
            font: { size: 11 },
            padding: 8,
            precision: 0
          }
        }
      };

      var chartDatasets = opts.datasets || [{
        data: opts.data,
        backgroundColor: colours(opts.data.length, opts.colors),
        hoverBackgroundColor: colours(opts.data.length, opts.colors),
        borderRadius: horizontal
          ? { topLeft: 0, topRight: 6, bottomLeft: 0, bottomRight: 6 }
          : { topLeft: 6, topRight: 6, bottomLeft: 0, bottomRight: 0 },
        borderSkipped: false,
        maxBarThickness: opts.maxBarThickness || (horizontal ? 24 : 38),
        hoverBorderColor: 'transparent'
      }];

      var legendConfig = (opts.legend !== false && (opts.legend === true || isStacked || opts.datasets)) ? {
        display: true,
        position: opts.legendPosition || 'top',
        align: 'end',
        labels: {
          color: INK_MUTED,
          boxWidth: 9,
          boxHeight: 9,
          usePointStyle: true,
          pointStyle: 'circle',
          padding: 12,
          font: { size: 11, weight: '600' }
        }
      } : { display: false };

      return new Chart(canvas, {
        type: 'bar',
        data: {
          labels: opts.labels,
          datasets: chartDatasets
        },
        options: {
          indexAxis: horizontal ? 'y' : 'x',
          responsive: true,
          maintainAspectRatio: false,
          layout: { padding: horizontal ? { right: 36, left: (opts.paddingLeft !== undefined ? opts.paddingLeft : 8), top: 4, bottom: 4 } : { top: 18, left: 4, right: 4, bottom: 4 } },
          attsHideValues: opts.hideValues === true || isStacked,
          plugins: {
            legend: legendConfig,
            tooltip: {
              callbacks: {
                label: function (ctx) {
                  if (typeof opts.tooltipCallback === 'function') {
                    return opts.tooltipCallback(ctx);
                  }
                  var noun = opts.unit || 'records';
                  var prefix = ctx.dataset && ctx.dataset.label ? (ctx.dataset.label + ': ') : '';
                  return prefix + ctx.raw + ' ' + noun;
                }
              }
            }
          },
          scales: scalesConfig
        }
      });
    },

    donut: function (canvas, opts) {
      canvas = typeof canvas === 'string' ? document.getElementById(canvas) : canvas;
      if (!canvas) { return null; }
      opts = opts || {};

      var existingChart = Chart.getChart(canvas);
      if (existingChart) {
        existingChart.destroy();
      }

      if (!hasData(opts.data)) { return drawEmpty(canvas, opts.empty); }

      var total = opts.data.reduce(function (a, b) { return a + Number(b || 0); }, 0);

      return new Chart(canvas, {
        type: 'doughnut',
        data: {
          labels: opts.labels,
          datasets: [{
            data: opts.data,
            backgroundColor: colours(opts.data.length, opts.colors),
            borderColor: SURFACE,
            borderWidth: 3,
            hoverOffset: 6,
            hoverBorderColor: SURFACE
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          cutout: '68%',
          attsCentre: opts.centre === false ? null : {
            value: opts.centreValue !== undefined ? opts.centreValue : total,
            label: opts.centreLabel || 'total'
          },
          plugins: {
            legend: {
              display: opts.legend !== false,
              position: 'right',
              labels: {
                color: INK_MUTED,
                boxWidth: 9,
                boxHeight: 9,
                usePointStyle: true,
                pointStyle: 'circle',
                padding: 13,
                font: { size: 11.5, weight: '600' }
              }
            },
            tooltip: {
              callbacks: {
                label: function (ctx) {
                  var pct = total ? Math.round((ctx.raw / total) * 100) : 0;
                  return ctx.label + ' — ' + ctx.raw + ' (' + pct + '%)';
                }
              }
            }
          }
        }
      });
    }
  };
})(window, document);
