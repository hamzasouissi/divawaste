import { ChangeDetectionStrategy, Component, ElementRef, OnDestroy, afterNextRender, output, signal, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { TranslocoDirective } from '@jsverse/transloco';
import type { IScannerControls } from '@zxing/browser';
import { ButtonDirective } from 'primeng/button';
import { IconComponent } from '../../ui/icon';

/**
 * QR reader on the device camera (ZXing, cahier §5). The rear camera is requested; when the camera is refused or
 * missing, the code printed under the QR on the label can be typed instead, so a scan is never a dead end.
 * The same code is not emitted twice in a row within two seconds (a label held in front of the camera).
 */
@Component({
  selector: 'dw-qr-scanner',
  imports: [FormsModule, TranslocoDirective, IconComponent, ButtonDirective],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      @if (!manual()) {
        <div class="viewport">
          <video #video muted playsinline [attr.aria-label]="t('mobile.scanHint')"></video>
          <div class="frame" aria-hidden="true"></div>
        </div>
        @if (problem(); as key) {
          <p class="problem" role="alert">{{ t(key) }}</p>
        } @else {
          <p class="hint">{{ t('mobile.scanHint') }}</p>
        }
        <button pButton severity="secondary" outlined type="button" class="big" (click)="manual.set(true)"><dw-icon>keyboard</dw-icon>{{ t('mobile.scanManual') }}</button>
      } @else {
        <label class="manual">
          <span>{{ t('mobile.scanCode') }}</span>
          <input [ngModel]="typed()" (ngModelChange)="typed.set($event)" (keydown.enter)="submitTyped()" autocapitalize="characters" autocomplete="off" spellcheck="false" />
        </label>
        <button pButton type="button" class="big" [disabled]="typed().trim() === ''" (click)="submitTyped()">{{ t('common.confirm') }}</button>
      }
    </ng-container>
  `,
  styles: `
    :host { display: grid; gap: var(--dw-3); }
    .viewport { position: relative; aspect-ratio: 1; max-height: 56dvh; background: #000; border-radius: var(--dw-radius-lg); overflow: hidden; margin-inline: auto; width: 100%; }
    video { width: 100%; height: 100%; object-fit: cover; }
    .frame { position: absolute; inset: 18%; border: 3px solid #7fd6c2; border-radius: 12px; box-shadow: 0 0 0 100vmax rgb(0 0 0 / 35%); }
    .hint, .problem { text-align: center; font-size: 15px; }
    .problem { color: var(--dw-danger); }
    .big { min-height: var(--dw-touch); font-size: 17px; line-height: 22px; border-radius: 12px; gap: 10px; }
    .big dw-icon { font-size: 24px; }
    .manual { display: grid; gap: 6px; font-size: 15px; }
    .manual input { height: var(--dw-touch); padding-inline: 14px; font: 600 18px var(--dw-mono); border: 2px solid var(--dw-line); border-radius: var(--dw-radius-lg); }
    .manual input:focus { outline: none; border-color: var(--dw-primary); }
  `,
})
export class QrScannerComponent implements OnDestroy {
  readonly scanned = output<string>();

  protected readonly manual = signal(false);
  protected readonly typed = signal('');
  protected readonly problem = signal<string | null>(null);

  private readonly video = viewChild<ElementRef<HTMLVideoElement>>('video');
  private controls: IScannerControls | null = null;
  private last = { text: '', at: 0 };

  constructor() {
    afterNextRender(() => void this.start());
  }

  ngOnDestroy(): void {
    this.controls?.stop();
  }

  protected submitTyped(): void {
    const value = this.typed().trim();
    if (value) {
      this.scanned.emit(value);
      this.typed.set('');
    }
  }

  private async start(): Promise<void> {
    const element = this.video()?.nativeElement;
    if (!element) {
      return;
    }
    if (!navigator.mediaDevices?.getUserMedia) {
      this.problem.set('mobile.cameraUnavailable');

      return;
    }
    try {
      // Loaded on demand: the decoder is only needed on the scanning screens.
      const { BrowserQRCodeReader } = await import('@zxing/browser');
      this.controls = await new BrowserQRCodeReader().decodeFromConstraints({ video: { facingMode: { ideal: 'environment' } }, audio: false }, element, (result) => {
        const text = result?.getText();
        const now = Date.now();
        if (text && (text !== this.last.text || now - this.last.at > 2000)) {
          this.last = { text, at: now };
          navigator.vibrate?.(60);
          this.scanned.emit(text);
        }
      });
    } catch (error) {
      this.problem.set((error as DOMException)?.name === 'NotAllowedError' ? 'mobile.cameraDenied' : 'mobile.cameraUnavailable');
    }
  }
}
