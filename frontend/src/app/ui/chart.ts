import { ChangeDetectionStrategy, Component, ElementRef, OnDestroy, afterNextRender, effect, inject, input } from '@angular/core';
import { BarChart, LineChart, PieChart } from 'echarts/charts';
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components';
import { ECharts, EChartsCoreOption, init, use } from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';

// Only what the product draws is bundled (bars, lines, donuts).
use([BarChart, LineChart, PieChart, GridComponent, LegendComponent, TooltipComponent, CanvasRenderer]);

/** Categorical colours, ordered so neighbours stay distinguishable; the first is the brand colour. */
export const CHART_COLORS = ['#0f5c56', '#c9812a', '#3f6fb5', '#8a5a9e', '#5b8c3a', '#b84a4a', '#6b7f8c'];

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

  private withDefaults(option: EChartsCoreOption): EChartsCoreOption {
    return {
      color: CHART_COLORS,
      textStyle: { fontFamily: getComputedStyle(this.host.nativeElement).fontFamily, fontSize: 12, color: '#4a5763' },
      animationDuration: 250,
      ...option,
    };
  }
}
