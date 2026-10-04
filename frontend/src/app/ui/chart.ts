import { ChangeDetectionStrategy, Component, ElementRef, OnDestroy, afterNextRender, effect, inject, input } from '@angular/core';
import { BarChart, LineChart, PieChart } from 'echarts/charts';
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components';
import { ECharts, EChartsCoreOption, init, use } from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';

// Only what the product draws is bundled (bars, lines, donuts).
use([BarChart, LineChart, PieChart, GridComponent, LegendComponent, TooltipComponent, CanvasRenderer]);

/** Categorical colours, ordered so neighbours stay distinguishable; the first is the brand colour. */
export const CHART_COLORS = ['#0f5c56', '#d0902f', '#4a78b8', '#8a63a6', '#5f9140', '#b84a4a', '#7c8a96'];

/**
 * ECharts host. The option object is built by the screen from API data; this component only owns the
 * rendering, the resize and the accessible description.
 */
@Component({
  selector: 'dw-chart',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: '',
  host: { role: 'img', '[attr.aria-label]': 'label()', '[style.height.px]': 'height()' },
  styles: ':host { display: block; width: 100%; min-width: 0; }',
})
export class ChartComponent implements OnDestroy {
  readonly option = input.required<EChartsCoreOption>();
  readonly height = input(260);
  /** What the chart shows, for people who cannot see it. */
  readonly label = input.required<string>();

  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private chart: ECharts | null = null;
  private observer: ResizeObserver | null = null;

  constructor() {
    afterNextRender(() => {
      this.chart = init(this.host.nativeElement, undefined, { renderer: 'canvas' });
      this.chart.setOption(this.withDefaults(this.option()));
      this.observer = new ResizeObserver(() => this.chart?.resize());
      this.observer.observe(this.host.nativeElement);
    });
    effect(() => {
      const option = this.option();
      this.chart?.setOption(this.withDefaults(option), true);
    });
  }

  ngOnDestroy(): void {
    this.observer?.disconnect();
    this.chart?.dispose();
  }

  /** One restrained look for every chart: hairline grid, small muted labels, dark compact tooltip. */
  private withDefaults(option: EChartsCoreOption): EChartsCoreOption {
    const fontFamily = getComputedStyle(this.host.nativeElement).fontFamily;
    const axis = (value: unknown): unknown =>
      value && typeof value === 'object' && !Array.isArray(value)
        ? {
            axisLine: { lineStyle: { color: '#d0d7dd' } },
            axisLabel: { color: '#6c7985', fontSize: 11, ...(value as { axisLabel?: object }).axisLabel },
            splitLine: { lineStyle: { color: '#eef1f4' } },
            ...value,
          }
        : value;
    const merged = option as Record<string, unknown>;

    return {
      color: CHART_COLORS,
      textStyle: { fontFamily, fontSize: 12, color: '#4b5864' },
      animationDuration: 250,
      ...option,
      tooltip: {
        backgroundColor: '#17212b', borderWidth: 0, padding: [6, 10], textStyle: { color: '#fff', fontSize: 12, fontFamily },
        extraCssText: 'border-radius:6px;box-shadow:0 8px 24px -6px rgb(13 20 27 / 35%);', axisPointer: { lineStyle: { color: '#d0d7dd' }, shadowStyle: { color: 'rgb(23 33 43 / 4%)' } },
        ...(merged['tooltip'] as object),
      },
      ...(merged['legend'] ? { legend: { itemWidth: 10, itemHeight: 10, textStyle: { color: '#4b5864', fontSize: 12 }, ...(merged['legend'] as object) } } : {}),
      ...(merged['xAxis'] ? { xAxis: axis(merged['xAxis']) } : {}),
      ...(merged['yAxis'] ? { yAxis: axis(merged['yAxis']) } : {}),
    };
  }
}
